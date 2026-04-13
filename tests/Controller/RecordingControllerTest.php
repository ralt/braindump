<?php

namespace App\Tests\Controller;

use App\Entity\Recording;
use App\Entity\User;
use App\Enum\RecordingStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class RecordingControllerTest extends WebTestCase
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
        $recording->setAudioFilePath('test.webm');
        $recording->setMimeType('audio/webm');
        $recording->setFileSizeBytes(1024);
        $recording->setStatus($status);
        $recording->setTranscription('Test transcription text');
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
        ], [
            'audio' => $audioFile,
        ]);

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $data);
        $this->assertEquals('pending', $data['status']);
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

    public function testRetryOnFailedRecording(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em);
        $recording = $this->createRecording($em, $user, RecordingStatus::Failed);
        $recording->setErrorMessage('API error');
        $em->flush();

        $client->loginUser($user);

        $csrfToken = static::getContainer()->get('security.csrf.token_manager')->getToken('retry')->getValue();
        $client->request('POST', '/api/recordings/' . $recording->getId() . '/retry', [
            '_token' => $csrfToken,
        ]);

        $this->assertResponseRedirects();
        $em->refresh($recording);
        $this->assertEquals(RecordingStatus::Pending, $recording->getStatus());
    }

    public function testRetryRejectsNonFailedRecording(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em);
        $recording = $this->createRecording($em, $user, RecordingStatus::Completed);

        $client->loginUser($user);

        $csrfToken = static::getContainer()->get('security.csrf.token_manager')->getToken('retry')->getValue();
        $client->request('POST', '/api/recordings/' . $recording->getId() . '/retry', [
            '_token' => $csrfToken,
        ]);

        $this->assertResponseRedirects();
        $em->refresh($recording);
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
