<?php

declare(strict_types=1);

namespace E7Propostas\Tests\Unit;

use E7Propostas\Infrastructure\InvoiceFinalizer;
use E7Propostas\Infrastructure\InvoiceSignatureEnvelope;
use PHPUnit\Framework\TestCase;

final class InvoiceArtifactRecoveryTest extends TestCase
{
    public function test_valid_existing_s3_object_is_recovered_before_render_or_put(): void
    {
        $invoice = $this->invoice();
        $remote = $this->remote($invoice, '%PDF-existing');
        $effects = ['render' => 0, 'put' => 0];
        $finalizer = $this->finalizer($remote, $effects);

        $artifact = $finalizer->finalize($invoice);

        self::assertSame('invoices/' . $invoice['public_id'] . '.pdf', $artifact['artifact_key']);
        self::assertSame(hash('sha256', '%PDF-existing'), $artifact['artifact_hash']);
        self::assertSame(0, $effects['render']);
        self::assertSame(0, $effects['put']);
    }

    public function test_412_race_recovers_the_winning_object_without_a_second_put(): void
    {
        $invoice = $this->invoice();
        $remote = null;
        $effects = ['render' => 0, 'put' => 0];
        $finalizer = $this->finalizer($remote, $effects, static function (string $key, string $pdf, array $metadata) use (&$remote, &$effects): string {
            $effects['put']++;
            $remote = ['artifact_key' => $key, 'body' => $pdf, 'metadata' => $metadata];
            throw new \RuntimeException('412 Precondition Failed');
        });

        $artifact = $finalizer->finalize($invoice);

        self::assertSame(hash('sha256', '%PDF-generated'), $artifact['artifact_hash']);
        self::assertSame(1, $effects['render']);
        self::assertSame(1, $effects['put']);
    }

    public function test_inconsistent_remote_metadata_is_rejected_before_render_or_put(): void
    {
        $invoice = $this->invoice();
        $remote = $this->remote($invoice, '%PDF-existing');
        $remote['metadata']['public-id'] = str_repeat('f', 32);
        $effects = ['render' => 0, 'put' => 0];
        $finalizer = $this->finalizer($remote, $effects);

        try {
            $finalizer->finalize($invoice);
            self::fail('Inconsistent S3 metadata must be rejected.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('metadata', strtolower($error->getMessage()));
        }

        self::assertSame(0, $effects['render']);
        self::assertSame(0, $effects['put']);
    }

    public function test_retry_after_database_persistence_failure_recovers_without_a_second_render_or_put(): void
    {
        $invoice = $this->invoice();
        $remote = null;
        $effects = ['render' => 0, 'put' => 0];
        $finalizer = $this->finalizer($remote, $effects);

        $first = $finalizer->finalize($invoice);
        // Simulate the worker crashing before this returned evidence reaches InvoiceRepository.
        $second = $finalizer->finalize($invoice);

        self::assertSame($first, $second);
        self::assertSame(1, $effects['render']);
        self::assertSame(1, $effects['put']);
    }

    public function test_default_s3_put_contract_is_create_only_and_carries_authenticated_metadata(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/InvoiceFinalizer.php');

        self::assertStringContainsString("'IfNoneMatch' => '*'", $source);
        foreach (['public-id', 'snapshot-hash', 'artifact-hash', 'signature-payload-hash', 'kms-signature'] as $field) {
            self::assertStringContainsString($field, $source);
        }
    }

    /** @param array<string, mixed>|null $remote @param array{render: int, put: int} $effects */
    private function finalizer(?array &$remote, array &$effects, ?callable $writer = null): InvoiceFinalizer
    {
        return new InvoiceFinalizer(
            environment: static fn (): string => 'production',
            pdfRenderer: static function () use (&$effects): string { $effects['render']++; return '%PDF-generated'; },
            signer: static fn (string $hash): string => base64_encode('signature:' . $hash),
            signatureVerifier: static fn (string $hash, string $signature): bool => hash_equals(base64_encode('signature:' . $hash), $signature),
            objectReader: static function (string $key) use (&$remote): ?array { return $remote; },
            objectWriter: $writer ?? static function (string $key, string $pdf, array $metadata) use (&$remote, &$effects): string {
                $effects['put']++;
                $remote = ['artifact_key' => $key, 'body' => $pdf, 'metadata' => $metadata];
                return $key;
            },
        );
    }

    /** @param array<string, mixed> $invoice @return array<string, mixed> */
    private function remote(array $invoice, string $pdf): array
    {
        $artifactHash = hash('sha256', $pdf);
        $issuedAt = (string) $invoice['due_at'];
        $payloadHash = InvoiceSignatureEnvelope::hash($invoice + ['artifact_hash' => $artifactHash, 'issued_at' => $issuedAt]);
        return [
            'artifact_key' => 'invoices/' . $invoice['public_id'] . '.pdf',
            'body' => $pdf,
            'metadata' => [
                'public-id' => $invoice['public_id'],
                'snapshot-hash' => $invoice['snapshot_hash'],
                'artifact-hash' => $artifactHash,
                'signature-payload-hash' => $payloadHash,
                'kms-signature' => base64_encode('signature:' . $payloadHash),
                'issued-at' => $issuedAt,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function invoice(): array
    {
        return [
            'public_id' => str_repeat('a', 32),
            'snapshot_hash' => str_repeat('b', 64),
            'invoice_number' => 'E7-2026-4821',
            'currency' => 'EUR',
            'total_minor' => 127500,
            'status' => 'processing',
            'due_at' => '2026-07-20 12:00:00',
            'supplier_profile' => ['legal_name' => 'E7 Company Tecnologia Ltda.'],
            'customer_profile' => ['legal_name' => 'Ross Motorcycles Limited'],
            'items' => [['description' => 'Services', 'amount_minor' => 127500]],
        ];
    }
}
