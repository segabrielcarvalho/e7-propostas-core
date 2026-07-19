<?php

declare(strict_types=1);

namespace E7Propostas\Tests\Unit;

use DateTimeImmutable;
use E7Propostas\Domain\OtpService;
use PHPUnit\Framework\TestCase;

final class OtpServiceTest extends TestCase
{
    public function test_issues_a_six_digit_challenge_valid_for_ten_minutes(): void
    {
        $now = new DateTimeImmutable('2026-07-18T12:00:00+00:00');
        $issued = (new OtpService('otp-pepper'))->issue($now);

        self::assertMatchesRegularExpression('/^\d{6}$/', $issued->code);
        self::assertSame('2026-07-18T12:10:00+00:00', $issued->challenge->expiresAt->format(DATE_ATOM));
        self::assertNotSame($issued->code, $issued->challenge->codeHash);
    }

    public function test_accepts_the_correct_code_only_once(): void
    {
        $now = new DateTimeImmutable('2026-07-18T12:00:00+00:00');
        $service = new OtpService('otp-pepper');
        $issued = $service->issue($now);

        $verified = $service->verify($issued->challenge, $issued->code, $now->modify('+1 minute'));
        $reused = $service->verify($verified->challenge, $issued->code, $now->modify('+2 minutes'));

        self::assertTrue($verified->isValid);
        self::assertSame('valid', $verified->reason);
        self::assertFalse($reused->isValid);
        self::assertSame('consumed', $reused->reason);
    }

    public function test_expires_and_locks_a_challenge_after_five_failures(): void
    {
        $now = new DateTimeImmutable('2026-07-18T12:00:00+00:00');
        $service = new OtpService('otp-pepper');
        $issued = $service->issue($now);

        $expired = $service->verify($issued->challenge, $issued->code, $now->modify('+11 minutes'));
        self::assertSame('expired', $expired->reason);

        $challenge = $issued->challenge;
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $result = $service->verify($challenge, '000000', $now->modify('+1 minute'));
            $challenge = $result->challenge;
        }

        self::assertSame('locked', $result->reason);
        self::assertSame(5, $challenge->attempts);
    }
}
