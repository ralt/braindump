<?php

namespace App\Service;

class PlaintextApiKeyEncryptor implements ApiKeyEncryptorInterface
{
    public function encrypt(string $plaintext): string
    {
        return $plaintext;
    }

    public function decrypt(string $encrypted): string
    {
        return $encrypted;
    }
}
