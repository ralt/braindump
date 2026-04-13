<?php

namespace App\Service;

interface ApiKeyEncryptorInterface
{
    public function encrypt(string $plaintext): string;

    public function decrypt(string $encrypted): string;
}
