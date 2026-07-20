<?php

declare(strict_types=1);

namespace E7Propostas\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AcceptanceContractTest extends TestCase
{
    public function test_reopening_an_accepted_proposal_keeps_the_full_document_screen(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/PublicRoutes.php');

        self::assertIsString($routes);
        self::assertStringContainsString("'screen' => \$authorized ? 'proposal' : 'password'", $routes);
        self::assertStringNotContainsString("? ((string) \$version['status'] === 'accepted' ? 'complete' : 'proposal')", $routes);
        self::assertStringContainsString("'acceptance' => \$authorized ? \$acceptance : null", $routes);
    }

    public function testRendererUsesTheOfficialBrowserlessPdfPayload(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/Infrastructure/ArtifactProcessor.php');

        self::assertIsString($source);
        self::assertStringContainsString("'options' => ['printBackground' => true, 'format' => 'A4']", $source);
        self::assertStringNotContainsString("'javascript' => false", $source);
        self::assertStringNotContainsString("'network' => false", $source);
    }

    public function test_acceptance_consumes_the_otp_inside_the_version_transaction(): void
    {
        $repository = file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/ProposalRepository.php');

        self::assertIsString($repository);
        self::assertStringContainsString('FOR UPDATE', $repository);
        self::assertStringContainsString('consumed_at IS NULL', $repository);
        self::assertStringContainsString('Could not consume OTP atomically', $repository);
    }

    public function test_idempotency_is_checked_before_otp_verification(): void
    {
        $controller = file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/RestController.php');

        self::assertIsString($controller);
        $acceptPosition = strpos($controller, 'public function accept');
        self::assertNotFalse($acceptPosition);
        $acceptController = substr($controller, $acceptPosition);
        $idempotencyPosition = strpos($acceptController, 'findAcceptanceByIdempotency');
        $otpPosition = strpos($acceptController, 'latestOtp');
        self::assertNotFalse($idempotencyPosition);
        self::assertNotFalse($otpPosition);
        self::assertLessThan($otpPosition, $idempotencyPosition);
    }

    public function test_local_evidence_is_written_outside_the_public_root(): void
    {
        $processor = file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/ArtifactProcessor.php');

        self::assertIsString($processor);
        self::assertStringContainsString("dirname(ABSPATH)", $processor);
        self::assertStringNotContainsString("wp_upload_dir()", $processor);
    }

    public function test_final_evidence_uses_the_acceptance_contacts_and_the_immutable_snapshot(): void
    {
        $processor = file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/ArtifactProcessor.php');

        self::assertIsString($processor);
        self::assertStringContainsString("snapshot_json", $processor);
        self::assertStringContainsString("canonical snapshot manifest", $processor);
        self::assertStringNotContainsString("getSettings((int) \$record['version']['post_id'])", $processor);
        foreach (['signer_email', 'signer_phone', 'signer_role', 'signer_company', 'ip_address', 'user_agent', 'audit_events'] as $field) {
            self::assertStringContainsString($field, $processor);
        }
        self::assertStringContainsString("\$record['acceptance']['signer_email']", $processor);
        self::assertStringContainsString("\$settings['copy_email']", $processor);
    }

    public function test_accepted_posts_are_locked_and_database_writes_are_checked(): void
    {
        $repository = file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/ProposalRepository.php');
        $plugin = file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Plugin.php');

        self::assertIsString($repository);
        self::assertIsString($plugin);
        self::assertStringContainsString('isAcceptedPost', $repository);
        self::assertStringContainsString("add_filter('map_meta_cap'", $plugin);
        self::assertStringContainsString('Database write failed', $repository);
        self::assertStringContainsString("['id' => \$versionId, 'status' => 'active']", $repository);
        self::assertStringContainsString("appendAudit(\$versionId, 'otp.verified', ['otp_id' => \$otpId], true)", $repository);
        self::assertStringContainsString('releaseAuditLock', $repository);
    }

    public function test_block_editor_context_is_never_cast_to_a_post_id(): void
    {
        $plugin = file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Plugin.php');

        self::assertIsString($plugin);
        self::assertStringContainsString('is_int($candidate)', $plugin);
        self::assertStringContainsString("is_string(\$candidate) && ctype_digit(\$candidate)", $plugin);
        self::assertStringNotContainsString('get_post((int) $args[0])', $plugin);
    }

    public function test_expiration_is_rechecked_and_jobs_have_a_recoverable_lease(): void
    {
        $repository = file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/ProposalRepository.php');
        $controller = file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/RestController.php');
        $processor = file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/ArtifactProcessor.php');

        self::assertIsString($repository);
        self::assertIsString($controller);
        self::assertIsString($processor);
        self::assertStringContainsString('isVersionExpired', $repository);
        self::assertStringContainsString('isVersionExpired', $controller);
        self::assertStringContainsString("status='processing' AND locked_at <", $processor);
        self::assertStringContainsString('provider_message_id', $processor);
        self::assertStringContainsString("getenv('E7_PROPOSTAS_RETENTION_YEARS')", $processor);
    }

