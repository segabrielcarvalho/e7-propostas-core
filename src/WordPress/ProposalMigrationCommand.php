<?php

declare(strict_types=1);

namespace E7Propostas\WordPress;

use E7Propostas\Domain\PasswordService;

final class ProposalMigrationCommand
{
    private const FORMAT = 'e7-proposals';
    private const VERSION = 1;
    private const MAX_PROPOSALS = 100;
    private const SOURCE_META_KEY = '_e7_migration_source_id';

    public function __construct(
        private readonly ProposalRepository $repository,
        private readonly PasswordService $passwords,
    ) {
    }

    /** @param list<string> $args @param array<string, mixed> $assocArgs */
    public function export(array $args, array $assocArgs): void
    {
        $posts = get_posts([
            'post_type' => 'e7_proposal',
            'post_status' => ['draft', 'pending', 'private', 'publish'],
            'numberposts' => self::MAX_PROPOSALS,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        $proposals = [];
        foreach ($posts as $post) {
            if (! $post instanceof \WP_Post) {
                continue;
            }
            $settings = $this->repository->getSettings($post->ID);
            $proposals[] = [
                'source_id' => $post->ID,
                'title' => $post->post_title,
                'content' => $post->post_content,
                'excerpt' => $post->post_excerpt,
                'menu_order' => $post->menu_order,
                'settings' => $this->safeSettings($settings),
            ];
        }

        $json = wp_json_encode([
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => gmdate(DATE_ATOM),
            'proposals' => $proposals,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($json)) {
            \WP_CLI::error('Could not encode the proposal export.');
        }

        $output = trim((string) ($assocArgs['output'] ?? ''));
        if ($output === '') {
            \WP_CLI::line($json);
            return;
        }

        $directory = dirname($output);
        if (! is_dir($directory) && ! wp_mkdir_p($directory)) {
            \WP_CLI::error('Could not create the export directory.');
        }
        if (file_put_contents($output, $json . PHP_EOL, LOCK_EX) === false) {
            \WP_CLI::error('Could not write the proposal export.');
        }
        @chmod($output, 0600);
        \WP_CLI::success(sprintf('%d proposal(s) exported.', count($proposals)));
    }

    /** @param list<string> $args @param array<string, mixed> $assocArgs */
    public function import(array $args, array $assocArgs): void
    {
        $file = trim((string) ($assocArgs['file'] ?? ''));
        $password = (string) ($assocArgs['password'] ?? '');
        if ($file === '' || ! is_file($file) || ! is_readable($file)) {
            \WP_CLI::error('A readable --file is required.');
        }
        if ($password === '') {
            \WP_CLI::error('A non-empty --password is required.');
        }

        try {
            $raw = file_get_contents($file);
            if (! is_string($raw)) {
                throw new \RuntimeException('Could not read the import file.');
            }
            $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            $proposals = $this->validatePayload($payload);
            $passwordHash = $this->passwords->hash($password);
            $results = [];
            foreach ($proposals as $proposal) {
                $results[] = $this->importProposal($proposal, $passwordHash);
            }
        } catch (\Throwable $error) {
            \WP_CLI::error($error->getMessage());
        }

        $json = wp_json_encode(['imported' => $results], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($json)) {
            \WP_CLI::error('Could not encode the import result.');
        }
        \WP_CLI::line($json);
        \WP_CLI::success(sprintf('%d proposal(s) imported.', count($results)));
    }

    /** @param mixed $payload @return list<array<string, mixed>> */
    private function validatePayload(mixed $payload): array
    {
        if (! is_array($payload) || ($payload['format'] ?? null) !== self::FORMAT || ($payload['version'] ?? null) !== self::VERSION) {
            throw new \InvalidArgumentException('Unsupported proposal export format.');
        }
        $proposals = $payload['proposals'] ?? null;
        if (! is_array($proposals) || count($proposals) > self::MAX_PROPOSALS) {
            throw new \InvalidArgumentException('Invalid proposal collection.');
        }
        foreach ($proposals as $proposal) {
            if (! is_array($proposal) || ! is_int($proposal['source_id'] ?? null) || ($proposal['source_id'] ?? 0) < 1 || ! is_string($proposal['title'] ?? null) || ! is_string($proposal['content'] ?? null) || ! is_array($proposal['settings'] ?? null)) {
                throw new \InvalidArgumentException('Invalid proposal record.');
            }
        }
        /** @var list<array<string, mixed>> $proposals */
        return array_values($proposals);
    }

    /** @param array<string, mixed> $proposal @return array<string, int|string> */
    private function importProposal(array $proposal, string $passwordHash): array
    {
        $sourceId = (int) $proposal['source_id'];
        $existing = get_posts([
            'post_type' => 'e7_proposal',
            'post_status' => 'any',
            'numberposts' => 1,
            'fields' => 'ids',
            'meta_key' => self::SOURCE_META_KEY,
            'meta_value' => (string) $sourceId,
        ]);
        $postId = isset($existing[0]) ? (int) $existing[0] : 0;
        if ($postId > 0 && $this->repository->isAcceptedPost($postId)) {
            throw new \DomainException(sprintf('Proposal imported from source %d is already accepted and immutable.', $sourceId));
        }

        $postData = [
            'post_type' => 'e7_proposal',
            'post_status' => 'draft',
            'post_title' => (string) $proposal['title'],
            'post_content' => (string) $proposal['content'],
            'post_excerpt' => is_string($proposal['excerpt'] ?? null) ? $proposal['excerpt'] : '',
            'menu_order' => is_int($proposal['menu_order'] ?? null) ? $proposal['menu_order'] : 0,
        ];
        if ($postId > 0) {
            $postData['ID'] = $postId;
            $written = wp_update_post($postData, true);
        } else {
            $written = wp_insert_post($postData, true);
        }
        if (is_wp_error($written)) {
            throw new \RuntimeException($written->get_error_message());
        }
        $postId = (int) $written;
        update_post_meta($postId, self::SOURCE_META_KEY, $sourceId);

        $this->repository->saveSettings($postId, $this->safeSettings($proposal['settings']), $passwordHash);
        $published = wp_update_post(['ID' => $postId, 'post_status' => 'publish'], true);
        if (is_wp_error($published)) {
            throw new \RuntimeException($published->get_error_message());
        }
        $post = get_post($postId);
        if (! $post instanceof \WP_Post || $this->repository->publish($post) === null) {
            throw new \RuntimeException(sprintf('Could not publish proposal imported from source %d.', $sourceId));
        }
        $shareCode = $this->repository->getShareCode($postId);
        if ($shareCode === null) {
            throw new \RuntimeException(sprintf('Could not create the public link for source %d.', $sourceId));
        }

        return [
            'source_id' => $sourceId,
            'post_id' => $postId,
            'share_code' => $shareCode,
            'url' => home_url('/p/' . rawurlencode($shareCode) . '/'),
        ];
    }

    /** @param array<string, mixed> $settings @return array<string, string> */
    private function safeSettings(array $settings): array
    {
        $safe = [];
        foreach (['client_name', 'client_email', 'client_phone', 'client_company', 'copy_email', 'expires_at', 'locale', 'currency', 'otp_policy'] as $key) {
            $safe[$key] = is_scalar($settings[$key] ?? null) ? (string) $settings[$key] : '';
        }
        return $safe;
    }
}
