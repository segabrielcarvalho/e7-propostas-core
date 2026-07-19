<?php
/**
 * Plugin Name: E7 Propostas Core
 * Description: Propostas privadas, aceite eletrônico e trilha de auditoria da E7 Company.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.2
 * Network: false
 * Text Domain: e7-propostas
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

require __DIR__ . '/vendor/autoload.php';

register_activation_hook(__FILE__, [E7Propostas\WordPress\Installer::class, 'activate']);
register_deactivation_hook(__FILE__, [E7Propostas\WordPress\Installer::class, 'deactivate']);

E7Propostas\WordPress\Plugin::boot(__FILE__);

if (! function_exists('e7_propostas_view')) {
    /** @return array<string, mixed> */
    function e7_propostas_view(): array
    {
        return E7Propostas\WordPress\PublicRoutes::view();
    }
}