    public function test_public_verification_checks_the_kms_signature_and_artifact_hash(): void
    {
        $verifier = file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/ArtifactVerifier.php');
        $controller = file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/RestController.php');

        self::assertIsString($verifier);
        self::assertIsString($controller);
        self::assertStringContainsString('RSASSA_PSS_SHA_256', $verifier);
        self::assertStringContainsString("'SignatureValid'", $verifier);
        self::assertStringContainsString("'artifact_hash'", $controller);
        self::assertStringContainsString("'signature_verified'", $controller);
    }

    public function test_public_kms_verification_is_cached_by_immutable_signature_material(): void
    {
        $verifier = file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/ArtifactVerifier.php');

        self::assertIsString($verifier);
        self::assertStringContainsString('get_transient', $verifier);
        self::assertStringContainsString('set_transient', $verifier);
        self::assertStringContainsString("'e7_kms_verify_'", $verifier);
        self::assertStringContainsString('DAY_IN_SECONDS', $verifier);
    }

    public function test_otp_is_bound_to_the_editable_requested_contact_and_persisted_destination(): void
    {
        $controller = file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/RestController.php');
        $repository = file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/ProposalRepository.php');
        $installer = file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Installer.php');

        self::assertIsString($controller);
        self::assertIsString($repository);
        self::assertIsString($installer);
        self::assertStringContainsString('$destination = OtpDestination::from(', $controller);
        self::assertStringNotContainsString('configuredOtpDestination', $controller);
        self::assertStringNotContainsString('$configuredEmail', $controller);
        self::assertStringNotContainsString('$configuredPhone', $controller);
        self::assertStringContainsString('assertOtpContactBinding', $controller);
        self::assertStringContainsString("'destination' => \$destination", $repository);
        self::assertStringContainsString('destination varchar(254)', $installer);
    }

    public function test_non_irish_phone_compatibility_is_delegated_to_the_otp_policy(): void
    {
        $controller = file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/RestController.php');

        self::assertIsString($controller);
        self::assertStringContainsString('AcceptancePolicy::phoneRequiredAtSubmission', $controller);
        self::assertStringContainsString("OtpDestination::from('sms', \$phoneRaw)->value", $controller);
        self::assertStringContainsString("BusinessProfile::normalize(\$request->get_param('business_profile'))", $controller);
        self::assertStringContainsString("\$channel === 'email'", $controller);
        self::assertStringContainsString("\$channel === 'sms'", $controller);
    }

    public function test_sms_uses_an_application_specific_sender_id(): void
    {
        $delivery = file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/DeliveryService.php');

        self::assertIsString($delivery);
        self::assertStringContainsString("getenv('E7_SNS_SENDER_ID')", $delivery);
        self::assertStringContainsString("'AWS.SNS.SMS.SenderID'", $delivery);
    }

    public function test_transactional_emails_send_the_branded_html_with_a_plain_text_fallback(): void
    {
        $delivery = file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/DeliveryService.php');
        $processor = file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/ArtifactProcessor.php');

        self::assertIsString($delivery);
        self::assertIsString($processor);
        self::assertStringContainsString('EmailTemplate::otp', $delivery);
        self::assertStringContainsString("'Html' =>", $delivery);
        self::assertStringContainsString("'Text' =>", $delivery);
        self::assertStringContainsString('EmailTemplate::finalCopy', $processor);
        self::assertStringContainsString('multipart/alternative', $processor);
        self::assertStringContainsString('text/html; charset=UTF-8', $processor);
    }

    public function test_otp_send_limits_survive_session_recreation_and_attempts_are_serialized(): void
    {
        $controller = file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/RestController.php');
        $repository = file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/ProposalRepository.php');

        self::assertIsString($controller);
        self::assertIsString($repository);
        self::assertStringContainsString("'otp-version|'", $controller);
        self::assertStringContainsString("'otp-destination|'", $controller);
        self::assertStringContainsString('withOtpLock', $controller);
        self::assertStringContainsString("SELECT GET_LOCK", $repository);
        self::assertStringContainsString("SELECT RELEASE_LOCK", $repository);
    }

    public function test_final_artifact_has_an_authenticated_integrity_checked_download(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/PublicRoutes.php');
        $download = file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/ArtifactDownload.php');

        self::assertIsString($routes);
        self::assertIsString($download);
        self::assertStringContainsString("^download/([a-f0-9]{32})/?$", $routes);
        self::assertStringContainsString('findSession', $routes);
        self::assertStringContainsString('hash_equals', $download);
        self::assertStringContainsString('Content-Disposition', $download);
    }
}
