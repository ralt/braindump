<?php

namespace App\Repository;

use App\Entity\Recording;
use App\Entity\RecordingShare;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecordingShare>
 */
class RecordingShareRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecordingShare::class);
    }

    public function findByRecordingAndUser(Recording $recording, User $user): ?RecordingShare
    {
        return $this->findOneBy([
            'recording' => $recording,
            'sharedWith' => $user,
        ]);
    }
}
