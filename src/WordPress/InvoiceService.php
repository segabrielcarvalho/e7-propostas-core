<?php

declare(strict_types=1);

namespace E7Propostas\WordPress;

use E7Propostas\Domain\BusinessProfile;
use E7Propostas\Domain\InvoiceItems;
use E7Propostas\Domain\InvoiceStatus;
use E7Propostas\Domain\SupplierProfile;
use E7Propostas\Infrastructure\ViesClient;

final class InvoiceService
{
    private readonly \Closure $viesCheck;

    public function __construct(private readonly InvoiceStore $store, ?callable $viesCheck = null)
    {
        $this->viesCheck = $viesCheck === null
            ? \Closure::fromCallable([new ViesClient(), 'check'])
            : \Closure::fromCallable($viesCheck);
    }

    /** @param array<string, mixed>|null $legacyProfile @param list<array<string, mixed>>|null $legacyItems @return array<string, mixed> */
    public function prepareDraft(int $acceptanceId, ?array $legacyProfile, ?array $legacyItems, bool $legacyConfirmed, int $actorId): array
    {
        $existing = $this->store->currentRoot($acceptanceId);
        if (is_array($existing)) {
            return $existing;
        }
        $context = $this->store->acceptanceContext($acceptanceId);
        if (($context['locale'] ?? null) !== 'en_IE' || ($context['currency'] ?? null) !== 'EUR') {
            throw new \DomainException('Commercial invoices are available only for en_IE/EUR acceptances.');
        }
        $profile = is_array($context['customer_profile'] ?? null) ? $context['customer_profile'] : null;
        $items = is_array($context['invoice_items'] ?? null) ? $context['invoice_items'] : [];
        $legacy = $profile === null || $items === [];
        if ($legacy) {
            if (! $legacyConfirmed || $legacyProfile === null || $legacyItems === null) {
                throw new \DomainException('Legacy invoice backfill requires customer data, items and explicit correspondence confirmation.');
            }
            $profile = BusinessProfile::normalize($legacyProfile);
            $items = InvoiceItems::normalize($legacyItems);
        } else {
            $profile = BusinessProfile::normalize($profile);
            $items = InvoiceItems::normalize($items);
        }
        if ($items === []) {
            throw new \DomainException('Invoice items are required.');
        }
        $total = InvoiceItems::total($items);
        $expected = (int) ($context['invoice_total_minor'] ?? $total);
        if (! $legacy && $total !== $expected) {
            throw new \DomainException('Immutable invoice items do not match the accepted total.');
        }
        $supplierOption = function_exists('get_option') ? get_option('e7_invoice_supplier_profile', SupplierProfile::defaults()) : SupplierProfile::defaults();
        $supplier = SupplierProfile::normalize($supplierOption);
        $invoice = $this->store->createDraft([
            'acceptance_id' => $acceptanceId,
            'version_id' => (int) $context['version_id'],
            'currency' => 'EUR',
            'customer_profile' => $profile,
            'supplier_profile' => $supplier,
            'items' => $items,
            'total_minor' => $total,
            'legacy_backfill_required' => $legacy,
        ]);
        if ($legacy) {
            $invoice = $this->store->backfillLegacy((int) $invoice['id'], $profile, $items, $total, $actorId);
        }
        $this->store->appendAudit((int) $context['version_id'], 'invoice.draft_prepared', [
            'invoice_id' => (int) $invoice['id'],
            'actor_id' => $actorId,
            'total_minor' => $total,
            'snapshot_hash' => (string) $invoice['snapshot_hash'],
        ]);
        return $invoice;
    }

    /** @param array<string, mixed> $profile @return array<string, mixed> */
    public function saveDraftCustomer(int $invoiceId, array $profile, int $actorId): array
    {
        $invoice = $this->requireInvoice($invoiceId);
        if ($invoice['status'] !== InvoiceStatus::DRAFT) {
            throw new \DomainException('Customer data can be corrected only while the invoice is draft.');
        }
        $updated = $this->store->updateDraftCustomer($invoiceId, BusinessProfile::normalize($profile));
        $this->store->appendAudit((int) $invoice['version_id'], 'invoice.customer_corrected', ['invoice_id' => $invoiceId, 'actor_id' => $actorId]);
        return $updated;
    }

    /** @return array<string, mixed> */
    public function issue(int $invoiceId, bool $viesAcknowledged, int $actorId): array
    {
        $invoice = $this->requireInvoice($invoiceId);
        InvoiceStatus::assertTransition((string) $invoice['status'], InvoiceStatus::PROCESSING);
        if (! empty($invoice['legacy_backfill_required'])) {
            throw new \DomainException('Legacy invoice data must be explicitly confirmed before issue.');
        }
        $vies = (string) ($invoice['vies_status'] ?? 'not_requested');
        if (in_array($vies, ['not_requested', 'pending', 'invalid', 'unavailable'], true)) {
            if (! $viesAcknowledged) {
                throw new \DomainException('VIES status requires explicit administrator acknowledgement before issue.');
            }
            $this->store->appendAudit((int) $invoice['version_id'], 'invoice.vies_acknowledged', [
                'invoice_id' => $invoiceId,
                'actor_id' => $actorId,
                'vies_status' => $vies,
            ]);
        }
        $processing = $this->store->issueAndEnqueue($invoiceId);
        $this->store->appendAudit((int) $invoice['version_id'], 'invoice.issue_requested', [
            'invoice_id' => $invoiceId,
            'invoice_number' => (string) $processing['invoice_number'],
            'actor_id' => $actorId,
        ]);
        return $processing;
    }

