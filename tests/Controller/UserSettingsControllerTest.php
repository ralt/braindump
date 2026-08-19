<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use App\Tests\DatabaseTestCase;

class UserSettingsControllerTest extends DatabaseTestCase
{
    public function testSettingsPageRendersForAuthenticatedUser(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('settings@example.com');
        $user->setDisplayName('Settings User');
        $user->setPassword(
            static::getContainer()->get('security.user_password_hasher')->hashPassword($user, 'password')
        );
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $client->request('GET', '/settings');
        $this->assertResponseIsSuccessful();
    }

    public function testUserCanRenameDisplayName(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('rename@example.com');
        $user->setDisplayName('Old Name');
        $user->setPassword(
            static::getContainer()->get('security.user_password_hasher')->hashPassword($user, 'password')
        );
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/settings');

        $client->request('POST', '/settings', [
            '_token' => $crawler->filter('#display_name')->closest('form')->filter('input[name="_token"]')->attr('value'),
            'display_name' => '  New Name  ',
        ]);
        $this->assertResponseRedirects('/settings');

        $em->clear();
        $updated = $em->getRepository(User::class)->find($user->getId());
        $this->assertSame('New Name', $updated->getDisplayName());
    }

    public function testEmptyDisplayNameIsRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('rename-empty@example.com');
        $user->setDisplayName('Keep Me');
        $user->setPassword(
            static::getContainer()->get('security.user_password_hasher')->hashPassword($user, 'password')
        );
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/settings');

        $client->request('POST', '/settings', [
            '_token' => $crawler->filter('#display_name')->closest('form')->filter('input[name="_token"]')->attr('value'),
            'display_name' => '   ',
        ]);
        $this->assertResponseRedirects('/settings');

        $em->clear();
        $updated = $em->getRepository(User::class)->find($user->getId());
        $this->assertSame('Keep Me', $updated->getDisplayName());
    }

    public function testRenamingDoesNotClearApiKey(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('rename-key@example.com');
        $user->setDisplayName('Key Owner');
        $user->setEncryptedAiApiKey('encrypted-key');
        $user->setPassword(
            static::getContainer()->get('security.user_password_hasher')->hashPassword($user, 'password')
        );
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/settings');

        $client->request('POST', '/settings', [
            '_token' => $crawler->filter('#display_name')->closest('form')->filter('input[name="_token"]')->attr('value'),
            'display_name' => 'Renamed',
        ]);

        $em->clear();
        $updated = $em->getRepository(User::class)->find($user->getId());
        $this->assertSame('Renamed', $updated->getDisplayName());
        $this->assertSame('encrypted-key', $updated->getEncryptedAiApiKey());
    }

    public function testSettingsRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/settings');
        $this->assertResponseRedirects('/login');
    }
}
