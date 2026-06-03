<?php

namespace VragenAI;

class DocumentSync
{
    private const BLOCK_LEVEL_TAGS = ['<p>', '<div>', '<h1>', '<h2>', '<h3>', '<h4>', '<h5>', '<h6>', '<ul>', '<ol>', '<li>', '<blockquote>', '<table>', '<figure>'];

    public function __construct(
        private readonly ApiClient $client,
        private readonly LanguageResolver $languageResolver = new LanguageResolver,
        private readonly AttachmentExtractor $attachmentExtractor = new AttachmentExtractor,
    ) {}

    public function register(): void
    {
        add_action('save_post', [$this, 'onSavePost'], 10, 2);
        add_action('before_delete_post', [$this, 'onDeletePost']);
        add_action('vragenai_sync_post', [$this, 'syncPost']);
        add_action('vragenai_delete_document', [$this, 'removeTranslation']);
    }

    public function onSavePost(int $postId, \WP_Post $post): void
    {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        $settings = (array) get_option('vragenai_settings', []);
        $enabledTypes = (array) ($settings['post_types'] ?? ['post', 'page']);

        if (! in_array($post->post_type, $enabledTypes, true)) {
            return;
        }

        if ($post->post_status === 'publish') {
            as_schedule_single_action(time(), 'vragenai_sync_post', ['post_id' => $postId], 'vragenai');
        } elseif (in_array($post->post_status, ['trash', 'draft'], true)) {
            // Resolve the translation group now, while the post still exists.
            as_schedule_single_action(time(), 'vragenai_delete_document', [$this->buildRemovalPayload($post)], 'vragenai');
        }
    }

    public function onDeletePost(int $postId): void
    {
        // before_delete_post fires before the row is removed, so translation
        // links are still resolvable here.
        $post = get_post($postId);
        if ($post instanceof \WP_Post) {
            as_schedule_single_action(time(), 'vragenai_delete_document', [$this->buildRemovalPayload($post)], 'vragenai');
        }
    }

    public function syncPost(int $postId): void
    {
        $post = get_post($postId);
        if (! $post || $post->post_status !== 'publish') {
            return;
        }

        $shouldIndex = (bool) apply_filters('vragenai_should_index_post', true, $post);

        if (! $shouldIndex) {
            $this->removeTranslation($this->buildRemovalPayload($post));

            return;
        }

        $this->upsertDocument($post);
    }

    /**
     * Sync a post immediately, bypassing the queue. Used by WP-CLI and bulk sync.
     */
    public function syncPostDirectly(\WP_Post $post): void
    {
        $this->upsertDocument($post);
    }

    private function upsertDocument(\WP_Post $post): void
    {
        // All translations of a post share one document, keyed on the canonical
        // (default-language) post. Content is sourced from that translation;
        // meta_data.languages advertises every language the group is available in.
        $translations = $this->languageResolver->getTranslations($post);
        $canonicalLanguage = $this->languageResolver->getDefaultLanguage();
        $canonicalPostId = $this->languageResolver->getCanonicalPostId($post);

        $canonicalPost = get_post($canonicalPostId);
        $source = ($canonicalPost instanceof \WP_Post && $canonicalPost->post_status === 'publish')
            ? $canonicalPost
            : $post;

        $externalRef = 'wp_post_'.$canonicalPostId;
        $author = get_userdata((int) $source->post_author);
        $attachments = $this->attachmentExtractor->extract($source);

        $attributes = [
            'external_reference' => $externalRef,
            'url' => get_permalink($source),
            'mime_type' => 'text/html',
            'title' => $source->post_title,
            'content' => $this->buildContent($source),
            'meta_data' => [
                'author' => $author ? $author->display_name : '',
                'post_type' => $source->post_type,
                'post_format' => get_post_format($source) ?: 'standard',
                'canonical_language' => $canonicalLanguage,
                'languages' => array_keys($translations),
                'published_at' => $source->post_date,
                'modified_at' => $source->post_modified,
                'taxonomies' => $this->getAllTaxonomyTerms($source),
                'featured_image_url' => get_the_post_thumbnail_url($source, 'full') ?: '',
                'attachments' => $attachments,
            ],
        ];

        /**
         * Filters the document attributes before they are sent to vragen.ai.
         *
         * @param  array  $attributes  The document attributes.
         * @param  \WP_Post  $post  The post that triggered the sync.
         */
        $attributes = (array) apply_filters('vragenai_document_attributes', $attributes, $post);

        $existing = $this->client->findByExternalReference($externalRef);
        if (is_wp_error($existing)) {
            $this->logError('find', $existing);

            return;
        }

        $existingId = $existing['data'][0]['id'] ?? null;

        $result = $existingId
            ? $this->client->updateDocument($existingId, $attributes)
            : $this->client->createDocument($attributes);

        if (is_wp_error($result)) {
            $this->logError('upsert', $result);
        }
    }

