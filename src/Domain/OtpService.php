<?php

declare(strict_types=1);

namespace E7Propostas\Domain;

use DateTimeImmutable;

final class OtpService
{
    private const TTL_SECONDS = 600;
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly string $pepper)
    {
        if ($this->pepper === '') {
            throw new \InvalidArgumentException('OTP pepper cannot be empty.');
        }
    }

    public function issue(DateTimeImmutable $now): IssuedOtp
    {
        $code = (string) random_int(100000, 999999);
        $challenge = new OtpChallenge(
            $this->hash($code),
            $now->modify('+' . self::TTL_SECONDS . ' seconds'),
        );

        return new IssuedOtp($code, $challenge);
    }

    public function verify(OtpChallenge $challenge, string $code, DateTimeImmutable $now): OtpVerification
    {
        if ($challenge->consumed) {
            return new OtpVerification(false, 'consumed', $challenge);
        }

        if ($now > $challenge->expiresAt) {
            return new OtpVerification(false, 'expired', $challenge);
        }

        if ($challenge->attempts >= self::MAX_ATTEMPTS) {
            return new OtpVerification(false, 'locked', $challenge);
        }

        if (! hash_equals($challenge->codeHash, $this->hash($code))) {
            $failed = $challenge->fail();
            $reason = $failed->attempts >= self::MAX_ATTEMPTS ? 'locked' : 'invalid';

            return new OtpVerification(false, $reason, $failed);
        }

        return new OtpVerification(true, 'valid', $challenge->consume());
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, $this->pepper);
    }
}
