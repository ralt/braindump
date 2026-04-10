<?php

namespace App\Search;

use App\Entity\Recording;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Elastic\Elasticsearch\Client;

class OpenSearchProvider implements SearchProviderInterface
{
    public function __construct(
        private Client $client,
        private EntityManagerInterface $em,
        private string $indexName,
    ) {}

    public function search(string $query, User $user): array
    {
        $params = [
            'index' => $this->indexName,
            'body' => [
                'query' => [
                    'bool' => [
                        'must' => [
                            'multi_match' => [
                                'query' => $query,
                                'fields' => ['title^2', 'transcription'],
                            ],
                        ],
                        'filter' => [
                            'bool' => [
                                'should' => [
                                    ['term' => ['owner_id' => $user->getId()->toRfc4122()]],
                                    ['term' => ['shared_with_ids' => $user->getId()->toRfc4122()]],
                                ],
                                'minimum_should_match' => 1,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->client->search($params);
        $hits = $response['hits']['hits'] ?? [];

        if (empty($hits)) {
            return [];
        }

        $ids = array_map(fn ($hit) => $hit['_id'], $hits);

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
        $sharedWithIds = [];
        foreach ($recording->getShares() as $share) {
            $sharedWithIds[] = $share->getSharedWith()->getId()->toRfc4122();
        }

        $this->client->index([
            'index' => $this->indexName,
            'id' => $recording->getId()->toRfc4122(),
            'body' => [
                'title' => $recording->getTitle(),
                'transcription' => $recording->getTranscription(),
                'owner_id' => $recording->getOwner()->getId()->toRfc4122(),
                'shared_with_ids' => $sharedWithIds,
                'status' => $recording->getStatus()->value,
                'created_at' => $recording->getCreatedAt()->format('c'),
            ],
        ]);
    }

    public function remove(Recording $recording): void
    {
        try {
            $this->client->delete([
                'index' => $this->indexName,
                'id' => $recording->getId()->toRfc4122(),
            ]);
        } catch (\Throwable) {
            // Ignore if document doesn't exist
        }
    }
}
