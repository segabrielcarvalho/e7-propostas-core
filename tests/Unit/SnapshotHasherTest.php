<?php

declare(strict_types=1);

namespace E7Propostas\Tests\Unit;

use E7Propostas\Domain\SnapshotHasher;
use PHPUnit\Framework\TestCase;

final class SnapshotHasherTest extends TestCase
{
    public function test_produces_a_stable_hash_for_equivalent_metadata(): void
    {
        $hasher = new SnapshotHasher();

        $first = $hasher->create('<main>Proposal</main>', ['currency' => 'EUR', 'client' => ['name' => 'Acme', 'id' => 7]]);
        $second = $hasher->create('<main>Proposal</main>', ['client' => ['id' => 7, 'name' => 'Acme'], 'currency' => 'EUR']);

        self::assertSame($first->hash, $second->hash);
        self::assertSame($first->canonicalJson, $second->canonicalJson);
    }

    public function test_detects_any_change_to_the_rendered_content(): void
    {
        $hasher = new SnapshotHasher();

        $before = $hasher->create('<p>EUR 1,000</p>', ['language' => 'en_IE']);
        $after = $hasher->create('<p>EUR 1,001</p>', ['language' => 'en_IE']);

        self::assertNotSame($before->hash, $after->hash);
    }
}
