<?php

declare(strict_types=1);

namespace E7Propostas\Infrastructure;

use Aws\Kms\KmsClient;

final class ArtifactVerifier
{
    private readonly \Closure $signatureVerifier;

    public function __construct(?callable $signatureVerifier = null)
    {
        $this->signatureVerifier = \Closure::fromCallable($signatureVerifier ?? [$this, 'verifyWithKms']);
    }

    /** @param array<string, mixed> $version */
    public function verify(array $version): bool
    {
        return $this->verifyEvidence($version);
    }

    /** @param array<string, mixed> $invoice */
    public function verifyInvoice(array $invoice): bool
    {
        return $this->verifyEvidence($invoice);
    }

    /** @param array<string, mixed> $evidence */
    private function verifyEvidence(array $evidence): bool
    {
        $hash = (string) ($evidence['artifact_hash'] ?? '');
        $encodedSignature = (string) ($evidence['kms_signature'] ?? '');
        if (! preg_match('/^[a-f0-9]{64}$/', $hash) || $encodedSignature === '') {
            return false;
        }
        try {
            return (bool) ($this->signatureVerifier)($hash, $encodedSignature);
        } catch (\Throwable) {
            return false;
        }
    }

    private function verifyWithKms(string $hash, string $encodedSignature): bool
    {
        $keyId = getenv('E7_PROPOSTAS_KMS_SIGNING_KEY_ID');
        $region = getenv('E7_AWS_REGION') ?: getenv('AWS_REGION');
        if (! is_string($keyId) || $keyId === '' || ! is_string($region) || $region === '') {
            return false;
        }
        $signature = base64_decode($encodedSignature, true);
        if ($signature === false) {
            return false;
        }
        $cacheKey = 'e7_kms_verify_' . hash('sha256', $keyId . '|' . $hash . '|' . $encodedSignature);
        $cached = get_transient($cacheKey);
        if ($cached === '1' || $cached === '0') {
            return $cached === '1';
        }
        $kms = new KmsClient(['version' => 'latest', 'region' => $region]);
        $result = $kms->verify([
            'KeyId' => $keyId,
            'Message' => hex2bin($hash),
            'MessageType' => 'DIGEST',
            'Signature' => $signature,
            'SigningAlgorithm' => 'RSASSA_PSS_SHA_256',
        ]);
        $valid = $result->get('SignatureValid') === true;
        set_transient($cacheKey, $valid ? '1' : '0', DAY_IN_SECONDS);
        return $valid;
    }
}
