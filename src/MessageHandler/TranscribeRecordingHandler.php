<?php

namespace App\MessageHandler;

use App\Entity\Recording;
use App\Enum\RecordingStatus;
use App\Message\TranscribeRecordingMessage;
use App\Search\SearchProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Message\Content\Audio;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Psr\Log\LoggerInterface;
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
        private LoggerInterface $logger,
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

            $this->logger->error('[DIAG] Transcription worker reading audio file', [
                'path' => $audioPath,
                'exists' => file_exists($audioPath),
                'readable' => is_readable($audioPath),
                'dir_exists' => is_dir($this->audioStoragePath),
                'dir_contents' => is_dir($this->audioStoragePath) ? scandir($this->audioStoragePath) : 'DIR_NOT_FOUND',
                'audioStoragePath' => $this->audioStoragePath,
            ]);

            $audio = Audio::fromFile($audioPath);

            $result = $this->platform->invoke('whisper-1', $audio);
            $text = $result->asText();

            $recording->setTranscription($text);
            $recording->setStatus(RecordingStatus::Completed);
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
                ]),
            ));
        } catch (\Throwable) {
            // Mercure may not be available in all environments
        }
    }
}
