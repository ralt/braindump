<?php

namespace App\Search;

use App\Entity\Recording;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

class PostgresSearchProvider implements SearchProviderInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    public function search(string $query, User $user): array
    {
        $conn = $this->em->getConnection();

        $sql = '
            SELECT r.id
            FROM recording r
            LEFT JOIN recording_share s ON s.recording_id = r.id
            WHERE r.search_vector @@ plainto_tsquery(\'english\', :query)
              AND (r.owner_id = :userId OR s.shared_with_id = :userId)
            ORDER BY ts_rank(r.search_vector, plainto_tsquery(\'english\', :query)) DESC
        ';

        $rows = $conn->fetchAllAssociative($sql, [
            'query' => $query,
            'userId' => $user->getId()->toRfc4122(),
        ]);

        if (empty($rows)) {
            return [];
        }

        $ids = array_column($rows, 'id');

        return $this->em->createQueryBuilder()
            ->select('r')
            ->from(Recording::class, 'r')
            ->where('r.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    public function index(Recording $recording): void
    {
        // No-op: PostgreSQL trigger handles indexing automatically
    }

    public function remove(Recording $recording): void
    {
        // No-op: cascade delete handles removal
    }
}
