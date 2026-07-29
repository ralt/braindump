<?php

namespace App\Tests\Controller;

use App\Entity\Recording;
use App\Entity\User;
use App\Enum\RecordingStatus;
use App\Tests\DatabaseTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

class RecordingControllerTest extends DatabaseTestCase
{
    private function createUser(EntityManagerInterface $em, string $email = 'test@example.com'): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Test User');
        $user->setPassword(
            static::getContainer()->get('security.user_password_hasher')->hashPassword($user, 'password')
        );
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function createRecording(EntityManagerInterface $em, User $owner, RecordingStatus $status = RecordingStatus::Completed): Recording
    {
        $recording = new Recording();
        $recording->setOwner($owner);
        $recording->setTitle('Test Recording');
        $recording->setMimeType('audio/webm');
        $recording->setFileSizeBytes(0);
        $recording->setStatus($status);
        $recording->setTranscription('Test transcription text');

        $filename = $recording->getId() . '.webm';
        $recording->setAudioFilePath($filename);

        $audioDir = static::getContainer()->getParameter('app.audio_storage_path');
        if (!is_dir($audioDir)) {
            mkdir($audioDir, 0777, true);
        }
        touch($audioDir . '/' . $filename);

        $em->persist($recording);
        $em->flush();

        return $recording;
    }

    public function testIndexRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        $this->assertResponseRedirects('/login');
    }

    public function testIndexReturnsOkForAuthenticatedUser(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em);

        $client->loginUser($user);
        $client->request('GET', '/');
        $this->assertResponseIsSuccessful();
    }

    public function testUploadCreatesRecording(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em);
        $client->loginUser($user);

        $audioFile = new UploadedFile(
            tempnam(sys_get_temp_dir(), 'test_audio'),
            'test.webm',
            'audio/webm',
            null,
            true
        );

        $client->request('POST', '/api/recordings', [
            'title' => 'My Test Recording',
            'duration' => '754',
        ], [
            'audio' => $audioFile,
        ]);

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $data);
        $this->assertEquals('pending', $data['status']);

        // Only the browser ever knows the length, so losing it on upload loses it for good.
        $freshEm = static::getContainer()->get(EntityManagerInterface::class);
        $stored = $freshEm->find(Recording::class, Uuid::fromString($data['id']));
        $this->assertSame(754, $stored->getDurationSeconds());
        $this->assertSame('12m 34s', $stored->getDurationLabel());
    }

    public function testUploadIgnoresImplausibleDuration(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em);
        $client->loginUser($user);

        $audioFile = new UploadedFile(
            tempnam(sys_get_temp_dir(), 'test_audio'),
            'test.webm',
            'audio/webm',
            null,
            true
        );

        $client->request('POST', '/api/recordings', [
            'duration' => '999999999',
        ], [
            'audio' => $audioFile,
        ]);

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $freshEm = static::getContainer()->get(EntityManagerInterface::class);
        $stored = $freshEm->find(Recording::class, Uuid::fromString($data['id']));
        $this->assertNull($stored->getDurationSeconds());
        $this->assertSame('', $stored->getDurationLabel());
    }

    public function testStatusEndpoint(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em);
        $recording = $this->createRecording($em, $user);

        $client->loginUser($user);
        $client->request('GET', '/api/recordings/' . $recording->getId() . '/status');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('completed', $data['status']);
        $this->assertEquals('Test transcription text', $data['transcription']);
    }

    /**
     * The delete form must stay behind a confirmation. It sits inside a <dialog> that only
     * scripting can open, so the guard is gone the moment the form escapes that element.
     */
    public function testDeleteIsConfirmedByADialog(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em);
        $recording = $this->createRecording($em, $user);

        $client->loginUser($user);
        $crawler = $client->request('GET', '/recordings/' . $recording->getId());

        $this->assertResponseIsSuccessful();
        $insideDialog = $crawler->filter('dialog form[action*="delete"]')->count();
        $this->assertSame(1, $insideDialog);
        $this->assertSame($insideDialog, $crawler->filter('form[action*="delete"]')->count());
    }

    /**
     * Anything that only makes sense while the audio exists sits outside the region the status
     * controller re-renders, so it must be tagged for the controller to hide when a
     * transcription finishes in an open tab. Untagged, it lingers as a dead link or a size for
     * a file that's already deleted.
     */
    public function testAudioDependentFieldsAreTaggedForLiveRemoval(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em);
        $recording = $this->createRecording($em, $user, RecordingStatus::Pending);

        $client->loginUser($user);
        $crawler = $client->request('GET', '/recordings/' . $recording->getId());

        $this->assertResponseIsSuccessful();
        $tagged = $crawler->filter('[data-recording-status-target="audioOnly"]')
            ->each(fn ($node) => $node->text());
        $this->assertStringContainsString('MB', implode(' ', $tagged));
        $this->assertCount(1, $crawler->filter('a[data-recording-status-target="audioOnly"][href*="/audio"]'));
    }

    public function testRetryOnFailedRecording(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em);
        $recording = $this->createRecording($em, $user, RecordingStatus::Failed);
        $recordingId = $recording->getId();
        $recording->setErrorMessage('API error');
        $em->flush();

        $client->loginUser($user);

        // Load the show page to get the CSRF token from the retry form
        $crawler = $client->request('GET', '/recordings/' . $recordingId);
        $csrfToken = $crawler->filter('form[action*="retry"] input[name="_token"]')->first()->attr('value');
        $client->request('POST', '/api/recordings/' . $recordingId . '/retry', [
            '_token' => $csrfToken,
        ]);

        $this->assertResponseRedirects();
        // Re-fetch entity from fresh EM since the request resets the container
        $freshEm = static::getContainer()->get(EntityManagerInterface::class);
        $recording = $freshEm->find(Recording::class, $recordingId);
        $this->assertEquals(RecordingStatus::Pending, $recording->getStatus());
    }

    public function testRetryRejectsNonFailedRecording(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em);
        // Create as Failed so the show page renders the retry form with CSRF token
        $recording = $this->createRecording($em, $user, RecordingStatus::Failed);
        $recordingId = $recording->getId();
        $recording->setErrorMessage('Test error');
        $em->flush();

        $client->loginUser($user);

        // Get CSRF token from the retry form on the show page
        $crawler = $client->request('GET', '/recordings/' . $recordingId);
        $csrfToken = $crawler->filter('form[action*="retry"] input[name="_token"]')->first()->attr('value');

        // Now change status to Completed before posting retry
        $freshEm = static::getContainer()->get(EntityManagerInterface::class);
        $recording = $freshEm->find(Recording::class, $recordingId);
        $recording->setStatus(RecordingStatus::Completed);
        $freshEm->flush();

        $client->request('POST', '/api/recordings/' . $recordingId . '/retry', [
            '_token' => $csrfToken,
        ]);

        $this->assertResponseRedirects();
        $freshEm2 = static::getContainer()->get(EntityManagerInterface::class);
        $recording = $freshEm2->find(Recording::class, $recordingId);
        $this->assertEquals(RecordingStatus::Completed, $recording->getStatus());
    }

    public function testAccessControlPreventsOtherUsersAccess(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->createUser($em, 'owner@example.com');
        $other = $this->createUser($em, 'other@example.com');
        $recording = $this->createRecording($em, $owner);

        $client->loginUser($other);
        $client->request('GET', '/recordings/' . $recording->getId());
        $this->assertResponseStatusCodeSame(403);
    }
}
