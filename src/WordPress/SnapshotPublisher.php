<?php

declare(strict_types=1);

namespace E7Propostas\WordPress;

final class SnapshotPublisher
{
    public function __construct(private readonly ProposalRepository $repository)
    {
    }

    public function publish(int $postId, \WP_Post $post): void
    {
        if ($post->post_type !== 'e7_proposal' || $post->post_status !== 'publish' || wp_is_post_autosave($postId) || wp_is_post_revision($postId)) {
            return;
        }
        try {
            $version = $this->repository->publish($post);
        } catch (\InvalidArgumentException $error) {
            set_transient('e7_proposal_admin_error_' . get_current_user_id(), $error->getMessage(), 60);
            return;
        }
        if ($version === null) {
            set_transient('e7_proposal_admin_error_' . get_current_user_id(), __('Defina uma senha antes de publicar. Propostas aceitas devem ser duplicadas.', 'e7-propostas'), 60);
        }
    }
}
