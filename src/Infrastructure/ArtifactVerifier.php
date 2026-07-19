<?php

declare(strict_types=1);

namespace E7Propostas\Infrastructure;

use Aws\Kms\KmsClient;

final class ArtifactVerifier
{
    /** @param array<string, mixed> $version */
    public function verify(array $version): bool
    {
        $hash = (string) ($version['artifact_hash'] ?? '');
        $encodedSignature = (string) ($version['kms_signature'] ?? '');
        $keyId = getenv('E7_PROPOSTAS_KMS_SIGNING_KEY_ID');
        $region = getenv('E7_AWS_REGION') ?: getenv('AWS_REGION');
        if (! preg_match('/^[a-f0-9]{64}$/', $hash) || $encodedSignature === '' || ! is_string($keyId) || $keyId === '' || ! is_string($region) || $region === '') {
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
        try {
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
        } catch (\Throwable) {
            return false;
        }
    }
}
