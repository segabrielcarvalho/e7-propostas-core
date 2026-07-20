<?php

declare(strict_types=1);

namespace E7Propostas\Infrastructure;

use Aws\Kms\KmsClient;
use Aws\S3\S3Client;

final class InvoiceFinalizer
{
    private readonly \Closure $environment;
    private readonly \Closure $pdfRenderer;
    private readonly \Closure $signer;
    private readonly \Closure $objectWriter;
    private readonly \Closure $localWriter;
    private readonly \Closure $verificationUrl;

    public function __construct(
        private readonly InvoiceHtmlRenderer $htmlRenderer = new InvoiceHtmlRenderer(),
        ?callable $environment = null,
        ?callable $pdfRenderer = null,
        ?callable $signer = null,
        ?callable $objectWriter = null,
        ?callable $localWriter = null,
        ?callable $verificationUrl = null,
    ) {
        $this->environment = \Closure::fromCallable($environment ?? static fn (): string => function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production');
        $this->pdfRenderer = \Closure::fromCallable($pdfRenderer ?? [$this, 'renderPdf']);
        $this->signer = \Closure::fromCallable($signer ?? [$this, 'signHash']);
        $this->objectWriter = \Closure::fromCallable($objectWriter ?? [$this, 'storePdf']);
        $this->localWriter = \Closure::fromCallable($localWriter ?? [$this, 'writeLocalHtml']);
        $this->verificationUrl = \Closure::fromCallable($verificationUrl ?? static fn (string $publicId): string => function_exists('home_url') ? home_url('/invoice/verify/' . $publicId . '/') : 'https://localhost/invoice/verify/' . $publicId . '/');
    }

    /** @param array<string, mixed> $invoice @return array{artifact_key: string, artifact_hash: string, kms_signature: ?string} */
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
        $url = (string) ($this->verificationUrl)($publicId);
        $html = $this->htmlRenderer->render($invoice, $url);

        if ($environment === 'local') {
            $path = (string) ($this->localWriter)($publicId, $html);
            if ($path === '') {
                throw new \RuntimeException('Local invoice artifact was not persisted.');
            }
            return ['artifact_key' => $path, 'artifact_hash' => hash('sha256', $html), 'kms_signature' => null];
        }

        $pdf = (string) ($this->pdfRenderer)($html);
        if (! str_starts_with($pdf, '%PDF-')) {
            throw new \RuntimeException('Renderer did not return an invoice PDF.');
        }
        $hash = hash('sha256', $pdf);
        $signature = (string) ($this->signer)($hash);
        if ($signature === '') {
            throw new \RuntimeException('KMS did not return an invoice signature.');
        }
        $key = 'invoices/' . $publicId . '.pdf';
        $artifactKey = (string) ($this->objectWriter)($key, $pdf);
        if ($artifactKey === '') {
            throw new \RuntimeException('Invoice artifact storage did not return an object key.');
        }
        return ['artifact_key' => $artifactKey, 'artifact_hash' => $hash, 'kms_signature' => $signature];
    }

    /** @param array<string, mixed> $invoice @return array{artifact_key: string, artifact_hash: string, kms_signature: ?string}|null */
    private function existingArtifact(array $invoice, string $environment): ?array
    {
        $key = trim((string) ($invoice['artifact_key'] ?? ''));
        $hash = strtolower(trim((string) ($invoice['artifact_hash'] ?? '')));
        $signature = trim((string) ($invoice['kms_signature'] ?? ''));
        if ($key === '' && $hash === '' && $signature === '') {
            return null;
        }
        $complete = $key !== '' && preg_match('/^[a-f0-9]{64}$/', $hash) && ($environment === 'local' || $signature !== '');
        if (! $complete) {
            throw new \RuntimeException('A partial invoice artifact was found; external effects were not repeated.');
        }
        return ['artifact_key' => $key, 'artifact_hash' => $hash, 'kms_signature' => $signature !== '' ? $signature : null];
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

    private function storePdf(string $key, string $pdf): string
    {
        $bucket = getenv('E7_PROPOSTAS_S3_BUCKET');
        $region = getenv('E7_AWS_REGION') ?: getenv('AWS_REGION');
        $retentionYears = filter_var(getenv('E7_PROPOSTAS_RETENTION_YEARS') ?: '7', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]);
        if (! is_string($bucket) || $bucket === '' || ! is_string($region) || $region === '' || ! is_int($retentionYears)) {
            throw new \RuntimeException('Invoice artifact storage is not configured.');
        }
        $result = (new S3Client(['version' => 'latest', 'region' => $region]))->putObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'Body' => $pdf,
            'ContentType' => 'application/pdf',
            'ServerSideEncryption' => 'aws:kms',
            'ObjectLockMode' => 'GOVERNANCE',
            'ObjectLockRetainUntilDate' => gmdate(DATE_ATOM, strtotime('+' . $retentionYears . ' years')),
        ]);
        return $key . '#' . (string) ($result->get('VersionId') ?? '');
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
