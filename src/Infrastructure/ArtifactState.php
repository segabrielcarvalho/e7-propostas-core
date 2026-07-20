<?php

declare(strict_types=1);

namespace E7Propostas\Infrastructure;

final class ArtifactState
{
    /** @param array<string, mixed> $version */
    public static function isPersisted(array $version): bool
    {
        return trim((string) ($version['artifact_key'] ?? '')) !== ''
            && preg_match('/^[a-f0-9]{64}$/', (string) ($version['artifact_hash'] ?? '')) === 1
            && trim((string) ($version['kms_signature'] ?? '')) !== '';
    }

    /** @param array<string, mixed> $version */
    public static function shouldGenerate(array $version): bool
    {
        return ! self::isPersisted($version);
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
