<?php

declare(strict_types=1);

namespace E7Propostas\Domain;

final class SnapshotHasher
{
    /** @param array<string, mixed> $metadata */
    public function create(string $html, array $metadata): ProposalSnapshot
    {
        $payload = $this->normalize([
            'html' => $html,
            'metadata' => $metadata,
        ]);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return new ProposalSnapshot($json, hash('sha256', $json));
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($value !== [] && ! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
