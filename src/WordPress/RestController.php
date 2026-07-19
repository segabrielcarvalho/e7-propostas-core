<?php

declare(strict_types=1);

namespace E7Propostas\WordPress;

use DateTimeImmutable;
use DateTimeZone;
use E7Propostas\Domain\OtpChallenge;
use E7Propostas\Domain\OtpDestination;
use E7Propostas\Domain\OtpService;
use E7Propostas\Domain\PasswordService;
use E7Propostas\Infrastructure\DeliveryService;
use E7Propostas\Infrastructure\ArtifactVerifier;

final class RestController
{
    private const NAMESPACE = 'e7-propostas/v1';

    public function __construct(
        private readonly ProposalRepository $repository,
        private readonly PasswordService $passwords,
        private readonly OtpService $otps,
        private readonly DeliveryService $delivery,
        private readonly ArtifactVerifier $artifactVerifier,
    ) {
    }

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/access/password', ['methods' => 'POST', 'callback' => [$this, 'access'], 'permission_callback' => [$this, 'sameOrigin']]);
        register_rest_route(self::NAMESPACE, '/otp/send', ['methods' => 'POST', 'callback' => [$this, 'sendOtp'], 'permission_callback' => [$this, 'authorizedRequest']]);
        register_rest_route(self::NAMESPACE, '/otp/verify', ['methods' => 'POST', 'callback' => [$this, 'verifyOtp'], 'permission_callback' => [$this, 'authorizedRequest']]);
        register_rest_route(self::NAMESPACE, '/accept', ['methods' => 'POST', 'callback' => [$this, 'accept'], 'permission_callback' => [$this, 'authorizedRequest']]);
        register_rest_route(self::NAMESPACE, '/verify/(?P<document_id>[a-f0-9]{32})', ['methods' => 'GET', 'callback' => [$this, 'verify'], 'permission_callback' => '__return_true']);
    }

    public function sameOrigin(\WP_REST_Request $request): bool|\WP_Error
    {
        $origin = (string) $request->get_header('origin');
        if ($origin === '' || rtrim($origin, '/') === rtrim(home_url(), '/')) {
            return true;
        }
        return new \WP_Error('e7_origin', __('Solicitação inválida.', 'e7-propostas'), ['status' => 403]);
    }

    public function authorizedRequest(\WP_REST_Request $request): bool|\WP_Error
    {
        $origin = $this->sameOrigin($request);
        if (is_wp_error($origin)) {
            return $origin;
        }
        $session = self::sessionCookie();
        $csrf = $request->get_header('x-e7-csrf');
        if ($session === '' || $csrf === '' || ! hash_equals(self::csrfFor($session), $csrf) || ! is_array($this->repository->findSession($session))) {
            return new \WP_Error('e7_session', __('Sessão inválida ou expirada.', 'e7-propostas'), ['status' => 403]);
        }
        return true;
    }

    public function access(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $code = strtolower(sanitize_text_field((string) $request->get_param('code')));
        $password = (string) $request->get_param('password');
        $version = $this->repository->findCurrentByShareCode($code);
        $ip = $this->clientIp();
        $scope = $code . '|' . $ip;
        $ipScope = 'ip|' . $ip;
        if ($this->repository->isRateBlocked($scope) || $this->repository->isRateBlocked($ipScope)) {
            return $this->genericError(429);
        }
        if (! is_array($version)) {
            $this->repository->registerRateFailure($scope);
            $this->repository->registerRateFailure($ipScope, 20, HOUR_IN_SECONDS);
            return $this->genericError();
        }
        $settings = $this->repository->getSettings((int) $version['post_id']);
        if (! $this->passwords->verify($password, (string) ($settings['password_hash'] ?? ''))) {
            $this->repository->registerRateFailure($scope);
            $this->repository->registerRateFailure($ipScope, 20, HOUR_IN_SECONDS);
            $this->repository->appendAudit((int) $version['id'], 'access.denied', ['ip' => $ip]);
            return $this->genericError();
        }
        $this->repository->clearRate($scope);
        $session = $this->repository->createSession((int) $version['id']);
        $this->setSessionCookie($session);
        $this->repository->appendAudit((int) $version['id'], 'access.authorized', ['ip' => $ip, 'user_agent' => $this->userAgent()]);
        return new \WP_REST_Response(['ok' => true], 200);
    }

    public function sendOtp(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $sessionRaw = self::sessionCookie();
        $session = $this->repository->findSession($sessionRaw);
        if (! is_array($session)) {
            return $this->genericError(403);
        }
        $version = $this->repository->getVersion((int) $session['version_id']);
        if (! is_array($version) || $version['status'] !== 'active' || $this->repository->isVersionExpired($version)) {
            return $this->genericError(409);
        }
        $settings = $this->repository->getSettings((int) $version['post_id']);
        try {
            $requestedDestination = OtpDestination::from(
                (string) $request->get_param('channel'),
                (string) $request->get_param('destination'),
            );
            $destination = $this->configuredOtpDestination($settings, $requestedDestination);
        } catch (\InvalidArgumentException) {
            return new \WP_Error('e7_otp_destination', __('Informe um e-mail ou telefone válido.', 'e7-propostas'), ['status' => 422]);
        }
        $versionScope = 'otp-version|' . (int) $version['id'] . '|' . $this->clientIp();
        $destinationScope = 'otp-destination|' . (int) $version['id'] . '|' . $destination->channel . '|' . strtolower($destination->value);
        if ($this->repository->isRateBlocked($versionScope) || $this->repository->isRateBlocked($destinationScope)) {
            return new \WP_Error('e7_otp_limit', __('Limite de reenvios atingido. Tente novamente mais tarde.', 'e7-propostas'), ['status' => 429]);
        }
        try {
            return $this->repository->withOtpSendLock((int) $version['id'], function () use ($session, $version, $settings, $destination, $versionScope, $destinationScope): \WP_REST_Response|\WP_Error {
                if ($this->repository->isRateBlocked($versionScope) || $this->repository->isRateBlocked($destinationScope)) {
                    return new \WP_Error('e7_otp_limit', __('Limite de reenvios atingido. Tente novamente mais tarde.', 'e7-propostas'), ['status' => 429]);
                }
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $issued = $this->otps->issue($now);
                $delivery = $this->delivery->sendOtp($destination->channel, $issued->code, $destination->value, (string) ($settings['locale'] ?? 'pt_BR'));
                $this->repository->saveOtp((int) $session['id'], (int) $version['id'], $destination->channel, $destination->value, $issued->challenge->codeHash, $issued->challenge->expiresAt->format('Y-m-d H:i:s'), $delivery['id']);
                $this->repository->registerRateFailure($versionScope, 5, HOUR_IN_SECONDS);
                $this->repository->registerRateFailure($destinationScope, 3, HOUR_IN_SECONDS);
                $this->repository->appendAudit((int) $version['id'], 'otp.sent', ['channel' => $destination->channel, 'provider_message_id' => $delivery['id']]);
                $response = ['ok' => true, 'channel' => $destination->channel, 'expires_in' => 600];
                if (isset($delivery['debug_code']) && wp_get_environment_type() === 'local') {
                    $response['dev_code'] = $delivery['debug_code'];
                }
                return new \WP_REST_Response($response, 200);
            });
        } catch (\Throwable $error) {
            return new \WP_Error('e7_delivery_unavailable', __('Não foi possível enviar o código. Tente novamente.', 'e7-propostas'), ['status' => 503]);
        }
    }

    public function verifyOtp(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $session = $this->repository->findSession(self::sessionCookie());
        if (! is_array($session)) {
            return $this->genericError(403);
        }
        $version = $this->repository->getVersion((int) $session['version_id']);
        if (! is_array($version) || $version['status'] !== 'active' || $this->repository->isVersionExpired($version)) {
            return $this->genericError(409);
        }
        try {
            return $this->repository->withOtpLock((int) $session['version_id'], function () use ($session, $request): \WP_REST_Response|\WP_Error {
                $otp = $this->repository->latestOtp((int) $session['id'], (int) $session['version_id']);
                if (! is_array($otp)) {
                    return new \WP_Error('e7_otp_required', __('Solicite o código antes de continuar.', 'e7-propostas'), ['status' => 409]);
                }
                $verification = $this->verifyChallenge($otp, (string) $request->get_param('otp'));
                if (! $verification->isValid) {
                    if ($verification->reason === 'invalid' || $verification->reason === 'locked') {
                        $this->repository->recordOtpFailure((int) $otp['id'], (int) $otp['attempts']);
                    }
                    $this->repository->appendAudit((int) $session['version_id'], 'otp.denied', ['reason' => $verification->reason, 'attempts' => $verification->challenge->attempts]);
                    return new \WP_Error('e7_otp_invalid', __('Código inválido ou expirado.', 'e7-propostas'), ['status' => 422]);
                }
                return new \WP_REST_Response(['ok' => true], 200);
            });
        } catch (\Throwable) {
            return new \WP_Error('e7_otp_unavailable', __('Não foi possível validar o código. Tente novamente.', 'e7-propostas'), ['status' => 503]);
        }
    }

    public function accept(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $sessionRaw = self::sessionCookie();
        $session = $this->repository->findSession($sessionRaw);
        if (! is_array($session)) {
            return $this->genericError(403);
        }
        $name = sanitize_text_field((string) $request->get_param('name'));
        $role = sanitize_text_field((string) $request->get_param('role'));
        $company = sanitize_text_field((string) $request->get_param('company'));
        if ($name === '' || filter_var($request->get_param('consent'), FILTER_VALIDATE_BOOLEAN) !== true) {
            return new \WP_Error('e7_acceptance_fields', __('Informe seu nome e confirme o aceite.', 'e7-propostas'), ['status' => 422]);
        }
        try {
            $email = OtpDestination::from('email', (string) $request->get_param('email'))->value;
            $phone = OtpDestination::from('sms', (string) $request->get_param('phone'))->value;
        } catch (\InvalidArgumentException) {
            return new \WP_Error('e7_acceptance_fields', __('Informe um e-mail e um telefone válidos.', 'e7-propostas'), ['status' => 422]);
        }
        $idempotency = sanitize_text_field($request->get_header('idempotency-key'));
        if (! preg_match('/^[A-Za-z0-9_-]{16,128}$/', $idempotency)) {
            return new \WP_Error('e7_idempotency', __('Identificador de envio inválido.', 'e7-propostas'), ['status' => 400]);
        }
        $idempotencyHash = hash('sha256', $idempotency);
        $existing = $this->repository->findAcceptanceByIdempotency((int) $session['version_id'], $idempotencyHash);
        if (is_array($existing)) {
            return new \WP_REST_Response(['ok' => true, 'document_id' => $existing['public_id'], 'verify_url' => home_url('/verify/' . $existing['public_id'] . '/'), 'download_url' => home_url('/download/' . $existing['public_id'] . '/')], 200);
        }
        $version = $this->repository->getVersion((int) $session['version_id']);
        $settings = is_array($version) ? $this->repository->getSettings((int) $version['post_id']) : [];
        $consent = ($settings['locale'] ?? 'pt_BR') === 'en_IE'
            ? 'I have read and accept this proposal and agree to the use of electronic records and signatures.'
            : 'Li e aceito esta proposta e concordo com o uso de registros e assinaturas eletrônicas.';
        try {
            $acceptance = $this->repository->withOtpLock((int) $session['version_id'], function () use ($session, $request, $settings, $email, $phone, $idempotencyHash, $name, $role, $company, $consent): array {
                $otp = $this->repository->latestOtp((int) $session['id'], (int) $session['version_id']);
                if (! is_array($otp)) {
                    throw new \UnexpectedValueException('otp_required');
                }
                $this->assertOtpContactBinding($settings, $otp, $email, $phone);
                $verification = $this->verifyChallenge($otp, (string) $request->get_param('otp'));
                if (! $verification->isValid) {
                    if ($verification->reason === 'invalid' || $verification->reason === 'locked') {
                        $this->repository->recordOtpFailure((int) $otp['id'], (int) $otp['attempts']);
                    }
                    $this->repository->appendAudit((int) $session['version_id'], 'otp.denied', ['reason' => $verification->reason, 'attempts' => $verification->challenge->attempts]);
                    throw new \UnexpectedValueException('otp_invalid');
                }
                return $this->repository->accept((int) $session['version_id'], (int) $otp['id'], (int) $otp['attempts'], $idempotencyHash, ['name' => $name, 'role' => $role, 'company' => $company, 'email' => $email, 'phone' => $phone], $consent, $this->clientIp(), $this->userAgent());
            });
        } catch (\InvalidArgumentException) {
            return new \WP_Error('e7_otp_contact', __('Os dados do signatário não correspondem ao destino validado.', 'e7-propostas'), ['status' => 422]);
        } catch (\UnexpectedValueException $error) {
            if ($error->getMessage() === 'otp_required') {
                return new \WP_Error('e7_otp_required', __('Solicite o código antes de aceitar.', 'e7-propostas'), ['status' => 409]);
            }
            return new \WP_Error('e7_otp_invalid', __('Código inválido ou expirado.', 'e7-propostas'), ['status' => 422]);
        } catch (\DomainException $error) {
            return new \WP_Error('e7_already_accepted', __('Esta proposta não está mais disponível para aceite.', 'e7-propostas'), ['status' => 409]);
        } catch (\Throwable) {
            return new \WP_Error('e7_otp_unavailable', __('Não foi possível concluir o aceite. Tente novamente.', 'e7-propostas'), ['status' => 503]);
        }
        return new \WP_REST_Response(['ok' => true, 'document_id' => $acceptance['public_id'], 'verify_url' => home_url('/verify/' . $acceptance['public_id'] . '/'), 'download_url' => home_url('/download/' . $acceptance['public_id'] . '/')], 201);
    }

    public function verify(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $record = $this->repository->findAcceptance(sanitize_text_field((string) $request['document_id']));
        if (! is_array($record)) {
            return new \WP_Error('e7_not_found', __('Documento não encontrado.', 'e7-propostas'), ['status' => 404]);
        }
        return new \WP_REST_Response(['document_id' => $record['acceptance']['public_id'], 'version' => (int) $record['version']['version_no'], 'status' => $record['version']['status'], 'document_hash' => $record['version']['document_hash'], 'artifact_hash' => $record['version']['artifact_hash'], 'signature_verified' => $this->artifactVerifier->verify($record['version']), 'accepted_at' => $record['acceptance']['accepted_at'], 'artifact_ready' => ! empty($record['version']['artifact_key'])], 200);
    }

    public static function sessionCookie(): string
    {
        $name = is_ssl() ? '__Host-e7-proposal-session' : 'e7_proposal_session';
        return sanitize_text_field(wp_unslash($_COOKIE[$name] ?? ''));
    }

    public static function csrfFor(string $session): string
    {
        return hash_hmac('sha256', 'csrf|' . $session, wp_salt('nonce'));
    }

    private function setSessionCookie(string $session): void
    {
        $secure = is_ssl();
        setcookie($secure ? '__Host-e7-proposal-session' : 'e7_proposal_session', $session, [
            'expires' => time() + 8 * HOUR_IN_SECONDS,
            'path' => '/',
            'secure' => $secure,
            'HttpOnly' => true,
            'SameSite' => 'Strict',
        ]);
    }

    private function genericError(int $status = 401): \WP_Error
    {
        return new \WP_Error('e7_access', __('Não foi possível continuar com os dados informados.', 'e7-propostas'), ['status' => $status]);
    }

    private function clientIp(): string
    {
        return sanitize_text_field((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    private function userAgent(): string
    {
        return substr(sanitize_text_field((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 1000);
    }

    /** @param array<string, mixed> $settings */
    private function configuredOtpDestination(array $settings, OtpDestination $requested): OtpDestination
    {
        $configured = $requested->channel === 'email'
            ? trim((string) ($settings['client_email'] ?? ''))
            : trim((string) ($settings['client_phone'] ?? ''));
        return $configured === '' ? $requested : OtpDestination::from($requested->channel, $configured);
    }

    /** @param array<string, mixed> $settings @param array<string, mixed> $otp */
    private function assertOtpContactBinding(array $settings, array $otp, string $email, string $phone): void
    {
        $configuredEmail = trim((string) ($settings['client_email'] ?? ''));
        $configuredPhone = trim((string) ($settings['client_phone'] ?? ''));
        if (($configuredEmail !== '' && strcasecmp($configuredEmail, $email) !== 0)
            || ($configuredPhone !== '' && ! hash_equals($configuredPhone, $phone))) {
            throw new \InvalidArgumentException('Signer contact does not match proposal settings.');
        }
        $channel = (string) ($otp['channel'] ?? '');
        $destination = (string) ($otp['destination'] ?? '');
        $submitted = $channel === 'email' ? $email : ($channel === 'sms' ? $phone : '');
        $matches = $channel === 'email' ? strcasecmp($destination, $submitted) === 0 : hash_equals($destination, $submitted);
        if ($destination === '' || $submitted === '' || ! $matches) {
            throw new \InvalidArgumentException('Signer contact does not match verified OTP destination.');
        }
    }

    /** @param array<string, mixed> $otp */
    private function verifyChallenge(array $otp, string $code): \E7Propostas\Domain\OtpVerification
    {
        $challenge = new OtpChallenge((string) $otp['code_hash'], new DateTimeImmutable((string) $otp['expires_at'], new DateTimeZone('UTC')), (int) $otp['attempts'], $otp['consumed_at'] !== null);
        return $this->otps->verify($challenge, sanitize_text_field($code), new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }
}
