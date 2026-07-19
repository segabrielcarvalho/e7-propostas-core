<?php

declare(strict_types=1);

namespace E7Propostas\Domain;

use DateTimeImmutable;

final readonly class OtpChallenge
{
    public function __construct(
        public string $codeHash,
        public DateTimeImmutable $expiresAt,
        public int $attempts = 0,
        public bool $consumed = false,
    ) {
    }

    public function fail(): self
    {
        return new self($this->codeHash, $this->expiresAt, $this->attempts + 1, $this->consumed);
    }

    public function consume(): self
    {
        return new self($this->codeHash, $this->expiresAt, $this->attempts, true);
    }
}
