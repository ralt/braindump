<?php

namespace App\Repository;

use App\Entity\AiSession;
use App\Entity\Recording;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AiSession>
 */
class AiSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiSession::class);
    }

    public function findOneByRecordingForUser(Recording $recording, User $user): ?AiSession
    {
        return $this->findOneBy(['recording' => $recording, 'user' => $user]);
    }
}
