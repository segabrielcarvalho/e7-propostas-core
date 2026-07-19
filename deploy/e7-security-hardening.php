<?php
/**
 * Plugin Name: E7 Security Hardening
 * Description: Baseline security controls that must remain active independently of ordinary plugins.
 */

declare(strict_types=1);

if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
    status_header(403);
    nocache_headers();
    exit('XML-RPC is disabled.');
}

add_filter('xmlrpc_enabled', '__return_false', PHP_INT_MAX);
add_filter('xmlrpc_methods', static fn (array $methods): array => []);
add_filter('pings_open', '__return_false', PHP_INT_MAX);
add_filter('wp_headers', static function (array $headers): array {
    unset($headers['X-Pingback']);
    $headers['X-Content-Type-Options'] = 'nosniff';
    $headers['Referrer-Policy'] = 'strict-origin-when-cross-origin';
    $headers['Permissions-Policy'] = 'camera=(), microphone=(), geolocation=()';
    return $headers;
});
