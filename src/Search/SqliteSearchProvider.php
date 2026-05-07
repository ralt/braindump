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

        $rows = $conn->fetchAllAssociative(
            'SELECT f.recording_id FROM recording_fts f
             JOIN recording r ON r.id = f.recording_id
             WHERE recording_fts MATCH :query
               AND r.owner_id = :userId
             ORDER BY rank',
            [
                'query' => $query,
                'userId' => $user->getId()->toBinary(),
            ]
        );

        if (empty($rows)) {
            return [];
        }

        $ids = array_column($rows, 'recording_id');

        return $this->em->createQueryBuilder()
            ->select('r')
            ->from(Recording::class, 'r')
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
