<?php

declare(strict_types=1);

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
if (! is_string($file) || $file === '' || ! is_string($password) || $password === '') {
    WP_CLI::error('Proposal import environment is incomplete.');
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
