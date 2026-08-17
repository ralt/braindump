<?php

namespace App\Repository;

use App\Entity\Recording;
use App\Entity\User;
use App\Pagination\Pager;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

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

    /**
     * Seconds of audio the user has uploaded since $since, for the daily quota.
     *
     * Duration is client-reported and therefore exactly what someone bypassing the quota would
     * understate, so each recording is charged the greater of what the browser claimed and the
     * shortest length its byte count could possibly represent. That floor is derived from a
     * bitrate no speech encoder realistically exceeds, so it never over-charges an honest
     * upload — it only stops a recording from being declared shorter than physics allows.
     *
     * Recordings predating duration capture have no reported value and are charged the floor
     * alone, which is the honest answer: their audio is long gone and nothing can measure it.
     */
    public function recordedSecondsSince(User $owner, \DateTimeImmutable $since, int $maxBytesPerSecond): int
    {
        $total = $this->createQueryBuilder('r')
            ->select(
                'SUM(CASE WHEN COALESCE(r.durationSeconds, 0) > (r.fileSizeBytes / :maxBytesPerSecond)'
                . ' THEN COALESCE(r.durationSeconds, 0)'
                . ' ELSE (r.fileSizeBytes / :maxBytesPerSecond) END)'
            )
            ->andWhere('r.owner = :owner')
            ->andWhere('r.createdAt >= :since')
            // The id with an explicit type, not the entity: a Uuid identifier bound as an
            // entity parameter is sent as its string form and silently matches none of the
            // binary column's rows — a broken query that looks like an empty result.
            ->setParameter('owner', $owner->getId(), UuidType::NAME)
            ->setParameter('since', $since)
            ->setParameter('maxBytesPerSecond', $maxBytesPerSecond)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $total;
    }
}
