<?php

if (! defined('WP_CLI') || ! WP_CLI) {
    exit(1);
}

$expectedHost = getenv('E7_PROPOSALS_CLEANUP_EXPECTED_HOST');
$sourceList = getenv('E7_PROPOSALS_CLEANUP_SOURCE_IDS');
$currentHost = wp_parse_url(home_url('/'), PHP_URL_HOST);
if (! is_string($expectedHost) || $expectedHost === '' || ! is_string($sourceList) || preg_match('/^[0-9]+(?:,[0-9]+)*$/', $sourceList) !== 1) {
    WP_CLI::error('Proposal cleanup environment is incomplete.');
}
if (! is_string($currentHost) || ! hash_equals(strtolower($expectedHost), strtolower($currentHost))) {
    WP_CLI::error('Proposal cleanup refused: unexpected WordPress site.');
}

$sourceIds = array_values(array_unique(array_map('intval', explode(',', $sourceList))));
$postIds = get_posts([
    'post_type' => 'e7_proposal',
    'post_status' => 'any',
    'numberposts' => -1,
    'fields' => 'ids',
    'meta_query' => [[
        'key' => '_e7_migration_source_id',
        'value' => array_map('strval', $sourceIds),
        'compare' => 'IN',
    ]],
]);

global $wpdb;
$removed = 0;
foreach ($postIds as $candidate) {
    $postId = (int) $candidate;
    $sourceId = (int) get_post_meta($postId, '_e7_migration_source_id', true);
    if (! in_array($sourceId, $sourceIds, true) || get_post_type($postId) !== 'e7_proposal') {
        continue;
    }
    $versionIds = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}e7_proposal_versions WHERE post_id = %d",
        $postId,
    ));
    foreach ($versionIds as $candidateVersionId) {
        $versionId = (int) $candidateVersionId;
        foreach (['otps', 'sessions', 'acceptances', 'audit_events', 'jobs'] as $suffix) {
            $wpdb->delete($wpdb->prefix . 'e7_proposal_' . $suffix, ['version_id' => $versionId], ['%d']);
        }
        $wpdb->delete($wpdb->prefix . 'e7_proposal_versions', ['id' => $versionId], ['%d']);
    }
    $wpdb->delete($wpdb->prefix . 'e7_proposal_settings', ['post_id' => $postId], ['%d']);
    if (wp_delete_post($postId, true) !== false) {
        $removed++;
    }
}

WP_CLI::success(sprintf('%d misrouted proposal(s) removed.', $removed));
