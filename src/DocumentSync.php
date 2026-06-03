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
        add_action('delete_post', [$this, 'onDeletePost']);
        add_action('vragenai_sync_post', [$this, 'syncPost']);
        add_action('vragenai_delete_document', [$this, 'deleteDocument']);
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
            as_schedule_single_action(time(), 'vragenai_delete_document', ['post_id' => $postId], 'vragenai');
        }
    }

    public function onDeletePost(int $postId): void
    {
        as_schedule_single_action(time(), 'vragenai_delete_document', ['post_id' => $postId], 'vragenai');
    }

    public function syncPost(int $postId): void
    {
        $post = get_post($postId);
        if (! $post || $post->post_status !== 'publish') {
            return;
        }

        $shouldIndex = (bool) apply_filters('vragenai_should_index_post', true, $post);

        if (! $shouldIndex) {
            $this->maybeDeleteExisting($postId);

            return;
        }

        $this->upsertDocument($post);
    }

    public function deleteDocument(int $postId): void
    {
        $this->removeDocument($postId);
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
        $externalRef = 'wp_post_'.$post->ID;
        $language = $this->languageResolver->getPostLanguage($post);
        $canonicalLanguage = $this->languageResolver->getCanonicalLanguage($post);
        $author = get_userdata((int) $post->post_author);
        $attachments = $this->attachmentExtractor->extract($post);

        $attributes = [
            'external_reference' => $externalRef,
            'url' => get_permalink($post),
            'mime_type' => 'text/html',
            'title' => $post->post_title,
            'content' => $this->buildContent($post),
            'meta_data' => [
                'author' => $author ? $author->display_name : '',
                'post_type' => $post->post_type,
                'post_format' => get_post_format($post) ?: 'standard',
                'language' => $language,
                'canonical_language' => $canonicalLanguage,
                'languages' => [$language],
                'published_at' => $post->post_date,
                'modified_at' => $post->post_modified,
                'taxonomies' => $this->getAllTaxonomyTerms($post),
                'featured_image_url' => get_the_post_thumbnail_url($post, 'full') ?: '',
                'attachments' => $attachments,
            ],
        ];

        /**
         * Filters the document attributes before they are sent to vragen.ai.
         *
         * @param  array  $attributes  The document attributes.
         * @param  \WP_Post  $post  The post being indexed.
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

    private function removeDocument(int $postId): void
    {
        $existing = $this->client->findByExternalReference('wp_post_'.$postId);
        if (is_wp_error($existing)) {
            $this->logError('find_for_delete', $existing);

            return;
        }

        $existingId = $existing['data'][0]['id'] ?? null;
        if (! $existingId) {
            return;
        }

        $result = $this->client->deleteDocument($existingId);
        if (is_wp_error($result)) {
            $this->logError('delete', $result);
        }
    }

    private function maybeDeleteExisting(int $postId): void
    {
        $existing = $this->client->findByExternalReference('wp_post_'.$postId);
        if (! is_wp_error($existing) && isset($existing['data'][0]['id'])) {
            $this->client->deleteDocument($existing['data'][0]['id']);
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
