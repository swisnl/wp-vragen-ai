<?php

namespace VragenAI;

final class PostVisibility
{
    /**
     * Whether an anonymous visitor may see a resolved post: a viewable status
     * and type, not password-protected. Used on the read paths, where a hit can
     * resolve to any post. The sync gate computes its own base (it already knows
     * the type is enabled) but shares the filter below, so the two cannot drift.
     */
    public static function isPublic(\WP_Post $post): bool
    {
        return self::vetoable(is_post_publicly_viewable($post) && $post->post_password === '', $post);
    }

    /**
     * Apply the site's own visibility rule to an already-computed verdict. This
     * is the single seam for capability- or membership-gated content (paywall
     * plugins), which WordPress keeps on status 'publish' and so hides from no
     * core check. A site can both hide ($public true -> false) and reveal.
     */
    public static function vetoable(bool $public, \WP_Post $post): bool
    {
        /**
         * Filters whether a post counts as publicly visible for vragen.ai,
         * governing both indexing and display.
         *
         * @param  bool  $public  The result of WordPress' own visibility checks.
         * @param  \WP_Post  $post  The post being evaluated.
         */
        return (bool) apply_filters('vragenai_post_is_visible', $public, $post);
    }
}
