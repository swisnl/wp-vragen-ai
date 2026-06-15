<?php

namespace VragenAI\Search;

use VragenAI\ApiClient;
use VragenAI\LanguageResolver;

/**
 * Read path over the vragen.ai search API: turns a query (or a source post)
 * into an ordered list of WordPress post IDs.
 *
 * Every synced document is keyed on `external_reference = wp_post_{canonicalId}`
 * where the canonical ID is the default-language post. A search hit is resolved
 * back by stripping that prefix and asking {@see LanguageResolver} for the
 * translation in the visitor's current language (falling back to the canonical
 * post when configured). Results that don't originate from this site, or whose
 * post no longer exists in the wanted language, are dropped.
 *
 * All public methods return null on any failure (not configured, API error,
 * timeout) so callers can fall back to WordPress' native behaviour.
 */
final class SearchService
{
    /** External references for synced posts use this prefix. */
    private const REF_PREFIX = 'wp_post_';

    private const SEARCH_CACHE_TTL = 5 * MINUTE_IN_SECONDS;

    private const RELATED_CACHE_TTL = HOUR_IN_SECONDS;

    private const FIELDS = 'external_reference,relevance_score,metadata_fields';

    public function __construct(
        private readonly ApiClient $client,
        private readonly LanguageResolver $languageResolver = new LanguageResolver,
    ) {}

    /**
     * Run a semantic search.
     *
     * @param  array{post_types?: list<string>, language_fallback?: bool, max_distance?: float|null, alpha?: float|null}  $options
     * @return array{ids: list<int>, total: int}|null
     */
    public function search(string $query, int $offset, int $limit, array $options = []): ?array
    {
        $query = trim($query);
        if ($query === '' || ! $this->isConfigured()) {
            return null;
        }

        $params = [
            'query' => $query,
            'page[offset]' => max(0, $offset),
            'page[limit]' => max(1, $limit),
            'fields[documents]' => self::FIELDS,
        ];

        $this->applyFilters($params, $options);

        if (isset($options['max_distance'])) {
            $params['maxDistance'] = $options['max_distance'];
        }

        // Hybrid-search weighting: 1 = pure semantic, 0 = pure keyword.
        if (isset($options['alpha'])) {
            $params['alpha'] = $options['alpha'];
        }

        return $this->cached(
            'search',
            $params,
            self::SEARCH_CACHE_TTL,
            fn (): array|\WP_Error => $this->client->searchDocuments($params),
            ! ($options['language_fallback'] ?? true),
        );
    }

    /**
     * Find content related to a post ("more like this"), excluding the post
     * itself. Returns null when the post isn't synced or on any API failure.
     *
     * @param  array{post_types?: list<string>, language_fallback?: bool}  $options
     * @return array{ids: list<int>, total: int}|null
     */
    public function related(int $postId, int $limit, array $options = []): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            return null;
        }

        $ref = self::REF_PREFIX.$this->languageResolver->getCanonicalPostId($post);

        $params = [
            'page[offset]' => 0,
            'page[limit]' => max(1, $limit),
            'fields[documents]' => self::FIELDS,
        ];

        $this->applyFilters($params, $options);

        // The similar endpoint excludes the seed document from its own results,
        // so there's no need to over-fetch and strip the source post here.
        return $this->cached(
            'related',
            ['ref' => $ref] + $params,
            self::RELATED_CACHE_TTL,
            fn (): array|\WP_Error => $this->client->similarDocuments($ref, $params),
            ! ($options['language_fallback'] ?? true),
        );
    }

    /**
     * Add language and post-type filters to the query params. With language
     * fallback enabled no language filter is sent (best-available translation
     * is shown); with it disabled results are constrained to the current
     * language so the API's total count matches what we display.
     *
     * @param  array<string, mixed>  $params
     * @param  array{post_types?: list<string>, language_fallback?: bool, max_distance?: float|null}  $options
     */
    private function applyFilters(array &$params, array $options): void
    {
        $filter = new FilterBuilder;

        $postTypes = array_values(array_filter(array_map('strval', $options['post_types'] ?? [])));
        if ($postTypes !== []) {
            $filter->whereIn('post_type', $postTypes);
        }

        if (! ($options['language_fallback'] ?? true)) {
            $filter->where('languages', $this->languageResolver->getCurrentLanguage());
        }

        if (! $filter->isEmpty()) {
            $params['filter'] = $filter->toArray();
        }
    }

    /**
     * Execute $fetch (unless cached), map the JSON:API collection to post IDs,
     * and cache the mapped {ids, total}. Returns null on WP_Error so callers
     * fall back.
     *
     * @param  array<string, mixed>  $cacheParams
     * @param  callable(): (array<string, mixed>|\WP_Error)  $fetch
     * @return array{ids: list<int>, total: int}|null
     */
    private function cached(string $kind, array $cacheParams, int $ttl, callable $fetch, bool $strictLanguage): ?array
    {
        $lang = $this->languageResolver->getCurrentLanguage();
        $key = 'vragenai_'.$kind.'_'.md5($lang.'|'.wp_json_encode($cacheParams));

        $cached = get_transient($key);
        if (is_array($cached) && isset($cached['ids'], $cached['total'])) {
            /** @var array{ids: list<int>, total: int} $cached */
            return $cached;
        }

        $response = $fetch();
        if (is_wp_error($response)) {
            return null;
        }

        $mapped = $this->mapResults($response, $lang, $strictLanguage);
        set_transient($key, $mapped, $ttl);

        return $mapped;
    }

    /**
     * Map a JSON:API collection to ordered, de-duplicated post IDs.
     *
     * @param  array<string, mixed>  $response
     * @return array{ids: list<int>, total: int}
     */
    private function mapResults(array $response, string $language, bool $strictLanguage): array
    {
        $ids = [];

        foreach ((array) ($response['data'] ?? []) as $item) {
            $attributes = (array) ($item['attributes'] ?? []);
            $postId = $this->resolvePostId((string) ($attributes['external_reference'] ?? ''), $language, $strictLanguage);

            if ($postId !== null) {
                $ids[$postId] = $postId;
            }
        }

        $meta = (array) ($response['meta'] ?? []);

        return [
            'ids' => array_values($ids),
            'total' => (int) ($meta['total'] ?? count($ids)),
        ];
    }

    /**
     * Resolve one external reference to the post ID to display, honouring the
     * visitor's language. Returns null for foreign references or when no
     * suitable translation exists.
     */
    private function resolvePostId(string $ref, string $language, bool $strictLanguage): ?int
    {
        if (! str_starts_with($ref, self::REF_PREFIX)) {
            return null;
        }

        $canonicalId = (int) substr($ref, strlen(self::REF_PREFIX));
        if ($canonicalId <= 0) {
            return null;
        }

        $resolved = $this->languageResolver->getTranslationInLanguage($canonicalId, $language);
        if ($resolved !== null) {
            return $resolved;
        }

        // No translation in the current language: fall back to the canonical
        // (default-language) post unless results are constrained to the
        // current language.
        return $strictLanguage ? null : $canonicalId;
    }

    private function isConfigured(): bool
    {
        $creds = ApiClient::credentials();

        return $creds['customer'] !== '' && $creds['token'] !== '';
    }
}
