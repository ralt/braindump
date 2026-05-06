<?php

namespace App\MessageHandler;

use App\Entity\Recording;
use App\Enum\RecordingStatus;
use App\Message\TranscribeRecordingMessage;
use App\Search\SearchProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Message\Content\Audio;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class TranscribeRecordingHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private PlatformInterface $platform,
        private HubInterface $hub,
        private SearchProviderInterface $searchProvider,
        private string $audioStoragePath,
    ) {}

    public function __invoke(TranscribeRecordingMessage $message): void
    {
        $recording = $this->em->find(Recording::class, $message->recordingId);
        if ($recording === null) {
            return;
        }

        $recording->setStatus(RecordingStatus::Transcribing);
        $this->em->flush();
        $this->publishStatus($recording);

        try {
            $audioPath = $this->audioStoragePath . '/' . $recording->getAudioFilePath();
            $audio = Audio::fromFile($audioPath);

            $result = $this->platform->invoke('whisper-1', $audio);
            $text = $result->asText();

            $recording->setTranscription($text);
            $recording->setStatus(RecordingStatus::Completed);

            if ($recording->getTitle() === '' && $text !== '') {
                $generated = $this->generateTitle($text);
                if ($generated !== '') {
                    $recording->setTitle($generated);
                }
            }
        } catch (\Throwable $e) {
            $recording->setStatus(RecordingStatus::Failed);
            $recording->setErrorMessage($e->getMessage());
        }

        $this->em->flush();
        $this->searchProvider->index($recording);
        $this->publishStatus($recording);
    }

    private function publishStatus(Recording $recording): void
    {
        try {
            $this->hub->publish(new Update(
                'recording/' . $recording->getId(),
                json_encode([
                    'status' => $recording->getStatus()->value,
                    'transcription' => $recording->getTranscription(),
                    'title' => $recording->getTitle(),
                ]),
            ));
        } catch (\Throwable) {
            // Mercure may not be available in all environments
        }
    }

    private function generateTitle(string $transcript): string
    {
        try {
            // Cap input to keep token usage low even on long recordings.
            $excerpt = mb_substr($transcript, 0, 4000);

            $messages = new MessageBag(
                new SystemMessage('Generate a short, descriptive title (5 to 10 words) for the following speech transcript. Output only the title — no quotes, no trailing punctuation, no prefix like "Title:".'),
                new UserMessage(new Text($excerpt)),
            );

            $title = trim($this->platform->invoke('gpt-4.1-mini', $messages)->asText());
            $title = trim($title, " \t\n\r\0\x0B\"'.");

            return mb_substr($title, 0, 100);
        } catch (\Throwable) {
            return '';
        }
    }
}
