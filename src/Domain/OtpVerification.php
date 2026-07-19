<?php

declare(strict_types=1);

namespace E7Propostas\Domain;

final readonly class OtpVerification
{
    public function __construct(
        public bool $isValid,
        public string $reason,
        public OtpChallenge $challenge,
    ) {
    }
}
