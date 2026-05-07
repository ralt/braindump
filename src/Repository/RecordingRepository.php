<?php

namespace App\Repository;

use App\Entity\Recording;
use App\Entity\User;
use App\Pagination\Pager;
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
        return $this->findBy(['owner' => $user], ['createdAt' => 'DESC']);
    }

    /**
     * @return Pager<Recording>
     */
    public function paginatedForUser(User $user, int $page = 1, int $perPage = 20): Pager
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $items = $this->findBy(
            ['owner' => $user],
            ['createdAt' => 'DESC'],
            $perPage,
            ($page - 1) * $perPage,
        );
        $total = $this->count(['owner' => $user]);

        return new Pager($items, $total, $page, $perPage);
    }
}
