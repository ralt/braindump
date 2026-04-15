<?php

namespace App\Search;

use App\Entity\Recording;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;

class SqliteSearchProvider implements SearchProviderInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    public function ensureFtsTable(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'CREATE VIRTUAL TABLE IF NOT EXISTS recording_fts USING fts5(recording_id, title, transcription)'
        );
    }

    public function search(string $query, User $user): array
    {
        $this->ensureFtsTable();
        $conn = $this->em->getConnection();
        $userId = $user->getId()->toBinary();

        // FTS5 prefix search: "braindum" → "braindum*"
        $ftsQuery = $this->buildFts5Query($query);

        if ($ftsQuery !== '') {
            $rows = $conn->fetchAllAssociative(
                'SELECT f.recording_id FROM recording_fts f
                 JOIN recording r ON r.id = f.recording_id
                 LEFT JOIN recording_share s ON s.recording_id = r.id
                 WHERE recording_fts MATCH :query
                   AND (r.owner_id = :userId OR s.shared_with_id = :userId)
                 ORDER BY rank',
                [
                    'query' => $ftsQuery,
                    'userId' => $userId,
                ]
            );

            if (!empty($rows)) {
                return $this->hydrateResults(array_column($rows, 'recording_id'));
            }
        }

        // Fallback: LIKE for typos and partial matches
        $rows = $conn->fetchAllAssociative(
            'SELECT r.id FROM recording r
             LEFT JOIN recording_share s ON s.recording_id = r.id
             WHERE (r.title LIKE :pattern OR r.transcription LIKE :pattern)
               AND (r.owner_id = :userId OR s.shared_with_id = :userId)
             ORDER BY r.created_at DESC',
            [
                'pattern' => '%' . str_replace(['%', '_'], ['\%', '\_'], $query) . '%',
                'userId' => $userId,
            ]
        );

        return $this->hydrateResults(array_column($rows, 'id'));
    }

    /**
     * Build an FTS5 prefix query: "hello world" → "hello world*"
     */
    private function buildFts5Query(string $query): string
    {
        $trimmed = trim($query);
        if ($trimmed === '') {
            return '';
        }

        // Append * to the last word for prefix matching
        return $trimmed . '*';
    }

    /** @return \App\Entity\Recording[] */
    private function hydrateResults(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return $this->em->createQueryBuilder()
            ->select('r')
            ->from(\App\Entity\Recording::class, 'r')
            ->where('r.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function index(Recording $recording): void
    {
        $this->ensureFtsTable();
        $conn = $this->em->getConnection();
        $recordingId = $recording->getId()->toBinary();

        // Delete existing entry then insert fresh
        $conn->executeStatement(
            'DELETE FROM recording_fts WHERE recording_id = :id',
            ['id' => $recordingId]
        );
        $conn->executeStatement(
            'INSERT INTO recording_fts (recording_id, title, transcription) VALUES (:id, :title, :transcription)',
            [
                'id' => $recordingId,
                'title' => $recording->getTitle(),
                'transcription' => $recording->getTranscription() ?? '',
            ]
        );
    }

    public function remove(Recording $recording): void
    {
        $this->ensureFtsTable();
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'DELETE FROM recording_fts WHERE recording_id = :id',
            ['id' => $recording->getId()->toBinary()]
        );
    }
}
