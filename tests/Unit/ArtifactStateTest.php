<?php

declare(strict_types=1);

namespace E7Propostas\Tests\Unit;

use E7Propostas\Infrastructure\ArtifactState;
use E7Propostas\Infrastructure\FinalEmailState;
use PHPUnit\Framework\TestCase;

final class ArtifactStateTest extends TestCase
{
    public function test_persisted_artifact_prevents_a_second_generation_and_upload(): void
    {
        self::assertTrue(class_exists(ArtifactState::class), 'ArtifactState must exist.');
        $version = ['artifact_key' => 'proposals/abc.pdf#version-1', 'artifact_hash' => str_repeat('a', 64), 'kms_signature' => 'signed'];
        self::assertTrue(ArtifactState::isPersisted($version));
        self::assertSame('complete', ArtifactState::state($version));
        self::assertFalse(ArtifactState::shouldGenerate($version));
    }

    public function test_absent_artifact_state_is_generated(): void
    {
        self::assertTrue(class_exists(ArtifactState::class), 'ArtifactState must exist.');
        $version = ['artifact_key' => null, 'artifact_hash' => null, 'kms_signature' => null];
        self::assertSame('absent', ArtifactState::state($version));
        self::assertTrue(ArtifactState::shouldGenerate($version));
    }

    public function test_partial_artifact_state_fails_closed_instead_of_regenerating(): void
    {
        self::assertTrue(class_exists(ArtifactState::class), 'ArtifactState must exist.');
        $version = ['artifact_key' => 'proposals/abc.pdf#version-1', 'artifact_hash' => str_repeat('a', 64), 'kms_signature' => ''];
        self::assertSame('partial', ArtifactState::state($version));
        $this->expectException(\RuntimeException::class);
        ArtifactState::shouldGenerate($version);
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

    public function test_processor_uses_a_persistent_claim_before_ses(): void
    {
        $root = dirname(__DIR__, 2);
        $processor = file_get_contents($root . '/src/Infrastructure/ArtifactProcessor.php');
        $repository = file_get_contents($root . '/src/WordPress/ProposalRepository.php');
        self::assertIsString($processor);
        self::assertIsString($repository);
        self::assertStringContainsString('claimFinalEmail', $processor);
        self::assertStringContainsString("'final_email.claimed'", $repository);
        self::assertLessThan(strpos($processor, '$this->sendFinalEmail'), strpos($processor, 'claimFinalEmail'));
    }

    public function test_absent_final_email_state_can_be_claimed_only_once(): void
    {
        self::assertTrue(class_exists(FinalEmailState::class), 'FinalEmailState must exist.');
        $state = FinalEmailState::fromEvents([]);
        self::assertTrue($state->claim());
        self::assertFalse($state->claim());
        self::assertSame('claimed', $state->value());
    }

    public function test_claimed_or_sent_final_email_state_skips_a_new_claim(): void
    {
        self::assertTrue(class_exists(FinalEmailState::class), 'FinalEmailState must exist.');
        $claimed = FinalEmailState::fromEvents([['event_type' => 'final_email.claimed']]);
        $sent = FinalEmailState::fromEvents([['event_type' => 'final_email.sent']]);
        self::assertFalse($claimed->claim());
        self::assertFalse($sent->claim());
        self::assertSame('sent', $sent->value());
    }
}