    /**
     * Snapshot of the translation group needed to remove one translation.
     * Resolved while the post still exists so the async handler does not
     * depend on it.
     *
     * @return array{external_reference: string, language: string, canonical_language: string, remaining_languages: list<string>}
     */
    private function buildRemovalPayload(\WP_Post $post): array
    {
        $translations = $this->languageResolver->getTranslations($post);
        $language = $this->languageResolver->getPostLanguage($post);

        return [
            'external_reference' => 'wp_post_'.$this->languageResolver->getCanonicalPostId($post),
            'language' => $language,
            'canonical_language' => $this->languageResolver->getDefaultLanguage(),
            'remaining_languages' => array_values(array_filter(
                array_keys($translations),
                static fn (string $lang): bool => $lang !== $language,
            )),
        ];
    }

    /**
     * Remove a translation from its document. Removing the canonical
     * translation (or the last remaining language) deletes the whole
     * document; otherwise the language is dropped from meta_data.languages.
     *
     * @param  array{external_reference?: string, language?: string, canonical_language?: string, remaining_languages?: list<string>}  $payload
     */
    public function removeTranslation(array $payload): void
    {
        $externalRef = (string) ($payload['external_reference'] ?? '');
        if ($externalRef === '') {
            return;
        }

        $existing = $this->client->findByExternalReference($externalRef);
        if (is_wp_error($existing)) {
            $this->logError('find_for_delete', $existing);

            return;
        }

        $existingId = $existing['data'][0]['id'] ?? null;
        if (! $existingId) {
            return;
        }

        $language = (string) ($payload['language'] ?? '');
        $canonicalLanguage = (string) ($payload['canonical_language'] ?? '');
        $remaining = $payload['remaining_languages'] ?? [];

        if ($language === $canonicalLanguage || $remaining === []) {
            $result = $this->client->deleteDocument($existingId);
            if (is_wp_error($result)) {
                $this->logError('delete', $result);
            }

            return;
        }

        $metaData = (array) ($existing['data'][0]['attributes']['meta_data'] ?? []);
        $metaData['languages'] = $remaining;

        $result = $this->client->updateDocument($existingId, ['meta_data' => $metaData]);
        if (is_wp_error($result)) {
            $this->logError('remove_language', $result);
        }
    }

    private function buildContent(\WP_Post $post): string
    {
        $body = apply_filters('the_content', $post->post_content);

        // Mirror Drupal: wrap bare inline content in <p> so the API receives valid HTML.
        if (! $this->hasBlockLevelElements($body)) {
            $body = '<p>'.$body.'</p>';
        }

        return sprintf(
            '<!doctype html><html><head><title>%s</title></head><body>%s</body></html>',
            esc_html($post->post_title),
            $body
        );
    }

    private function hasBlockLevelElements(string $html): bool
    {
        foreach (self::BLOCK_LEVEL_TAGS as $tag) {
            if (str_contains($html, $tag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, list<string>>
     */
    private function getAllTaxonomyTerms(\WP_Post $post): array
    {
        $result = [];
        foreach (get_object_taxonomies($post->post_type, 'names') as $taxonomy) {
            $terms = get_the_terms($post->ID, $taxonomy);
            if ($terms && ! is_wp_error($terms)) {
                $result[$taxonomy] = wp_list_pluck($terms, 'name');
            }
        }

        return $result;
    }

    private function logError(string $context, \WP_Error $error): void
    {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('[vragen.ai] '.$context.': '.$error->get_error_message());
        }
    }
}