    /** @return array<string, mixed> */
    public function retry(int $invoiceId, int $actorId): array
    {
        $invoice = $this->requireInvoice($invoiceId);
        InvoiceStatus::assertTransition((string) $invoice['status'], InvoiceStatus::PROCESSING);
        if (! is_string($invoice['invoice_number'] ?? null) || $invoice['invoice_number'] === '') {
            throw new \DomainException('A failed invoice must retain its reserved number.');
        }
        $processing = $this->store->retryAndEnqueue($invoiceId);
        $this->store->appendAudit((int) $invoice['version_id'], 'invoice.retry_requested', ['invoice_id' => $invoiceId, 'actor_id' => $actorId]);
        return $processing;
    }

    /** @param array<string, mixed> $profile @param list<array<string, mixed>> $items @return array<string, mixed> */
    public function backfillLegacy(int $invoiceId, array $profile, array $items, bool $confirmed, int $actorId): array
    {
        $invoice = $this->requireInvoice($invoiceId);
        if ($invoice['status'] !== InvoiceStatus::DRAFT || empty($invoice['legacy_backfill_required'])) {
            throw new \DomainException('Legacy invoice backfill is no longer available.');
        }
        if (! $confirmed) {
            throw new \DomainException('Legacy invoice backfill requires explicit correspondence confirmation.');
        }
        $normalizedProfile = BusinessProfile::normalize($profile);
        $normalizedItems = InvoiceItems::normalize($items);
        if ($normalizedItems === []) {
            throw new \DomainException('Legacy invoice items are required.');
        }
        $total = InvoiceItems::total($normalizedItems);
        return $this->store->backfillLegacy($invoiceId, $normalizedProfile, $normalizedItems, $total, $actorId);
    }

    /** @param array<string, string|null> $artifact @return array<string, mixed> */
    public function markIssued(int $invoiceId, array $artifact): array
    {
        $invoice = $this->requireInvoice($invoiceId);
        InvoiceStatus::assertTransition((string) $invoice['status'], InvoiceStatus::ISSUED);
        $key = trim((string) ($artifact['artifact_key'] ?? ''));
        $hash = strtolower(trim((string) ($artifact['artifact_hash'] ?? '')));
        if ($key === '' || ! preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new \InvalidArgumentException('Issued invoice artifact evidence is invalid.');
        }
        $issued = $this->store->markIssued($invoiceId, [
            'artifact_key' => $key,
            'artifact_hash' => $hash,
            'kms_signature' => isset($artifact['kms_signature']) ? (string) $artifact['kms_signature'] : null,
        ]);
        $this->store->appendAudit((int) $invoice['version_id'], 'invoice.issued', [
            'invoice_id' => $invoiceId,
            'invoice_number' => (string) $invoice['invoice_number'],
            'artifact_hash' => $hash,
        ]);
        return $issued;
    }

    public function markFinalizationFailed(int $invoiceId, string $message): void
    {
        $invoice = $this->requireInvoice($invoiceId);
        InvoiceStatus::assertTransition((string) $invoice['status'], InvoiceStatus::FAILED);
        $this->store->markFailed($invoiceId, $message);
        $this->store->appendAudit((int) $invoice['version_id'], 'invoice.finalization_failed', [
            'invoice_id' => $invoiceId,
            'reason_hash' => hash('sha256', $message),
        ]);
    }

    /** @return array<string, mixed> */
    public function invoice(int $invoiceId): array
    {
        return $this->store->verifiedInvoice($invoiceId);
    }

    /** @return array<string, mixed> */
    public function cancel(int $invoiceId, int $actorId): array
    {
        $invoice = $this->requireInvoice($invoiceId);
        InvoiceStatus::assertTransition((string) $invoice['status'], InvoiceStatus::CANCELLED);
        $cancelled = $this->store->cancel($invoiceId);
        $this->store->appendAudit((int) $invoice['version_id'], 'invoice.cancelled', ['invoice_id' => $invoiceId, 'actor_id' => $actorId]);
        return $cancelled;
    }

    /** @return array<string, mixed> */
    public function createReplacement(int $invoiceId, int $actorId): array
    {
        $invoice = $this->requireInvoice($invoiceId);
        if (! in_array($invoice['status'], [InvoiceStatus::ISSUED, InvoiceStatus::CANCELLED], true)) {
            throw new \DomainException('Only an issued or cancelled invoice can be replaced.');
        }
        return $this->store->createReplacement($invoiceId, $actorId);
    }

    /** @return array<string, mixed> */
    public function recheckVies(int $invoiceId, int $actorId): array
    {
        $invoice = $this->requireInvoice($invoiceId);
        $profile = is_array($invoice['customer_profile'] ?? null) ? $invoice['customer_profile'] : [];
        $vat = (string) ($profile['vat_number'] ?? '');
        $result = ($this->viesCheck)($vat);
        $updated = $this->store->updateVies($invoiceId, $result);
        $this->store->appendAudit((int) $invoice['version_id'], 'invoice.vies_checked', [
            'invoice_id' => $invoiceId,
            'actor_id' => $actorId,
            'status' => (string) $result['status'],
            'evidence_hash' => hash('sha256', json_encode($result['evidence'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
        ]);
        return $updated;
    }

    /** @return array<string, mixed> */
    private function requireInvoice(int $invoiceId): array
    {
        $invoice = $this->store->get($invoiceId);
        if (! is_array($invoice)) {
            throw new \DomainException('Invoice was not found.');
        }
        return $invoice;
    }
}
