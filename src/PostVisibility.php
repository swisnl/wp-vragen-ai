<?php

namespace VragenAI;

final class PostVisibility
{
    /**
     * Whether an anonymous visitor may see a post: a viewable status and type,
     * not password-protected, and not vetoed by the site's own rule. The sync
     * gate and the read paths share this so they cannot drift apart. The filter
     * is the seam for capability- or membership-gated content (paywall plugins),
     * which WordPress keeps on status 'publish' and so hides from no core check.
     */
    public static function isPublic(\WP_Post $post): bool
    {
        $public = is_post_publicly_viewable($post) && $post->post_password === '';

        /**
         * Filters whether a post counts as publicly visible for vragen.ai.
         *
         * @param  bool  $public  The result of WordPress' own visibility checks.
         * @param  \WP_Post  $post  The post being evaluated.
         */
        return (bool) apply_filters('vragenai_post_is_visible', $public, $post);
    }
}
