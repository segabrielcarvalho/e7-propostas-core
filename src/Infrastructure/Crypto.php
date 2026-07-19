<?php

declare(strict_types=1);

namespace E7Propostas\Infrastructure;

final class Crypto
{
    private readonly string $key;

    public function __construct(string $secret)
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('Encryption secret cannot be empty.');
        }
        $this->key = hash('sha256', $secret, true);
    }

    public function seal(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key));
    }

    public function open(string $ciphertext): string
    {
        $decoded = base64_decode($ciphertext, true);
        if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Invalid encrypted payload.');
        }
        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open(substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
        if ($plaintext === false) {
            throw new \RuntimeException('Could not decrypt payload.');
        }
        return $plaintext;
    }
}
