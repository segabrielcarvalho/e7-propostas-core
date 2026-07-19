<?php

declare(strict_types=1);

namespace E7Propostas\Tests\Unit;

use E7Propostas\Domain\PasswordService;
use PHPUnit\Framework\TestCase;

final class PasswordServiceTest extends TestCase
{
    public function test_hashes_and_verifies_a_proposal_password(): void
    {
        $service = new PasswordService();
        $hash = $service->hash('Cliente-E7-2026');

        self::assertNotSame('Cliente-E7-2026', $hash);
        self::assertTrue($service->verify('Cliente-E7-2026', $hash));
        self::assertFalse($service->verify('senha-incorreta', $hash));
    }

    public function test_hashes_and_verifies_short_passwords(): void
    {
        $service = new PasswordService();
        $hash = $service->hash('12345');

        self::assertTrue($service->verify('12345', $hash));
    }

    public function test_rejects_an_empty_password(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new PasswordService())->hash('');
    }
}
