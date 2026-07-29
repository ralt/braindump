<?php

namespace App\Tests\MessageHandler;

use App\Entity\Recording;
use App\Entity\User;
use App\Enum\RecordingStatus;
use App\Message\TranscribeRecordingMessage;
use App\MessageHandler\TranscribeRecordingHandler;
use App\Search\SearchProviderInterface;
use App\Tests\DatabaseTestCase;
use App\Transcription\TranscriberInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Mercure\HubInterface;

/**
 * Deleting the audio is irreversible, so these pin down exactly which outcomes are allowed
 * to do it: a real transcript, and nothing else.
 */
class TranscribeRecordingHandlerTest extends DatabaseTestCase
{
    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = sys_get_temp_dir() . '/braindump-audio-test-' . uniqid('', true);
        mkdir($this->storagePath, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storagePath . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->storagePath);

        parent::tearDown();
    }

    public function testAudioIsDeletedOnceTranscribed(): void
    {
        $recording = $this->handle('the quick brown fox');

        self::assertSame(RecordingStatus::Completed, $recording->getStatus());
        self::assertSame('the quick brown fox', $recording->getTranscription());
        self::assertFileDoesNotExist($this->audioPathFor($recording));
    }

    public function testAudioIsKeptWhenTranscriptionFails(): void
    {
        $recording = $this->handle(new \RuntimeException('whisper exploded'));

        self::assertSame(RecordingStatus::Failed, $recording->getStatus());
        self::assertFileExists($this->audioPathFor($recording));
    }

    /**
     * An empty transcript is a success status hiding a failure — ten minutes of talking
     * turned into nothing — so the audio has to survive for the retry to mean anything.
     */
    public function testAudioIsKeptWhenTranscriptIsEmpty(): void
    {
        $recording = $this->handle('   ');

        self::assertFileExists($this->audioPathFor($recording));
    }

    private function handle(string|\Throwable $transcriberResult): Recording
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $recording = $this->createRecording($em);

        $transcriber = $this->createStub(TranscriberInterface::class);
        $expectation = $transcriber->method('transcribe');
        $transcriberResult instanceof \Throwable
            ? $expectation->willThrowException($transcriberResult)
            : $expectation->willReturn($transcriberResult);

        $platform = $this->createStub(PlatformInterface::class);
        // No LLM in tests — the handler falls back to a heuristic title.
        $platform->method('invoke')->willThrowException(new \RuntimeException('no platform'));

        $handler = new TranscribeRecordingHandler(
            $em,
            $transcriber,
            $platform,
            $this->createStub(HubInterface::class),
            $this->createStub(SearchProviderInterface::class),
            new NullLogger(),
            $this->storagePath,
        );

        $handler(new TranscribeRecordingMessage($recording->getId()));

        $em->refresh($recording);

        return $recording;
    }

    private function createRecording(EntityManagerInterface $em): Recording
    {
        $user = new User();
        $user->setEmail('handler@example.com');
        $user->setDisplayName('Handler Test');
        $user->setPassword('irrelevant');
        $em->persist($user);

        $recording = new Recording();
        $recording->setOwner($user);
        $recording->setTitle('');
        $recording->setMimeType('audio/webm');
        $recording->setFileSizeBytes(1234);
        $recording->setStatus(RecordingStatus::Pending);
        $recording->setAudioFilePath($recording->getId() . '.webm');
        $em->persist($recording);
        $em->flush();

        file_put_contents($this->audioPathFor($recording), 'not really audio');

        return $recording;
    }

    private function audioPathFor(Recording $recording): string
    {
        return $this->storagePath . '/' . $recording->getAudioFilePath();
    }
}
