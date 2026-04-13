<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class UserSettingsControllerTest extends WebTestCase
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

    public function testSettingsRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/settings');
        $this->assertResponseRedirects('/login');
    }
}
