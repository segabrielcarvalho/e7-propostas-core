<?php

declare(strict_types=1);

namespace E7Propostas\Domain;

final class InvoiceJobCanonicalizer
{
    private const RUNNABLE_PRIORITY = [
        'processing' => 3,
        'pending' => 2,
        'retry' => 1,
    ];

    /**
     * @param list<array<string, mixed>> $jobs
     * @return array{canonical_id: int|null, rebuild: bool}
     */
    public static function choose(array $jobs, string $invoiceStatus): array
    {
        if ($jobs === []) {
            return ['canonical_id' => null, 'rebuild' => $invoiceStatus === 'processing'];
        }

        usort($jobs, static function (array $left, array $right): int {
            $priority = (self::RUNNABLE_PRIORITY[(string) ($right['status'] ?? '')] ?? 0)
                <=> (self::RUNNABLE_PRIORITY[(string) ($left['status'] ?? '')] ?? 0);
            if ($priority !== 0) {
                return $priority;
            }
            $updated = strcmp((string) ($right['updated_at'] ?? ''), (string) ($left['updated_at'] ?? ''));
            if ($updated !== 0) {
                return $updated;
            }
            $created = strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
            return $created !== 0 ? $created : ((int) ($right['id'] ?? 0) <=> (int) ($left['id'] ?? 0));
        });

        $canonical = $jobs[0];
        $runnable = isset(self::RUNNABLE_PRIORITY[(string) ($canonical['status'] ?? '')]);
        return [
            'canonical_id' => (int) $canonical['id'],
            'rebuild' => $invoiceStatus === 'processing' && ! $runnable,
        ];
    }
}
