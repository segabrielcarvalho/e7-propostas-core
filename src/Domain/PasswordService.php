<?php

declare(strict_types=1);

namespace E7Propostas\Domain;

final class PasswordService
{
    public function hash(string $password): string
    {
        if ($password === '') {
            throw new \InvalidArgumentException('Proposal password is required.');
        }

        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $hash = password_hash($password, $algorithm);

        if ($hash === false) {
            throw new \RuntimeException('Could not hash the proposal password.');
        }

        return $hash;
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}
