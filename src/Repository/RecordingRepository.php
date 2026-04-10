<?php

namespace App\Repository;

use App\Entity\Recording;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Recording>
 */
class RecordingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Recording::class);
    }

    /**
     * @return Recording[]
     */
    public function findAccessibleByUser(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.shares', 's')
            ->where('r.owner = :user')
            ->orWhere('s.sharedWith = :user')
            ->setParameter('user', $user)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
