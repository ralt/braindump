<?php

namespace App\Tests\Controller;

use App\Entity\Recording;
use App\Entity\User;
use App\Enum\RecordingStatus;
use Doctrine\ORM\EntityManagerInterface;
use App\Tests\DatabaseTestCase;

class AdminControllerTest extends DatabaseTestCase
{
    private function createAdminUser(EntityManagerInterface $em, string $email = 'admin-test@example.com'): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Admin');
        $user->setRoles(['ROLE_ADMIN']);
        $user->setPassword(
            static::getContainer()->get('security.user_password_hasher')->hashPassword($user, 'password')
        );
        $em->persist($user);
        $em->flush();

        return $user;
    }

    public function testAdminDashboardRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin');
        $this->assertResponseRedirects();
    }

    public function testAdminDashboardRedirectsToCrud(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createAdminUser($em, 'admin-dashboard@example.com');

        $client->loginUser($user);
        $client->request('GET', '/admin');
        $this->assertResponseRedirects();
        $client->followRedirect();
        $this->assertResponseIsSuccessful();
    }

    public function testAdminRecordingListRendersForAdmin(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createAdminUser($em, 'admin-recordings@example.com');

        $recording = new Recording();
        $recording->setOwner($user);
        $recording->setTitle('Test Recording');
        $recording->setMimeType('audio/webm');
        $recording->setFileSizeBytes(0);
        $recording->setStatus(RecordingStatus::Completed);
        $recording->setTranscription('Hello world');

        $filename = $recording->getId() . '.webm';
        $recording->setAudioFilePath($filename);

        $audioDir = static::getContainer()->getParameter('app.audio_storage_path');
        if (!is_dir($audioDir)) {
            mkdir($audioDir, 0777, true);
        }
        touch($audioDir . '/' . $filename);

        $em->persist($recording);
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/admin/recording');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Test Recording');
    }

    public function testAdminDeniedForNonAdmin(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('regular-admin-test@example.com');
        $user->setDisplayName('Regular');
        $user->setPassword(
            static::getContainer()->get('security.user_password_hasher')->hashPassword($user, 'password')
        );
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/admin');
        $this->assertResponseStatusCodeSame(403);
    }
}
