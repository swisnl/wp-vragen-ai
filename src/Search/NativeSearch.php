<?php

namespace VragenAI\Search;

/**
 * Replaces WordPress' built-in search results with semantic results from
 * vragen.ai. Hooks posts_pre_query so the theme's existing search template,
 * widget and pagination keep working unchanged — only the result set and its
 * ordering come from the API.
 *
 * Degrades safely: when the feature is disabled, the query isn't a front-end
 * main search, or the API fails/times out, it returns control to WordPress and
 * the native search runs instead.
 */
class NativeSearch
{
    public function __construct(private readonly SearchService $service) {}

    public function register(): void
    {
        add_filter('posts_pre_query', [$this, 'maybeShortCircuit'], 10, 2);
    }

    /**
     * @param  array<int, \WP_Post>|null  $posts  Null until something short-circuits the query.
     * @return array<int, \WP_Post>|null
     */
    public function maybeShortCircuit(?array $posts, \WP_Query $query): ?array
    {
        if (! $this->shouldHandle($query)) {
            return $posts;
        }

        $settings = (array) get_option('vragenai_settings', []);

        $perPage = (int) $query->get('posts_per_page');
        if ($perPage <= 0) {
            $perPage = max(1, (int) get_option('posts_per_page', 10));
        }
        $paged = max(1, (int) $query->get('paged'));
        $offset = ($paged - 1) * $perPage;

        $postTypes = array_values(array_filter(array_map('strval', (array) ($settings['post_types'] ?? ['post', 'page']))));

        $baseOptions = [
            'post_types' => $postTypes,
            'language_fallback' => (bool) ($settings['search_language_fallback'] ?? true),
        ];

        $maxDistance = $settings['search_max_distance'] ?? '';
        if (is_numeric($maxDistance)) {
            $baseOptions['max_distance'] = (float) $maxDistance;
        }

        $alpha = $settings['search_alpha'] ?? '';
        if (is_numeric($alpha)) {
            $baseOptions['alpha'] = (float) $alpha;
        }

        /**
         * Filters the options passed to the search service for native search,
         * e.g. to set a maxDistance or narrow the post types.
         *
         * @param  array<string, mixed>  $options
         * @param  \WP_Query  $query  The main search query.
         */
        $options = (array) apply_filters('vragenai_native_search_options', $baseOptions, $query);

        $result = $this->service->search((string) $query->get('s'), $offset, $perPage, $options);

        // Null means not configured, an API error or a timeout — hand back to
        // WordPress so the site never loses its search.
        if ($result === null) {
            return $posts;
        }

        $query->found_posts = $result['total'];
        $query->max_num_pages = (int) ceil($result['total'] / $perPage);

        if ($result['ids'] === []) {
            return [];
        }

        return get_posts([
            'post_type' => $postTypes !== [] ? $postTypes : 'any',
            'post_status' => 'publish',
            'post__in' => $result['ids'],
            'orderby' => 'post__in',
            'posts_per_page' => count($result['ids']),
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
        ]);
    }

    private function shouldHandle(\WP_Query $query): bool
    {
        if (is_admin() || ! $query->is_main_query() || ! $query->is_search()) {
            return false;
        }

        $settings = (array) get_option('vragenai_settings', []);

        return ! empty($settings['replace_native_search']);
    }
}
