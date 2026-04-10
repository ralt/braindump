<?php

namespace App\Search;

use App\Entity\Recording;
use App\Entity\User;

interface SearchProviderInterface
{
    /**
     * @return Recording[]
     */
    public function search(string $query, User $user): array;

    public function index(Recording $recording): void;

    public function remove(Recording $recording): void;
}
