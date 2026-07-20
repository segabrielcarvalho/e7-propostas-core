<?php

declare(strict_types=1);

namespace E7Propostas\Infrastructure;

final class ArtifactState
{
    public const ABSENT = 'absent';
    public const COMPLETE = 'complete';
    public const PARTIAL = 'partial';

    /** @param array<string, mixed> $version */
    public static function state(array $version): string
    {
        $key = trim((string) ($version['artifact_key'] ?? ''));
        $hash = trim((string) ($version['artifact_hash'] ?? ''));
        $signature = trim((string) ($version['kms_signature'] ?? ''));

        if ($key === '' && $hash === '' && $signature === '') {
            return self::ABSENT;
        }
        if ($key !== '' && preg_match('/^[a-f0-9]{64}$/', $hash) === 1 && $signature !== '') {
            return self::COMPLETE;
        }
        return self::PARTIAL;
    }

    /** @param array<string, mixed> $version */
    public static function isPersisted(array $version): bool
    {
        return self::state($version) === self::COMPLETE;
    }

    /** @param array<string, mixed> $version */
    public static function shouldGenerate(array $version): bool
    {
        return match (self::state($version)) {
            self::ABSENT => true,
            self::COMPLETE => false,
            self::PARTIAL => throw new \RuntimeException('Artifact state is partial and cannot be regenerated safely.'),
        };
    }

    /** @param list<array<string, mixed>> $events */
    public static function hasEvent(array $events, string $type): bool
    {
        foreach ($events as $event) {
            if (($event['event_type'] ?? null) === $type) {
                return true;
            }
        }
        return false;
    }

    /** @param list<array<string, mixed>> $events */
    public static function providerMessageId(array $events): ?string
    {
        foreach (array_reverse($events) as $event) {
            if (($event['event_type'] ?? null) !== 'final_email.sent') {
                continue;
            }
            try {
                $payload = json_decode((string) ($event['payload'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            $providerId = is_array($payload) ? trim((string) ($payload['provider_message_id'] ?? '')) : '';
            if ($providerId !== '') {
                return $providerId;
            }
        }
        return null;
    }
}
