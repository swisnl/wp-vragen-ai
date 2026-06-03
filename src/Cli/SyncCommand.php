<?php

namespace VragenAI\Cli;

use VragenAI\ApiClient;
use VragenAI\DocumentSync;

class SyncCommand
{
    /**
     * Synchronise published posts to vragen.ai.
     *
     * ## OPTIONS
     *
     * [--post-type=<post-type>]
     * : Limit sync to a specific post type. Defaults to all enabled types.
     *
     * [--dry-run]
     * : List post IDs without syncing.
     *
     * ## EXAMPLES
     *
     *     wp vragenai sync
     *     wp vragenai sync --post-type=post
     *     wp vragenai sync --dry-run
     *
     * @when after_wp_load
     */
    public function sync(array $args, array $assoc): void
    {
        $settings     = (array) get_option('vragenai_settings', []);
        $enabledTypes = (array) ($settings['post_types'] ?? ['post', 'page']);

        if (!empty($assoc['post-type'])) {
            $enabledTypes = [$assoc['post-type']];
        }

        $ids = get_posts([
            'post_type'      => $enabledTypes,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        \WP_CLI::log(sprintf('Found %d posts.', count($ids)));

        if (!empty($assoc['dry-run'])) {
            \WP_CLI::success('Dry run — no posts synced.');
            return;
        }

        $sync     = new DocumentSync(ApiClient::fromSettings());
        $progress = \WP_CLI\Utils\make_progress_bar('Syncing', count($ids));

        foreach ($ids as $postId) {
            $post = get_post($postId);
            if ($post) {
                $sync->syncPostDirectly($post);
            }
            $progress->tick();
        }

        $progress->finish();
        \WP_CLI::success(sprintf('Synced %d posts.', count($ids)));
    }
}
