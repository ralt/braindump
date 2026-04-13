<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class VaultKmsApiKeyEncryptor implements ApiKeyEncryptorInterface
{
    private const KEY_NAME = 'api-keys';

    private string $baseUrl;
    private string $token;

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
        $relationships = json_decode(base64_decode($_ENV['PLATFORM_RELATIONSHIPS']), true);
        $vaultKms = $relationships['vault_kms'][0];

        $this->baseUrl = sprintf('%s://%s:%d', $vaultKms['scheme'], $vaultKms['host'], $vaultKms['port']);
        $this->token = $vaultKms['password'];
    }

    public function encrypt(string $plaintext): string
    {
        $response = $this->httpClient->request('POST', sprintf('%s/v1/transit/encrypt/%s', $this->baseUrl, self::KEY_NAME), [
            'headers' => [
                'X-Vault-Token' => $this->token,
            ],
            'json' => [
                'plaintext' => base64_encode($plaintext),
            ],
        ]);

        $data = $response->toArray();

        return $data['data']['ciphertext'];
    }

    public function decrypt(string $encrypted): string
    {
        $response = $this->httpClient->request('POST', sprintf('%s/v1/transit/decrypt/%s', $this->baseUrl, self::KEY_NAME), [
            'headers' => [
                'X-Vault-Token' => $this->token,
            ],
            'json' => [
                'ciphertext' => $encrypted,
            ],
        ]);

        $data = $response->toArray();

        return base64_decode($data['data']['plaintext']);
    }
}
