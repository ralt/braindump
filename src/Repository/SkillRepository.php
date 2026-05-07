<?php

namespace App\Repository;

use App\Entity\Skill;
use App\Entity\User;
use App\Pagination\Pager;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Skill>
 */
class SkillRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Skill::class);
    }

    /** @return Skill[] */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['name' => 'ASC']);
    }

    /**
     * @return Pager<Skill>
     */
    public function paginatedForUser(User $user, int $page = 1, int $perPage = 20): Pager
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $items = $this->findBy(
            ['user' => $user],
            ['name' => 'ASC'],
            $perPage,
            ($page - 1) * $perPage,
        );
        $total = $this->count(['user' => $user]);

        return new Pager($items, $total, $page, $perPage);
    }

    public function findOneByUserAndName(User $user, string $name): ?Skill
    {
        return $this->findOneBy(['user' => $user, 'name' => $name]);
    }
}
