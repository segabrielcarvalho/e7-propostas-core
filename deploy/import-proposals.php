<?php

use E7Propostas\Domain\AuditChain;
use E7Propostas\Domain\PasswordService;
use E7Propostas\Domain\ShareCodeService;
use E7Propostas\Domain\SnapshotHasher;
use E7Propostas\Domain\TokenService;
use E7Propostas\Infrastructure\Crypto;
use E7Propostas\WordPress\Installer;
use E7Propostas\WordPress\ProposalMigrationCommand;
use E7Propostas\WordPress\ProposalRepository;

if (! defined('WP_CLI') || ! WP_CLI) {
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

$file = getenv('E7_PROPOSALS_IMPORT_FILE');
$password = getenv('E7_PROPOSALS_IMPORT_PASSWORD');
$expectedHost = getenv('E7_PROPOSALS_IMPORT_EXPECTED_HOST');
if (! is_string($file) || $file === '' || ! is_string($password) || $password === '' || ! is_string($expectedHost) || $expectedHost === '') {
    WP_CLI::error('Proposal import environment is incomplete.');
}
$currentHost = wp_parse_url(home_url('/'), PHP_URL_HOST);
if ((! is_string($currentHost) || ! hash_equals(strtolower($expectedHost), strtolower($currentHost))) && is_multisite()) {
    foreach (get_sites(['number' => 0]) as $site) {
        if (! $site instanceof WP_Site) {
            continue;
        }
        $candidateHost = wp_parse_url(get_home_url((int) $site->blog_id, '/'), PHP_URL_HOST);
        if (is_string($candidateHost) && hash_equals(strtolower($expectedHost), strtolower($candidateHost))) {
            switch_to_blog((int) $site->blog_id);
            $currentHost = wp_parse_url(home_url('/'), PHP_URL_HOST);
            break;
        }
    }
}
if (! is_string($currentHost) || ! hash_equals(strtolower($expectedHost), strtolower($currentHost))) {
    WP_CLI::error(sprintf('Proposal import refused: expected %s, got %s.', $expectedHost, is_string($currentHost) ? $currentHost : 'unknown'));
}

Installer::ensureSchema();
$secret = defined('AUTH_KEY') ? (string) AUTH_KEY : wp_salt('auth');
$repository = new ProposalRepository(
    new Crypto($secret),
    new TokenService(wp_salt('secure_auth')),
    new SnapshotHasher(),
    new AuditChain(),
    new ShareCodeService(),
);

(new ProposalMigrationCommand($repository, new PasswordService()))->import([], [
    'file' => $file,
    'password' => $password,
]);
