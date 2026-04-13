<?php

namespace App\Tests\Controller;

use App\Entity\Recording;
use App\Entity\User;
use App\Enum\RecordingStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SharingControllerTest extends WebTestCase
{
    private function createUser(EntityManagerInterface $em, string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('User ' . $email);
        $user->setPassword(
            static::getContainer()->get('security.user_password_hasher')->hashPassword($user, 'password')
        );
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function createRecording(EntityManagerInterface $em, User $owner): Recording
    {
        $recording = new Recording();
        $recording->setOwner($owner);
        $recording->setTitle('Test Recording');
        $recording->setAudioFilePath('test.webm');
        $recording->setMimeType('audio/webm');
        $recording->setFileSizeBytes(1024);
        $recording->setStatus(RecordingStatus::Completed);
        $em->persist($recording);
        $em->flush();

        return $recording;
    }

    public function testShareRecording(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->createUser($em, 'owner@example.com');
        $target = $this->createUser($em, 'target@example.com');
        $recording = $this->createRecording($em, $owner);

        $client->loginUser($owner);
        $client->request('POST', '/api/recordings/' . $recording->getId() . '/share', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'target@example.com',
            'permission' => 'view',
        ]));

        $this->assertResponseStatusCodeSame(201);
    }

    public function testShareRequiresOwnership(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->createUser($em, 'owner@example.com');
        $other = $this->createUser($em, 'other@example.com');
        $recording = $this->createRecording($em, $owner);

        $client->loginUser($other);
        $client->request('POST', '/api/recordings/' . $recording->getId() . '/share', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'other@example.com',
            'permission' => 'view',
        ]));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testListShares(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->createUser($em, 'owner@example.com');
        $recording = $this->createRecording($em, $owner);

        $client->loginUser($owner);
        $client->request('GET', '/api/recordings/' . $recording->getId() . '/shares');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }
}
