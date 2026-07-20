<?php

declare(strict_types=1);

namespace E7Propostas\WordPress;

interface InvoiceStore
{
    /** @return array<string, mixed> */
    public function acceptanceContext(int $acceptanceId): array;
    /** @return array<string, mixed>|null */
    public function currentRoot(int $acceptanceId): ?array;
    /** @return array<string, mixed>|null */
    public function get(int $invoiceId): ?array;
    /** Returns an invoice only after validating its canonical snapshot hash. @return array<string, mixed> */
    public function verifiedInvoice(int $invoiceId): array;
    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    public function createDraft(array $snapshot): array;
    /** @param array<string, mixed> $profile @return array<string, mixed> */
    public function updateDraftCustomer(int $invoiceId, array $profile): array;
    /** Atomically reserves the number, transitions to processing and persists one finalize_invoice job. @return array<string, mixed> */
    public function issueAndEnqueue(int $invoiceId): array;
    public function markFailed(int $invoiceId, string $message): void;
    /** Persists immutable artifact evidence while the invoice is still processing. @param array<string, string|null> $artifact @return array<string, mixed> */
    public function persistArtifact(int $invoiceId, array $artifact): array;
    /** @param array<string, string|null> $artifact @return array<string, mixed> */
    public function markIssued(int $invoiceId, array $artifact): array;
    /** Atomically transitions a failed invoice and resets its single finalize_invoice job. @return array<string, mixed> */
    public function retryAndEnqueue(int $invoiceId): array;
    /** @param array<string, mixed> $profile @param list<array<string, mixed>> $items @return array<string, mixed> */
    public function backfillLegacy(int $invoiceId, array $profile, array $items, int $totalMinor, int $actorId): array;
    /** @return array<string, mixed> */
    public function cancel(int $invoiceId, int $actorId): array;
    /** @return array<string, mixed> */
    public function createReplacement(int $invoiceId, int $actorId): array;
    /** @param array<string, mixed> $result @return array<string, mixed> */
    public function updateVies(int $invoiceId, array $result): array;
    /** @param array<string, mixed> $payload */
    public function appendAudit(int $versionId, string $type, array $payload): void;
}
