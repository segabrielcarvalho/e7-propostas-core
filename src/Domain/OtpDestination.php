<?php

declare(strict_types=1);

namespace E7Propostas\Domain;

final readonly class OtpDestination
{
    private function __construct(public string $channel, public string $value)
    {
    }

    public static function from(string $channel, string $value): self
    {
        $channel = strtolower(trim($channel));
        $value = trim($value);

        if ($channel === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return new self($channel, $value);
        }

        if ($channel === 'sms' && preg_match('/^\+[1-9][0-9]{6,14}$/', $value) === 1) {
            return new self($channel, $value);
        }

        throw new \InvalidArgumentException('Invalid OTP channel or destination.');
    }
}
