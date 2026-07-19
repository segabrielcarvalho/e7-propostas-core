<?php

declare(strict_types=1);

namespace E7Propostas\Domain;

final readonly class IssuedOtp
{
    public function __construct(public string $code, public OtpChallenge $challenge)
    {
    }
}
