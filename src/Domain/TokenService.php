<?php

declare(strict_types=1);

namespace E7Propostas\Domain;

final class TokenService
{
    public function __construct(private readonly string $pepper)
    {
        if ($this->pepper === '') {
            throw new \InvalidArgumentException('Token pepper cannot be empty.');
        }
    }

    public function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function hash(string $token): string
    {
        return hash_hmac('sha256', $token, $this->pepper);
    }
}
