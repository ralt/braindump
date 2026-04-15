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
        $userId = $user->getId()->toRfc4122();

        // Build a prefix-matching tsquery: "hello worl" → "hello & worl:*"
        // This handles the common live-search case where the user is still typing
        $tsquery = $this->buildPrefixTsquery($query);

        if ($tsquery !== '') {
            $sql = '
                SELECT r.id
                FROM recording r
                LEFT JOIN recording_share s ON s.recording_id = r.id
                WHERE r.search_vector @@ to_tsquery(\'english\', :query)
                  AND (r.owner_id = :userId OR s.shared_with_id = :userId)
                ORDER BY ts_rank(r.search_vector, to_tsquery(\'english\', :query)) DESC
            ';

            $rows = $conn->fetchAllAssociative($sql, [
                'query' => $tsquery,
                'userId' => $userId,
            ]);

            if (!empty($rows)) {
                return $this->hydrateResults($rows);
            }
        }

        // Fallback: ILIKE for typos and partial matches within words
        $sql = '
            SELECT r.id
            FROM recording r
            LEFT JOIN recording_share s ON s.recording_id = r.id
            WHERE (r.title ILIKE :pattern OR r.transcription ILIKE :pattern)
              AND (r.owner_id = :userId OR s.shared_with_id = :userId)
            ORDER BY r.created_at DESC
        ';

        $rows = $conn->fetchAllAssociative($sql, [
            'pattern' => '%' . str_replace(['%', '_'], ['\%', '\_'], $query) . '%',
            'userId' => $userId,
        ]);

        return $this->hydrateResults($rows);
    }

    /**
     * Convert a search string to a prefix-matching tsquery.
     * "hello world" → "hello & world:*"
     * "braindum"    → "braindum:*"
     */
    private function buildPrefixTsquery(string $query): string
    {
        $words = preg_split('/\s+/', trim($query), -1, \PREG_SPLIT_NO_EMPTY);
        if (empty($words)) {
            return '';
        }

        $parts = [];
        foreach ($words as $i => $word) {
            // Strip non-alphanumeric to avoid tsquery syntax errors
            $clean = preg_replace('/[^\w]/', '', $word);
            if ($clean === '') {
                continue;
            }
            // Add prefix operator to last word (user is still typing)
            $parts[] = $i === \count($words) - 1 ? $clean . ':*' : $clean;
        }

        return implode(' & ', $parts);
    }

    /** @return Recording[] */
    private function hydrateResults(array $rows): array
    {
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
