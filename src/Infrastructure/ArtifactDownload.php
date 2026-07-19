<?php

declare(strict_types=1);

namespace E7Propostas\Infrastructure;

use Aws\S3\S3Client;

final class ArtifactDownload
{
    /** @param array<string, mixed> $version */
    public function serve(array $version, string $publicId): never
    {
        $artifactKey = (string) ($version['artifact_key'] ?? '');
        $expectedHash = (string) ($version['artifact_hash'] ?? '');
        if ($artifactKey === '' || ! preg_match('/^[a-f0-9]{64}$/', $expectedHash)) {
            wp_die(esc_html__('O arquivo final ainda está sendo preparado.', 'e7-propostas'), '', ['response' => 409]);
        }
        $isLocal = wp_get_environment_type() === 'local';
        if ($isLocal) {
            $privateRoot = realpath(trailingslashit(dirname(ABSPATH)) . 'e7-propostas-private');
            $resolved = realpath($artifactKey);
            if ($privateRoot === false || $resolved === false || ! str_starts_with($resolved, $privateRoot . DIRECTORY_SEPARATOR)) {
                wp_die(esc_html__('Arquivo final indisponível.', 'e7-propostas'), '', ['response' => 404]);
            }
            $contents = file_get_contents($resolved);
            $contentType = 'text/html; charset=UTF-8';
            $extension = 'html';
        } else {
            [$key, $versionId] = array_pad(explode('#', $artifactKey, 2), 2, '');
            $bucket = getenv('E7_PROPOSTAS_S3_BUCKET');
            $region = getenv('E7_AWS_REGION') ?: getenv('AWS_REGION');
            if (! is_string($bucket) || $bucket === '' || ! is_string($region) || $region === '') {
                wp_die(esc_html__('Armazenamento final indisponível.', 'e7-propostas'), '', ['response' => 503]);
            }
            $arguments = ['Bucket' => $bucket, 'Key' => $key];
            if ($versionId !== '') {
                $arguments['VersionId'] = $versionId;
            }
            $object = (new S3Client(['version' => 'latest', 'region' => $region]))->getObject($arguments);
            $contents = (string) $object->get('Body');
            $contentType = 'application/pdf';
            $extension = 'pdf';
        }
        if (! is_string($contents) || ! hash_equals($expectedHash, hash('sha256', $contents))) {
            wp_die(esc_html__('A verificação de integridade do arquivo falhou.', 'e7-propostas'), '', ['response' => 409]);
        }
        nocache_headers();
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="e7-proposal-' . $publicId . '.' . $extension . '"');
        header('Content-Length: ' . strlen($contents));
        echo $contents;
        exit;
    }
}
