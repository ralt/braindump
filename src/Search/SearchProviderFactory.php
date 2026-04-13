<?php

namespace App\Search;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Elastic\Elasticsearch\ClientBuilder;

class SearchProviderFactory
{
    public function __construct(
        private EntityManagerInterface $em,
        private string $searchProvider,
        private string $openSearchUrl,
        private string $openSearchIndex,
    ) {}

    public function create(): SearchProviderInterface
    {
        return match ($this->searchProvider) {
            'opensearch' => $this->createOpenSearchProvider(),
            default => $this->createDefaultProvider(),
        };
    }

    private function createDefaultProvider(): SearchProviderInterface
    {
        $platform = $this->em->getConnection()->getDatabasePlatform();
        if ($platform instanceof SQLitePlatform) {
            return new SqliteSearchProvider($this->em);
        }

        return new PostgresSearchProvider($this->em);
    }

    private function createOpenSearchProvider(): OpenSearchProvider
    {
        $client = ClientBuilder::create()
            ->setHosts([$this->openSearchUrl])
            ->build();

        return new OpenSearchProvider($client, $this->em, $this->openSearchIndex);
    }
}
