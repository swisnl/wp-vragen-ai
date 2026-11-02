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
     * core check. On the read paths a filter can both hide and reveal; the sync
     * gate ANDs the hard status/password check around this, so there a filter can
     * only hide, never force a draft or protected post into the index.
     */
    public static function vetoable(bool $public, \WP_Post $post): bool
    {
        /**
         * Filters whether a post counts as publicly visible for vragen.ai,
         * governing both indexing and display. Returning false to hide a post is
         * the safe, symmetric use. On the read paths $public is WordPress' own
         * verdict and returning true can also reveal an otherwise-hidden post; on
         * the sync gate $public is always true (the hard status/password check is
         * applied around this filter), so there it can only hide.
         *
         * @param  bool  $public  The base verdict: WordPress' visibility checks on read, always true on sync.
         * @param  \WP_Post  $post  The post being evaluated.
         */
        return (bool) apply_filters('vragenai_post_is_visible', $public, $post);
    }
}
