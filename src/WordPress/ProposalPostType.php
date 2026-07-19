<?php

declare(strict_types=1);

namespace E7Propostas\WordPress;

final class ProposalPostType
{
    public static function register(): void
    {
        register_post_type('e7_proposal', [
            'labels' => [
                'name' => __('Propostas', 'e7-propostas'),
                'singular_name' => __('Proposta', 'e7-propostas'),
                'add_new_item' => __('Criar proposta', 'e7-propostas'),
                'edit_item' => __('Editar proposta', 'e7-propostas'),
            ],
            'public' => false,
            'publicly_queryable' => false,
            'exclude_from_search' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => true,
            'has_archive' => false,
            'rewrite' => false,
            'query_var' => false,
            'supports' => ['title', 'editor', 'revisions'],
            'menu_icon' => 'dashicons-media-document',
            'map_meta_cap' => true,
            'capability_type' => ['e7_proposal', 'e7_proposals'],
            'capabilities' => [
                'edit_post' => 'e7_edit_proposal',
                'read_post' => 'e7_read_proposal',
                'delete_post' => 'e7_delete_proposal',
                'edit_posts' => 'e7_edit_proposals',
                'edit_others_posts' => 'e7_edit_others_proposals',
                'publish_posts' => 'e7_publish_proposals',
                'read_private_posts' => 'e7_read_private_proposals',
                'delete_posts' => 'e7_delete_proposals',
            ],
            'template' => [
                ['core/cover', ['dimRatio' => 70, 'minHeight' => 420], [
                    ['core/heading', ['level' => 1, 'placeholder' => __('Título da proposta', 'e7-propostas')]],
                    ['core/paragraph', ['placeholder' => __('Resumo executivo', 'e7-propostas')]],
                ]],
                ['core/heading', ['level' => 2, 'content' => __('Escopo', 'e7-propostas')]],
                ['core/paragraph', ['placeholder' => __('Descreva o trabalho proposto.', 'e7-propostas')]],
                ['core/heading', ['level' => 2, 'content' => __('Investimento', 'e7-propostas')]],
                ['core/table'],
                ['core/heading', ['level' => 2, 'content' => __('Termos', 'e7-propostas')]],
                ['core/paragraph', ['placeholder' => __('Inclua os termos revisados juridicamente.', 'e7-propostas')]],
            ],
        ]);
    }

    /** @return list<string> */
    public static function capabilities(): array
    {
        return ['e7_edit_proposal', 'e7_read_proposal', 'e7_delete_proposal', 'e7_edit_proposals', 'e7_edit_others_proposals', 'e7_publish_proposals', 'e7_read_private_proposals', 'e7_delete_proposals'];
    }
}
