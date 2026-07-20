<?php

declare(strict_types=1);

namespace E7Propostas\Tests\Unit;

use E7Propostas\Infrastructure\ArtifactState;
use PHPUnit\Framework\TestCase;

final class ArtifactStateTest extends TestCase
{
    public function test_persisted_artifact_prevents_a_second_generation_and_upload(): void
    {
        self::assertTrue(class_exists(ArtifactState::class), 'ArtifactState must exist.');
        $version = ['artifact_key' => 'proposals/abc.pdf#version-1', 'artifact_hash' => str_repeat('a', 64), 'kms_signature' => 'signed'];
        self::assertTrue(ArtifactState::isPersisted($version));
        self::assertFalse(ArtifactState::shouldGenerate($version));
    }

    public function test_partial_artifact_state_is_not_treated_as_persisted(): void
    {
        self::assertTrue(class_exists(ArtifactState::class), 'ArtifactState must exist.');
        self::assertFalse(ArtifactState::isPersisted(['artifact_key' => 'proposals/abc.pdf#version-1', 'artifact_hash' => str_repeat('a', 64), 'kms_signature' => '']));
    }

    public function test_audit_events_make_finalization_and_email_delivery_replay_safe(): void
    {
        self::assertTrue(class_exists(ArtifactState::class), 'ArtifactState must exist.');
        $events = [
            ['event_type' => 'artifact.finalized', 'payload' => '{"artifact_hash":"abc"}'],
            ['event_type' => 'final_email.sent', 'payload' => '{"provider_message_id":"ses-123"}'],
        ];
        self::assertTrue(ArtifactState::hasEvent($events, 'artifact.finalized'));
        self::assertSame('ses-123', ArtifactState::providerMessageId($events));
    }
}
