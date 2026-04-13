<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use App\Tests\DatabaseTestCase;

class SecurityControllerTest extends DatabaseTestCase
{
    public function testLoginPageRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    public function testLoginWithValidCredentials(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('login@example.com');
        $user->setDisplayName('Login User');
        $user->setPassword(
            static::getContainer()->get('security.user_password_hasher')->hashPassword($user, 'password')
        );
        $em->persist($user);
        $em->flush();

        $client->request('GET', '/login');
        $client->submitForm('Sign in', [
            '_username' => 'login@example.com',
            '_password' => 'password',
        ]);

        $this->assertResponseRedirects('/');
    }

    public function testLoginWithInvalidCredentials(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');
        $client->submitForm('Sign in', [
            '_username' => 'nobody@example.com',
            '_password' => 'wrong',
        ]);

        $this->assertResponseRedirects('/login');
    }
}
