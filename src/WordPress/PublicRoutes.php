<?php

declare(strict_types=1);

namespace E7Propostas\WordPress;

use E7Propostas\Infrastructure\ArtifactVerifier;
use E7Propostas\Infrastructure\ArtifactDownload;
use E7Propostas\Infrastructure\FeatureFlags;
use E7Propostas\Infrastructure\InvoiceRoutePolicy;

final class PublicRoutes
{
    /** @var array<string, mixed> */
    private static array $view = [];

    public function __construct(private readonly ProposalRepository $repository, private readonly InvoiceRepository $invoices, private readonly ArtifactVerifier $artifactVerifier, private readonly ArtifactDownload $artifactDownload, private readonly FeatureFlags $features)
    {
    }

    public static function registerRewrites(): void
    {
        add_rewrite_rule('^p/([A-Za-z0-9]{8})/?$', 'index.php?e7_proposal_code=$matches[1]', 'top');
        add_rewrite_rule('^verify/([a-f0-9]{32})/?$', 'index.php?e7_verify_id=$matches[1]', 'top');
        add_rewrite_rule('^download/([a-f0-9]{32})/?$', 'index.php?e7_download_id=$matches[1]', 'top');
        add_rewrite_rule('^invoice/verify/([a-f0-9]{32})/?$', 'index.php?e7_invoice_verify_id=$matches[1]', 'top');
        add_rewrite_rule('^invoice/download/([a-f0-9]{32})/?$', 'index.php?e7_invoice_download_id=$matches[1]', 'top');
    }

    /** @param list<string> $vars @return list<string> */
    public function queryVars(array $vars): array
    {
        $vars[] = 'e7_proposal_code';
        $vars[] = 'e7_verify_id';
        $vars[] = 'e7_download_id';
        $vars[] = 'e7_invoice_verify_id';
        $vars[] = 'e7_invoice_download_id';
        return $vars;
    }

    public function dispatch(): void
    {
        $code = (string) get_query_var('e7_proposal_code');
        $verifyId = (string) get_query_var('e7_verify_id');
        $downloadId = (string) get_query_var('e7_download_id');
        $invoiceVerifyId = (string) get_query_var('e7_invoice_verify_id');
        $invoiceDownloadId = (string) get_query_var('e7_invoice_download_id');
        if ($code === '' && $verifyId === '' && $downloadId === '' && $invoiceVerifyId === '' && $invoiceDownloadId === '') {
            return;
        }
        $this->privateHeaders();
        if ($downloadId !== '') {
            $this->download($downloadId);
        }
        if ($invoiceDownloadId !== '') {
            $this->invoiceDownload($invoiceDownloadId);
        }
        if ($code !== '') {
            $this->proposal($code);
            return;
        }
        if ($invoiceVerifyId !== '') {
            $this->invoiceVerification($invoiceVerifyId);
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
        $issuedInvoice = $authorized ? $this->invoices->latestIssuedForVersion((int) $version['id']) : null;
        $invoiceView = is_array($issuedInvoice) ? [
            'invoice_number' => (string) $issuedInvoice['invoice_number'],
            'issued_at' => (string) $issuedInvoice['issued_at'],
            'currency' => (string) $issuedInvoice['currency'],
            'total_minor' => (int) $issuedInvoice['total_minor'],
            'status' => (string) $issuedInvoice['status'],
            'verification_url' => home_url('/invoice/verify/' . $issuedInvoice['public_id'] . '/'),
        ] : null;
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
            'issued_invoice' => $authorized ? $invoiceView : null,
            'invoice_download_url' => $authorized && is_array($issuedInvoice) && $issuedInvoice['status'] === 'issued' ? home_url('/invoice/download/' . $issuedInvoice['public_id'] . '/') : null,
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

    private function invoiceDownload(string $publicId): never
    {
        $invoice = $this->invoices->findByPublicId($publicId);
        $sessionRaw = RestController::sessionCookie();
        $session = $sessionRaw !== '' ? $this->repository->findSession($sessionRaw) : null;
        if (! is_array($invoice) || ! InvoiceRoutePolicy::canCustomerDownload($invoice, $session)) {
            wp_die(esc_html__('Invoice download is not authorised.', 'e7-propostas'), '', ['response' => 403]);
        }
        $this->artifactDownload->serve($invoice, $publicId, 'invoice');
    }

    private function invoiceVerification(string $publicId): never
    {
        try {
            $invoice = $this->invoices->findByPublicId($publicId);
            $record = is_array($invoice) ? InvoiceRoutePolicy::verificationRecord($invoice, $this->artifactVerifier->verifyInvoice($invoice)) : null;
        } catch (\Throwable) {
            $record = null;
        }
        if (! is_array($record)) {
            status_header(404);
            wp_die(esc_html__('Invoice verification record was not found.', 'e7-propostas'), '', ['response' => 404]);
        }
        $rows = '';
        foreach ($record as $label => $value) {
            $display = is_bool($value) ? ($value ? 'yes' : 'no') : ($value ?? '—');
            $rows .= '<dt>' . esc_html(ucwords(str_replace('_', ' ', $label))) . '</dt><dd>' . esc_html((string) $display) . '</dd>';
        }
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow,noarchive"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Invoice verification</title><style>body{font:16px/1.5 Arial,sans-serif;color:#122033;margin:40px auto;max-width:760px;padding:0 20px}h1{color:#071a33}dl{display:grid;grid-template-columns:minmax(180px,1fr) 2fr;gap:8px 24px}dt{font-weight:700}dd{margin:0;word-break:break-word}</style></head><body><main><h1>Invoice verification</h1><dl>' . $rows . '</dl></main></body></html>';
        exit;
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
