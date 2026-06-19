<?php

namespace App\Service;

/**
 * Encrypts per-user provider API keys at rest using libsodium's secretbox
 * (XSalsa20-Poly1305), with a 32-byte key derived from APP_SECRET. This is the
 * default for self-hosted / local installs; on Symfony Cloud the container swaps
 * in {@see VaultKmsApiKeyEncryptor} for managed key storage in production.
 */
class SecretBoxApiKeyEncryptor implements ApiKeyEncryptorInterface
{
    private const PREFIX = 'secretbox:';

    private string $key;

    public function __construct(string $appSecret)
    {
        // APP_SECRET is an arbitrary-length string; hash it down to the exact key size.
        $this->key = sodium_crypto_generichash($appSecret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    public function decrypt(string $encrypted): string
    {
        // Values stored before encryption was enabled are plaintext — pass them
        // through so existing keys keep working (they get encrypted on the next save).
        if (!str_starts_with($encrypted, self::PREFIX)) {
            return $encrypted;
        }

        $decoded = base64_decode(substr($encrypted, \strlen(self::PREFIX)), true);
        if ($decoded === false || \strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Malformed encrypted API key.');
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        if ($plaintext === false) {
            throw new \RuntimeException('Unable to decrypt API key (wrong APP_SECRET?).');
        }

        return $plaintext;
    }
}
