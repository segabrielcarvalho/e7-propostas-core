<?php

declare(strict_types=1);

namespace E7Propostas\Tests\Unit;

use E7Propostas\Domain\CanonicalPayload;
use PHPUnit\Framework\TestCase;

final class CanonicalPayloadTest extends TestCase
{
    public function test_hash_is_stable_for_equivalent_key_order(): void
    {
        self::assertTrue(class_exists(CanonicalPayload::class), 'CanonicalPayload must exist.');
        $first = ['legal_name' => 'E7', 'responsible' => ['name' => 'Gabriel', 'role' => 'Director']];
        $second = ['responsible' => ['role' => 'Director', 'name' => 'Gabriel'], 'legal_name' => 'E7'];
        self::assertSame(CanonicalPayload::hash($first), CanonicalPayload::hash($second));
        self::assertSame(CanonicalPayload::encode($first), CanonicalPayload::encode($second));
    }

    public function test_hash_changes_with_business_or_invoice_context(): void
    {
        self::assertTrue(class_exists(CanonicalPayload::class), 'CanonicalPayload must exist.');
        $base = ['business_profile' => ['legal_name' => 'E7'], 'invoice_total_minor' => 100, 'version' => 1];
        self::assertNotSame(CanonicalPayload::hash($base), CanonicalPayload::hash([...$base, 'invoice_total_minor' => 101]));
        self::assertNotSame(CanonicalPayload::hash($base), CanonicalPayload::hash([...$base, 'version' => 2]));
    }
}
