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

        // save_post fires for the parent only; re-evaluate its attachments, whose indexability follows it.
        if ($post->post_type !== 'attachment' && in_array('attachment', $enabledTypes, true)) {
            $this->reevaluateChildAttachments($postId);
        }

        if (! in_array($post->post_type, $enabledTypes, true)) {
            return;
        }

        if ($this->shouldSyncPost($post)) {
            as_schedule_single_action(time(), 'vragenai_sync_post', ['post_id' => $postId], 'vragenai');
        } else {
            // No longer syncable: drop any document an earlier publish left (no-op if never synced).
            as_schedule_single_action(time(), 'vragenai_delete_document', [$this->buildRemovalPayload($post)], 'vragenai');
        }
    }

    public function onDeletePost(int $postId): void
    {
        // before_delete_post fires before the row is gone, so translations still resolve.
        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            return;
        }

        as_schedule_single_action(time(), 'vragenai_delete_document', [$this->buildRemovalPayload($post)], 'vragenai');

        // Attachments outlive their parent's deletion, so re-evaluate them too.
        $settings = (array) get_option('vragenai_settings', []);
        $enabledTypes = (array) ($settings['post_types'] ?? ['post', 'page']);
        if ($post->post_type !== 'attachment' && in_array('attachment', $enabledTypes, true)) {
            $this->reevaluateChildAttachments($postId);
        }
    }

    public function syncPost(int $postId): void
    {
        $post = get_post($postId);
        if (! $post) {
            return;
        }

        // No longer syncable: remove any document it left behind.
        if (! $this->shouldSyncPost($post)) {
            $this->removeTranslation($this->buildRemovalPayload($post));

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
     * Sync a post immediately, bypassing the queue.
     */
    public function syncPostDirectly(\WP_Post $post): void
    {
        // No longer syncable: remove any document it left behind.
        if (! $this->shouldSyncPost($post)) {
            $this->removeTranslation($this->buildRemovalPayload($post));

            return;
        }

        // Honour the force-exclude filter here too, matching syncPost().
        if (! (bool) apply_filters('vragenai_should_index_post', true, $post)) {
            $this->removeTranslation($this->buildRemovalPayload($post));

            return;
        }

        $this->upsertDocument($post);
    }

    // Queue each attachment of a post for a sync; syncPost() self-heals, removing or recreating its document.
    private function reevaluateChildAttachments(int $parentId): void
    {
        $childIds = get_posts([
            'post_type' => 'attachment',
            'post_parent' => $parentId,
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        foreach ((array) $childIds as $childId) {
            as_schedule_single_action(time(), 'vragenai_sync_post', ['post_id' => (int) $childId], 'vragenai');
        }
    }

    private function upsertDocument(\WP_Post $post): void
    {
        $canonicalLanguage = $this->languageResolver->getDefaultLanguage();
        $translations = [$canonicalLanguage => $post->ID];
        $source = $post;
        $externalRef = 'wp_post_'.$post->ID;

        if (! $this->isStandaloneAttachment($post)) {
            // All translations share one document keyed on the canonical (default-language) post.
            $translations = $this->languageResolver->getTranslations($post);
            $canonicalPostId = $this->languageResolver->getCanonicalPostId($post);

            $canonicalPost = get_post($canonicalPostId);
            $source = ($canonicalPost instanceof \WP_Post && $this->shouldSyncPost($canonicalPost))
                ? $canonicalPost
                : $post;

            $externalRef = 'wp_post_'.$canonicalPostId;
        }

        $isAttachment = $this->isStandaloneAttachment($source);
        $author = get_userdata((int) $source->post_author);
        $attachments = $isAttachment ? [] : $this->attachmentExtractor->extract($source);
        $url = $isAttachment ? wp_get_attachment_url($source->ID) : get_permalink($source);
        $mimeType = $isAttachment ? (get_post_mime_type($source) ?: 'application/octet-stream') : 'text/html';

        if (! is_string($url) || $url === '') {
            return;
        }

        $attributes = [
            'external_reference' => $externalRef,
            'url' => $url,
            'mime_type' => $mimeType,
            'title' => $source->post_title,
            'attachments' => $attachments,
            'meta_data' => [
                'author' => $author ? $author->display_name : '',
                'post_type' => $source->post_type,
                'post_format' => get_post_format($source) ?: 'standard',
                'canonical_language' => $canonicalLanguage,
                'languages' => $isAttachment ? [$canonicalLanguage] : ($this->visibleLanguages($translations) ?: [$canonicalLanguage]),
                'published_at' => $source->post_date,
                'modified_at' => $source->post_modified,
                'taxonomies' => $this->getAllTaxonomyTerms($source),
                'featured_image_url' => get_the_post_thumbnail_url($source, 'full') ?: '',
            ],
        ];

        if (! $isAttachment) {
            $attributes['content'] = $this->buildContent($source);
        }

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
     * The languages of a translation group an anonymous visitor can actually
     * see, so an in-progress draft or a password-protected translation is not
     * announced in the knowledge base. Shared by the upsert and removal paths so
     * they advertise the same set.
     *
     * @param  array<string, int>  $translations
     * @return list<string>
     */
    private function visibleLanguages(array $translations): array
    {
        $languages = [];
        foreach ($translations as $language => $postId) {
            $post = get_post($postId);
            if ($post instanceof \WP_Post && PostVisibility::isPublic($post)) {
                $languages[] = (string) $language;
            }
        }

        return $languages;
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
        if ($this->isStandaloneAttachment($post)) {
            $defaultLanguage = $this->languageResolver->getDefaultLanguage();

            return [
                'external_reference' => 'wp_post_'.$post->ID,
                'language' => $defaultLanguage,
                'canonical_language' => $defaultLanguage,
                'remaining_languages' => [],
            ];
        }

        $translations = $this->languageResolver->getTranslations($post);
        $language = $this->languageResolver->getPostLanguage($post);

        return [
            'external_reference' => 'wp_post_'.$this->languageResolver->getCanonicalPostId($post),
            'language' => $language,
            'canonical_language' => $this->languageResolver->getDefaultLanguage(),
            // Only the still-visible translations, so removing one language does
            // not re-advertise a hidden one back into meta_data.languages.
            'remaining_languages' => array_values(array_filter(
                $this->visibleLanguages($translations),
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
        // 'the_content' is a WordPress core filter; applying it renders the stored
        // post content exactly as the front end would before sending it to the API.
        /**
         * Filters the stored post_content before WordPress renders it for indexing.
         *
         * Integrations that extract structured block content separately can remove
         * those blocks here to avoid indexing denied or duplicated rendered output.
         *
         * @param  string  $content  Stored post_content.
         * @param  \WP_Post  $post  The post being synced.
         */
        $content = (string) apply_filters('vragenai_document_render_content_source', $post->post_content, $post);

        $body = apply_filters('the_content', $content); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

        /**
         * Filters additional HTML fragments that should be indexed for a post.
         *
         * Use this for content stored outside post_content, such as ACF fields,
         * page-builder metadata, or other custom field content. Fragments are
         * appended after the rendered WordPress content.
         *
         * @param  mixed  $fragments  Additional HTML/text fragments.
         * @param  \WP_Post  $post  The post being synced.
         */
        $extraFragments = apply_filters('vragenai_document_content_fragments', [], $post);
        $fragments = [$body];
        foreach (is_array($extraFragments) ? $extraFragments : [$extraFragments] as $fragment) {
            if (is_scalar($fragment) || $fragment instanceof \Stringable) {
                $fragments[] = (string) $fragment;
            }
        }

        $fragments = array_filter(
            array_map('trim', $fragments),
            static fn (string $fragment): bool => $fragment !== '',
        );

        $body = implode("\n\n", $fragments);

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
            error_log('[vragen.ai] '.$context.': '.$error->get_error_message()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- guarded debug logging, only active when WP_DEBUG_LOG is enabled.
        }
    }

    private function isStandaloneAttachment(\WP_Post $post): bool
    {
        return $post->post_type === 'attachment';
    }

    private function shouldSyncPost(\WP_Post $post): bool
    {
        if ($this->isStandaloneAttachment($post)) {
            return $this->shouldSyncAttachment($post);
        }

        // Index only published, unprotected posts. The post type is already gated
        // by the admin's enabled-types choice, so (unlike the read paths) we do
        // not re-check post-type viewability here. The membership veto can narrow
        // this, but the hard gate sits outside it so a filter cannot widen a
        // hidden post into the index.
        return $post->post_status === 'publish'
            && $post->post_password === ''
            && PostVisibility::vetoable(true, $post);
    }

    // An attachment's own status is not its parent's visibility, so gate on the parent;
    // an attachment may also carry its own password. Parentless uploads sync unless protected.
    // The membership veto is applied to the attachment itself, matching the read paths.
    private function shouldSyncAttachment(\WP_Post $post): bool
    {
        $syncable = in_array($post->post_status, ['inherit', 'publish'], true)
            && $post->post_password === ''
            && ($post->post_parent === 0 || $this->parentIsSyncable($post->post_parent));

        return $syncable && PostVisibility::vetoable(true, $post);
    }

    private function parentIsSyncable(int $parentId): bool
    {
        $parent = get_post($parentId);

        return $parent instanceof \WP_Post && $this->shouldSyncPost($parent);
    }
}
