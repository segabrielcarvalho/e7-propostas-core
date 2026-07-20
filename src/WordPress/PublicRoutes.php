<?php

declare(strict_types=1);

namespace E7Propostas\WordPress;

use E7Propostas\Infrastructure\ArtifactVerifier;
use E7Propostas\Infrastructure\ArtifactDownload;
use E7Propostas\Infrastructure\FeatureFlags;

final class PublicRoutes
{
    /** @var array<string, mixed> */
    private static array $view = [];

    public function __construct(private readonly ProposalRepository $repository, private readonly ArtifactVerifier $artifactVerifier, private readonly ArtifactDownload $artifactDownload, private readonly FeatureFlags $features)
    {
    }

    public static function registerRewrites(): void
    {
        add_rewrite_rule('^p/([A-Za-z0-9]{8})/?$', 'index.php?e7_proposal_code=$matches[1]', 'top');
        add_rewrite_rule('^verify/([a-f0-9]{32})/?$', 'index.php?e7_verify_id=$matches[1]', 'top');
        add_rewrite_rule('^download/([a-f0-9]{32})/?$', 'index.php?e7_download_id=$matches[1]', 'top');
    }

    /** @param list<string> $vars @return list<string> */
    public function queryVars(array $vars): array
    {
        $vars[] = 'e7_proposal_code';
        $vars[] = 'e7_verify_id';
        $vars[] = 'e7_download_id';
        return $vars;
    }

    public function dispatch(): void
    {
        $code = (string) get_query_var('e7_proposal_code');
        $verifyId = (string) get_query_var('e7_verify_id');
        $downloadId = (string) get_query_var('e7_download_id');
        if ($code === '' && $verifyId === '' && $downloadId === '') {
            return;
        }
        $this->privateHeaders();
        if ($downloadId !== '') {
            $this->download($downloadId);
        }
        if ($code !== '') {
            $this->proposal($code);
            return;
        }
        $this->verification($verifyId);
    }

    /** @return array<string, mixed> */
    public static function view(): array
    {
        return self::$view;
    }

    private function proposal(string $code): void
    {
        $version = $this->repository->findCurrentByShareCode($code);
        if (! is_array($version)) {
            self::$view = ['screen' => 'unavailable'];
            status_header(404);
            $this->render('proposal.php');
        }
        $sessionRaw = RestController::sessionCookie();
        $session = $sessionRaw !== '' ? $this->repository->findSession($sessionRaw) : null;
        $authorized = is_array($session) && (int) $session['version_id'] === (int) $version['id'];
        $settings = $this->repository->getSettings((int) $version['post_id']);
        $acceptance = (string) $version['status'] === 'accepted' ? $this->repository->findAcceptanceByVersion((int) $version['id']) : null;
        $pageTitle = get_the_title((int) $version['post_id']);
        self::$view = [
            'screen' => $authorized ? 'proposal' : 'password',
            'code' => strtolower($code),
            'page_title' => is_string($pageTitle) ? $pageTitle : '',
            'version' => $authorized ? $version : null,
            'settings' => $authorized ? $settings : null,
            'locale' => (string) ($settings['locale'] ?? 'pt_BR'),
            'csrf' => $authorized ? RestController::csrfFor($sessionRaw) : '',
            'rest_url' => esc_url_raw(rest_url('e7-propostas/v1')),
            'acceptance' => $authorized ? $acceptance : null,
            'otp_enabled' => $this->features->otpEnabled(),
            'irish_invoice_flow' => AcceptancePolicy::isIrishInvoiceFlow((string) ($settings['locale'] ?? ''), (string) ($settings['currency'] ?? '')),
        ];
        $this->render('proposal.php');
    }

    private function verification(string $publicId): void
    {
        $record = $this->repository->findAcceptance($publicId);
        $snapshot = is_array($record) ? json_decode((string) $record['version']['snapshot_json'], true) : [];
        $locale = is_array($snapshot) && is_array($snapshot['metadata'] ?? null) ? (string) ($snapshot['metadata']['locale'] ?? 'pt_BR') : 'pt_BR';
        self::$view = ['screen' => 'verify', 'record' => $record, 'signature_verified' => is_array($record) ? $this->artifactVerifier->verify($record['version']) : false, 'locale' => $locale];
        if (! is_array($record)) {
            status_header(404);
        }
        $this->render('verify.php');
    }

    private function download(string $publicId): never
    {
        $record = $this->repository->findAcceptance($publicId);
        $session = $this->repository->findSession(RestController::sessionCookie());
        if (! is_array($record) || ! is_array($session) || (int) $session['version_id'] !== (int) $record['version']['id']) {
            wp_die(esc_html__('Acesso ao arquivo não autorizado.', 'e7-propostas'), '', ['response' => 403]);
        }
        $this->artifactDownload->serve($record['version'], $publicId);
    }

    private function render(string $file): never
    {
        $template = get_theme_file_path($file);
        if (! is_file($template)) {
            wp_die(esc_html__('O tema E7 Propostas não está ativo.', 'e7-propostas'), '', ['response' => 500]);
        }
        include $template;
        exit;
    }

    private function privateHeaders(): void
    {
        nocache_headers();
        header('Cache-Control: no-store, private, max-age=0');
        header('X-Robots-Tag: noindex, nofollow, noarchive', true);
        header('Referrer-Policy: no-referrer', true);
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'", true);
        header('X-Content-Type-Options: nosniff', true);
        header('X-Frame-Options: DENY', true);
    }
}
