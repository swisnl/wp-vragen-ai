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

        // An attachment's indexability follows its parent, but save_post fires only
        // for the post that changed. When a parent's visibility changes its
        // attachments keep their own documents until something re-evaluates them,
        // so fan out to them here. Guarded to attachments being a synced type,
        // since otherwise they have no standalone document to reconcile.
        if ($post->post_type !== 'attachment' && in_array('attachment', $enabledTypes, true)) {
            $this->reevaluateChildAttachments($postId);
        }

        if (! in_array($post->post_type, $enabledTypes, true)) {
            return;
        }

        if ($this->shouldSyncPost($post)) {
            as_schedule_single_action(time(), 'vragenai_sync_post', ['post_id' => $postId], 'vragenai');
        } else {
            // An enabled-type post that is no longer syncable must drop any document
            // an earlier publish created. This covers every non-syncable transition:
            // trashed, draft, private, pending, scheduled (future) and non-public
            // custom statuses, plus a published post that becomes password-protected
            // (which keeps post_status 'publish', so a status list would miss it).
            // A post that was never synced resolves to no document, so the removal is
            // a harmless no-op. Resolve the translation group now, while the post
            // still exists.
            as_schedule_single_action(time(), 'vragenai_delete_document', [$this->buildRemovalPayload($post)], 'vragenai');
        }
    }

    public function onDeletePost(int $postId): void
    {
        // before_delete_post fires before the row is removed, so translation
        // links are still resolvable here.
        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            return;
        }

        as_schedule_single_action(time(), 'vragenai_delete_document', [$this->buildRemovalPayload($post)], 'vragenai');

        // Attachments outlive their parent's deletion (WordPress orphans them
        // rather than removing them), so their documents would otherwise linger
        // with a now-gone parent. Re-evaluate them; by the time the job runs the
        // parent is gone and the attachment resolves as non-syncable.
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

        // A queued sync for a post that is no longer syncable (unpublished before
        // the job ran, or a bulk sync re-running over it) reconciles by removing
        // any document it left behind.
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
        // Mirror syncPost(): reconcile a no-longer-syncable post by removing any
        // document it left behind, so a full `wp vragenai sync` cleans up.
        if (! $this->shouldSyncPost($post)) {
            $this->removeTranslation($this->buildRemovalPayload($post));

            return;
        }

        // Honour the same force-exclude filter the queued path applies, so a full
        // sync does not re-index a post the site deliberately keeps out.
        if (! (bool) apply_filters('vragenai_should_index_post', true, $post)) {
            $this->removeTranslation($this->buildRemovalPayload($post));

            return;
        }

        $this->upsertDocument($post);
    }

    /**
     * Queue a re-evaluation of every attachment belonging to a post, so a change
     * to the parent's visibility propagates to the attachments' own documents.
     * syncPost() self-heals: it removes the attachment's document when the parent
     * is no longer syncable and (re)creates it when the parent becomes syncable.
     */
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
            // All translations of a post share one document, keyed on the canonical
            // (default-language) post. Content is sourced from that translation;
            // meta_data.languages advertises every language the group is available in.
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
                'languages' => array_keys($translations),
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

        // A password-protected post keeps post_status 'publish' — the protection
        // lives in post_password — so gating on status alone would send its body
        // to the knowledge base.
        return $post->post_status === 'publish' && $post->post_password === '';
    }

    /**
     * An attachment carries its own post_status ('inherit'/'publish'), not its
     * parent's visibility, so gate it on the parent: an attachment of a draft,
     * private or password-protected post must not be indexed. An attachment may
     * also carry its own password, honoured regardless of parent. Parentless
     * (media-library) uploads have no parent gate to inherit and sync as before,
     * unless they carry their own password.
     */
    private function shouldSyncAttachment(\WP_Post $post): bool
    {
        if (! in_array($post->post_status, ['inherit', 'publish'], true)) {
            return false;
        }

        // An attachment can carry its own password, independent of any parent;
        // WordPress treats it as not publicly viewable, so don't index it.
        if ($post->post_password !== '') {
            return false;
        }

        if ($post->post_parent === 0) {
            return true;
        }

        $parent = get_post($post->post_parent);

        return $parent instanceof \WP_Post && $this->shouldSyncPost($parent);
    }
}
