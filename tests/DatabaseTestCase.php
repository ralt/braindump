<?php

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class DatabaseTestCase extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $connection = $em->getConnection();

        $connection->executeStatement('DELETE FROM claude_session');
        $connection->executeStatement('DELETE FROM recording_share');
        $connection->executeStatement('DELETE FROM recording');
        $connection->executeStatement('DELETE FROM "user"');

        static::ensureKernelShutdown();
    }
}
