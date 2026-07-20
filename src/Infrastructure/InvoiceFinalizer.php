<?php

declare(strict_types=1);

namespace E7Propostas\Infrastructure;

use Aws\Exception\AwsException;
use Aws\Kms\KmsClient;
use Aws\S3\S3Client;

final class InvoiceFinalizer
{
    private readonly \Closure $environment;
    private readonly \Closure $pdfRenderer;
    private readonly \Closure $signer;
    private readonly \Closure $signatureVerifier;
    private readonly \Closure $objectReader;
    private readonly \Closure $objectWriter;
    private readonly \Closure $localWriter;
    private readonly \Closure $verificationUrl;

    public function __construct(
        private readonly InvoiceHtmlRenderer $htmlRenderer = new InvoiceHtmlRenderer(),
        ?callable $environment = null,
        ?callable $pdfRenderer = null,
        ?callable $signer = null,
        ?callable $signatureVerifier = null,
        ?callable $objectReader = null,
        ?callable $objectWriter = null,
        ?callable $localWriter = null,
        ?callable $verificationUrl = null,
    ) {
        $this->environment = \Closure::fromCallable($environment ?? static fn (): string => function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production');
        $this->pdfRenderer = \Closure::fromCallable($pdfRenderer ?? [$this, 'renderPdf']);
        $this->signer = \Closure::fromCallable($signer ?? [$this, 'signHash']);
        $this->signatureVerifier = \Closure::fromCallable($signatureVerifier ?? [$this, 'verifyHash']);
        $this->objectReader = \Closure::fromCallable($objectReader ?? [$this, 'fetchStoredPdf']);
        $this->objectWriter = \Closure::fromCallable($objectWriter ?? [$this, 'storePdf']);
        $this->localWriter = \Closure::fromCallable($localWriter ?? [$this, 'writeLocalHtml']);
        $this->verificationUrl = \Closure::fromCallable($verificationUrl ?? static fn (string $publicId): string => function_exists('home_url') ? home_url('/invoice/verify/' . $publicId . '/') : 'https://localhost/invoice/verify/' . $publicId . '/');
    }

    /** @param array<string, mixed> $invoice @return array{artifact_key: string, artifact_hash: string, signature_payload_hash: string, kms_signature: ?string, issued_at: string} */
    public function finalize(array $invoice): array
    {
        $environment = (string) ($this->environment)();
        $existing = $this->existingArtifact($invoice, $environment);
        if ($existing !== null) {
            return $existing;
        }
        if (($invoice['status'] ?? null) !== 'processing') {
            throw new \DomainException('Only a processing invoice can be finalized.');
        }
        $publicId = (string) ($invoice['public_id'] ?? '');
        if (! preg_match('/^[a-f0-9]{32}$/', $publicId)) {
            throw new \InvalidArgumentException('Invoice public ID is invalid.');
        }
        $key = 'invoices/' . $publicId . '.pdf';
        if ($environment !== 'local') {
            $remote = ($this->objectReader)($key);
            if (is_array($remote)) {
                return $this->recoverRemoteArtifact($invoice, $key, $remote);
            }
        }
        $url = (string) ($this->verificationUrl)($publicId);
        $html = $this->htmlRenderer->render($invoice, $url);
        $issuedAt = trim((string) ($invoice['issued_at'] ?? $invoice['due_at'] ?? ''));

        if ($environment === 'local') {
            $path = (string) ($this->localWriter)($publicId, $html);
            if ($path === '') {
                throw new \RuntimeException('Local invoice artifact was not persisted.');
            }
            $artifactHash = hash('sha256', $html);
            $payloadHash = InvoiceSignatureEnvelope::hash(array_merge($invoice, ['artifact_hash' => $artifactHash, 'issued_at' => $issuedAt]));
            return ['artifact_key' => $path, 'artifact_hash' => $artifactHash, 'signature_payload_hash' => $payloadHash, 'kms_signature' => null, 'issued_at' => $issuedAt];
        }

        $pdf = (string) ($this->pdfRenderer)($html);
        if (! str_starts_with($pdf, '%PDF-')) {
            throw new \RuntimeException('Renderer did not return an invoice PDF.');
        }
        $hash = hash('sha256', $pdf);
        $payloadHash = InvoiceSignatureEnvelope::hash(array_merge($invoice, ['artifact_hash' => $hash, 'issued_at' => $issuedAt]));
        $signature = (string) ($this->signer)($payloadHash);
        if ($signature === '') {
            throw new \RuntimeException('KMS did not return an invoice signature.');
        }
        $metadata = [
            'public-id' => $publicId,
            'snapshot-hash' => (string) $invoice['snapshot_hash'],
            'artifact-hash' => $hash,
            'signature-payload-hash' => $payloadHash,
            'kms-signature' => $signature,
            'issued-at' => $issuedAt,
        ];
        try {
            $artifactKey = (string) ($this->objectWriter)($key, $pdf, $metadata);
        } catch (\Throwable $writeError) {
            $remote = ($this->objectReader)($key);
            if (! is_array($remote)) {
                throw $writeError;
            }
            return $this->recoverRemoteArtifact($invoice, $key, $remote);
        }
        if ($artifactKey === '') {
            throw new \RuntimeException('Invoice artifact storage did not return an object key.');
        }
        return ['artifact_key' => $artifactKey, 'artifact_hash' => $hash, 'signature_payload_hash' => $payloadHash, 'kms_signature' => $signature, 'issued_at' => $issuedAt];
    }

    /** @param array<string, mixed> $invoice @param array<string, mixed> $remote @return array{artifact_key: string, artifact_hash: string, signature_payload_hash: string, kms_signature: string, issued_at: string} */
    private function recoverRemoteArtifact(array $invoice, string $key, array $remote): array
    {
        $artifactKey = trim((string) ($remote['artifact_key'] ?? $key));
        $body = $remote['body'] ?? null;
        $rawMetadata = is_array($remote['metadata'] ?? null) ? $remote['metadata'] : [];
        $metadata = [];
        foreach ($rawMetadata as $name => $value) {
            $metadata[str_replace('_', '-', strtolower((string) $name))] = trim((string) $value);
        }
        $issuedAt = trim((string) ($invoice['issued_at'] ?? $invoice['due_at'] ?? ''));
        $publicId = (string) ($invoice['public_id'] ?? '');
        $snapshotHash = (string) ($invoice['snapshot_hash'] ?? '');
        if ($artifactKey !== $key
            || ! is_string($body)
            || ! str_starts_with($body, '%PDF-')
            || ($metadata['public-id'] ?? '') !== $publicId
            || ($metadata['snapshot-hash'] ?? '') !== $snapshotHash
            || ($metadata['issued-at'] ?? '') !== $issuedAt) {
            throw new \RuntimeException('Stored invoice artifact metadata is inconsistent.');
        }
        $artifactHash = hash('sha256', $body);
        $payloadHash = strtolower((string) ($metadata['signature-payload-hash'] ?? ''));
        $signature = (string) ($metadata['kms-signature'] ?? '');
        if (($metadata['artifact-hash'] ?? '') !== $artifactHash
            || ! preg_match('/^[a-f0-9]{64}$/', $payloadHash)
            || $signature === '') {
            throw new \RuntimeException('Stored invoice artifact metadata failed integrity validation.');
        }
        $expectedPayloadHash = InvoiceSignatureEnvelope::hash(array_merge($invoice, ['artifact_hash' => $artifactHash, 'issued_at' => $issuedAt]));
        if (! hash_equals($expectedPayloadHash, $payloadHash) || ! ($this->signatureVerifier)($payloadHash, $signature)) {
            throw new \RuntimeException('Stored invoice artifact metadata failed signature validation.');
        }
        return [
            'artifact_key' => $key,
            'artifact_hash' => $artifactHash,
            'signature_payload_hash' => $payloadHash,
            'kms_signature' => $signature,
            'issued_at' => $issuedAt,
        ];
    }

    /** @param array<string, mixed> $invoice @return array{artifact_key: string, artifact_hash: string, signature_payload_hash: string, kms_signature: ?string, issued_at: string}|null */
    private function existingArtifact(array $invoice, string $environment): ?array
    {
        $key = trim((string) ($invoice['artifact_key'] ?? ''));
        $hash = strtolower(trim((string) ($invoice['artifact_hash'] ?? '')));
        $signature = trim((string) ($invoice['kms_signature'] ?? ''));
        $payloadHash = strtolower(trim((string) ($invoice['signature_payload_hash'] ?? '')));
        $issuedAt = trim((string) ($invoice['issued_at'] ?? ''));
        if ($key === '' && $hash === '' && $signature === '' && $payloadHash === '' && $issuedAt === '') {
            return null;
        }
        $complete = $key !== '' && preg_match('/^[a-f0-9]{64}$/', $hash) && preg_match('/^[a-f0-9]{64}$/', $payloadHash) && $issuedAt !== '' && ($environment === 'local' || $signature !== '');
        if (! $complete) {
            throw new \RuntimeException('A partial invoice artifact was found; external effects were not repeated.');
        }
        $expected = InvoiceSignatureEnvelope::hash(array_merge($invoice, ['artifact_hash' => $hash, 'issued_at' => $issuedAt]));
        if (! hash_equals($expected, $payloadHash)) {
            throw new \RuntimeException('Invoice artifact signature envelope does not match its invoice.');
        }
        return ['artifact_key' => $key, 'artifact_hash' => $hash, 'signature_payload_hash' => $payloadHash, 'kms_signature' => $signature !== '' ? $signature : null, 'issued_at' => $issuedAt];
    }

    private function renderPdf(string $html): string
    {
        $renderer = getenv('E7_PROPOSTAS_RENDERER_URL');
        if (! is_string($renderer) || $renderer === '') {
            throw new \RuntimeException('Invoice Chromium renderer is not configured.');
        }
        $response = wp_remote_post($renderer, [
            'timeout' => 60,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode(['html' => $html, 'options' => ['printBackground' => true, 'format' => 'A4']]),
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            throw new \RuntimeException('Chromium invoice renderer failed.');
        }
        return (string) wp_remote_retrieve_body($response);
    }

    private function signHash(string $hash): string
    {
        $keyId = getenv('E7_PROPOSTAS_KMS_SIGNING_KEY_ID');
        $region = getenv('E7_AWS_REGION') ?: getenv('AWS_REGION');
        if (! is_string($keyId) || $keyId === '' || ! is_string($region) || $region === '') {
            throw new \RuntimeException('Invoice KMS signing is not configured.');
        }
        $result = (new KmsClient(['version' => 'latest', 'region' => $region]))->sign([
            'KeyId' => $keyId,
            'Message' => hex2bin($hash),
            'MessageType' => 'DIGEST',
            'SigningAlgorithm' => 'RSASSA_PSS_SHA_256',
        ]);
        return base64_encode((string) $result->get('Signature'));
    }

    private function verifyHash(string $hash, string $encodedSignature): bool
    {
        $keyId = getenv('E7_PROPOSTAS_KMS_SIGNING_KEY_ID');
        $region = getenv('E7_AWS_REGION') ?: getenv('AWS_REGION');
        $signature = base64_decode($encodedSignature, true);
        if (! is_string($keyId) || $keyId === '' || ! is_string($region) || $region === '' || $signature === false) {
            return false;
        }
        $result = (new KmsClient(['version' => 'latest', 'region' => $region]))->verify([
            'KeyId' => $keyId,
            'Message' => hex2bin($hash),
            'MessageType' => 'DIGEST',
            'Signature' => $signature,
            'SigningAlgorithm' => 'RSASSA_PSS_SHA_256',
        ]);
        return $result->get('SignatureValid') === true;
    }

    /** @return array{artifact_key: string, body: string, metadata: array<string, string>}|null */
    private function fetchStoredPdf(string $key): ?array
    {
        [$client, $bucket] = $this->s3();
        try {
            $head = $client->headObject(['Bucket' => $bucket, 'Key' => $key]);
        } catch (AwsException $error) {
            if ($error->getStatusCode() === 404 || in_array($error->getAwsErrorCode(), ['NoSuchKey', 'NotFound'], true)) {
                return null;
            }
            throw $error;
        }
        $object = $client->getObject(['Bucket' => $bucket, 'Key' => $key]);
        $metadata = $head->get('Metadata');
        return [
            'artifact_key' => $key,
            'body' => (string) $object->get('Body'),
            'metadata' => is_array($metadata) ? $metadata : [],
        ];
    }

    /** @param array<string, string> $metadata */
    private function storePdf(string $key, string $pdf, array $metadata): string
    {
        [$client, $bucket] = $this->s3();
        $retentionYears = filter_var(getenv('E7_PROPOSTAS_RETENTION_YEARS') ?: '7', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]);
        if (! is_int($retentionYears)) {
            throw new \RuntimeException('Invoice artifact storage is not configured.');
        }
        $client->putObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'Body' => $pdf,
            'IfNoneMatch' => '*',
            'Metadata' => $metadata,
            'ContentType' => 'application/pdf',
            'ServerSideEncryption' => 'aws:kms',
            'ObjectLockMode' => 'GOVERNANCE',
            'ObjectLockRetainUntilDate' => gmdate(DATE_ATOM, strtotime('+' . $retentionYears . ' years')),
        ]);
        return $key;
    }

    /** @return array{S3Client, string} */
    private function s3(): array
    {
        $bucket = getenv('E7_PROPOSTAS_S3_BUCKET');
        $region = getenv('E7_AWS_REGION') ?: getenv('AWS_REGION');
        if (! is_string($bucket) || $bucket === '' || ! is_string($region) || $region === '') {
            throw new \RuntimeException('Invoice artifact storage is not configured.');
        }
        return [new S3Client(['version' => 'latest', 'region' => $region]), $bucket];
    }

    private function writeLocalHtml(string $publicId, string $html): string
    {
        $directory = trailingslashit(dirname(ABSPATH)) . 'e7-propostas-private/invoices';
        if (! wp_mkdir_p($directory)) {
            throw new \RuntimeException('Private local invoice directory could not be created.');
        }
        $path = $directory . '/' . $publicId . '.html';
        if (file_put_contents($path, $html, LOCK_EX) === false) {
            throw new \RuntimeException('Local invoice artifact could not be written.');
        }
        return $path;
    }
}
