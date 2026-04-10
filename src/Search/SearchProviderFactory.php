<?php

namespace App\Search;

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
            default => new PostgresSearchProvider($this->em),
        };
    }

    private function createOpenSearchProvider(): OpenSearchProvider
    {
        $client = ClientBuilder::create()
            ->setHosts([$this->openSearchUrl])
            ->build();

        return new OpenSearchProvider($client, $this->em, $this->openSearchIndex);
    }
}
