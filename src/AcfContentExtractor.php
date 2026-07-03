<?php

namespace VragenAI;

class AcfContentExtractor
{
    private const SUPPORTED_FIELD_TYPES = [
        'flexible_content',
        'group',
        'repeater',
        'text',
        'textarea',
        'wysiwyg',
    ];

    public function register(): void
    {
        add_filter('vragenai_document_render_content_source', [$this, 'stripAcfBlocksFromRenderSource'], 10, 2);
        add_filter('vragenai_document_content_fragments', [$this, 'contentFragments'], 10, 2);
    }

    public function stripAcfBlocksFromRenderSource(string $content, \WP_Post $post): string
    {
        if (! function_exists('parse_blocks') || ! function_exists('serialize_blocks') || trim($content) === '') {
            return $content;
        }

        return serialize_blocks($this->withoutAcfBlocks(parse_blocks($content)));
    }

    /**
     * @param  list<string>|string  $fragments
     * @return list<string>
     */
    public function contentFragments(array|string $fragments, \WP_Post $post): array
    {
        $fragments = is_array($fragments) ? $fragments : [$fragments];
        $acfContent = $this->extract($post);

        if ($acfContent !== '') {
            $fragments[] = $acfContent;
        }

        return $fragments;
    }

    public function extract(\WP_Post $post): string
    {
        $html = $this->extractFieldObjects($post);
        $html .= $this->extractBlockData($post);

        if ($html === '') {
            return '';
        }

        return '<section data-vragenai-source="acf">'.$html.'</section>';
    }

    private function extractFieldObjects(\WP_Post $post): string
    {
        if (! function_exists('get_field_objects')) {
            return '';
        }

        $fields = get_field_objects($post->ID);
        if (! is_array($fields) || $fields === []) {
            return '';
        }

        /**
         * Filters the ACF fields inspected for document content.
         *
         * @param  array<string, array<string, mixed>>  $fields  ACF field objects keyed by field name.
         * @param  \WP_Post  $post  The post being synced.
         */
        $fields = (array) apply_filters('vragenai_acf_content_fields', $fields, $post);

        $html = '';
        foreach ($fields as $field) {
            $html .= $this->renderField($field, $field['value'] ?? null, $post, 2);
        }

        return $html;
    }

    private function extractBlockData(\WP_Post $post): string
    {
        if (! function_exists('parse_blocks') || trim($post->post_content) === '') {
            return '';
        }

        /**
         * Filters ACF block names excluded from document content.
         *
         * @param  list<string>  $denylist  Block names such as "acf/newsletter-cta".
         * @param  \WP_Post  $post  The post being synced.
         */
        $denylist = array_values(array_filter((array) apply_filters('vragenai_acf_block_denylist', [], $post), 'is_string'));

        return $this->renderAcfBlocks(parse_blocks($post->post_content), $post, $denylist);
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function withoutAcfBlocks(array $blocks): array
    {
        $filtered = [];

        foreach ($blocks as $block) {
            $blockName = (string) ($block['blockName'] ?? '');
            if (str_starts_with($blockName, 'acf/')) {
                continue;
            }

            $filtered[] = $block;
        }

        return $filtered;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<string>  $denylist
     */
    private function renderAcfBlocks(array $blocks, \WP_Post $post, array $denylist): string
    {
        $html = '';

        foreach ($blocks as $block) {
            $blockName = (string) ($block['blockName'] ?? '');

            if (str_starts_with($blockName, 'acf/') && $this->shouldIncludeBlock($block, $post, $denylist)) {
                $data = $block['attrs']['data'] ?? [];
                if (is_array($data)) {
                    $html .= $this->renderAcfBlockData($blockName, $data, $post);
                }
            }

            $innerBlocks = $block['innerBlocks'] ?? [];
            if (is_array($innerBlocks) && $innerBlocks !== []) {
                $html .= $this->renderAcfBlocks($innerBlocks, $post, $denylist);
            }
        }

        return $html;
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  list<string>  $denylist
     */
    private function shouldIncludeBlock(array $block, \WP_Post $post, array $denylist): bool
    {
        $blockName = (string) ($block['blockName'] ?? '');
        $include = ! in_array($blockName, $denylist, true);

        /**
         * Filters whether an ACF block instance should be included in document content.
         *
         * @param  bool  $include  Whether to include the block.
         * @param  array<string, mixed>  $block  Parsed block data from parse_blocks().
         * @param  int  $postId  The post ID being synced.
         */
        return (bool) apply_filters('vragenai_include_acf_block', $include, $block, $post->ID);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderAcfBlockData(string $blockName, array $data, \WP_Post $post): string
    {
        $body = '';

        foreach ($data as $key => $value) {
            if (str_starts_with($key, '_') || $key === 'acf_fc_layout') {
                continue;
            }

            $field = $this->blockFieldObject($data, $key, $post);
            $value = $this->blockFieldValue($key, $value, $post);

            if ($field !== null) {
                if ($this->isUnexpandedLayoutField($field, $value)) {
                    continue;
                }

                $field['value'] = $value;
                $body .= $this->renderField($field, $value, $post, 3);

                continue;
            }

            $body .= $this->renderMixed($value);
        }

        if ($body === '') {
            return '';
        }

        return sprintf(
            '<section data-acf-block="%s">%s</section>',
            esc_attr($blockName),
            $body
        );
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function renderField(array $field, mixed $value, \WP_Post $post, int $headingLevel): string
    {
        if ($this->isEmpty($value) || ! $this->shouldIncludeField($field, $value, $post)) {
            return '';
        }

        $type = (string) ($field['type'] ?? '');
        $body = match ($type) {
            'wysiwyg' => $this->renderWysiwyg((string) $value),
            'textarea' => $this->renderTextarea((string) $value),
            default => $this->renderMixed($value, $this->subFieldsByName($field), $post, $headingLevel + 1),
        };

        if ($body === '') {
            return '';
        }

        $label = trim(wp_strip_all_tags((string) ($field['label'] ?? '')));
        if ($label === '') {
            return $body;
        }

        $headingLevel = max(2, min(6, $headingLevel));

        return sprintf(
            '<section data-acf-field="%s"><h%d>%s</h%d>%s</section>',
            esc_attr((string) ($field['name'] ?? '')),
            $headingLevel,
            esc_html($label),
            $headingLevel,
            $body
        );
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function shouldIncludeField(array $field, mixed $value, \WP_Post $post): bool
    {
        $include = in_array((string) ($field['type'] ?? ''), self::SUPPORTED_FIELD_TYPES, true);

        /**
         * Filters whether an ACF field should be included in document content.
         *
         * @param  bool  $include  Whether to include the field.
         * @param  array<string, mixed>  $field  ACF field object.
         * @param  mixed  $value  Field value.
         * @param  int  $postId  The post ID being synced.
         */
        return (bool) apply_filters('vragenai_include_acf_field', $include, $field, $value, $post->ID);
    }

    /**
     * @param  array<string, array<string, mixed>>  $subFields
     */
    private function renderMixed(mixed $value, array $subFields = [], ?\WP_Post $post = null, int $headingLevel = 3): string
    {
        if ($this->isEmpty($value)) {
            return '';
        }

        if (is_scalar($value)) {
            $text = trim((string) $value);

            return $text === '' ? '' : '<p>'.esc_html($text).'</p>';
        }

        if (! is_array($value)) {
            return '';
        }

        if ($this->isList($value)) {
            return $this->renderList($value, $subFields, $post, $headingLevel);
        }

        $html = '';
        foreach ($value as $key => $item) {
            if (! is_string($key) || str_starts_with($key, '_') || str_starts_with($key, 'field_') || $key === 'acf_fc_layout') {
                continue;
            }

            if ($post instanceof \WP_Post && isset($subFields[$key])) {
                $field = $subFields[$key];
                $field['value'] = $item;
                $html .= $this->renderField($field, $item, $post, $headingLevel);

                continue;
            }

            $html .= $this->renderMixed($item, [], $post, $headingLevel);
        }

        return $html;
    }

    /**
     * @param  array<int|string, mixed>  $items
     * @param  array<string, array<string, mixed>>  $subFields
     */
    private function renderList(array $items, array $subFields, ?\WP_Post $post, int $headingLevel): string
    {
        $html = '';
        $simpleItems = [];

        foreach ($items as $item) {
            if ($this->isEmpty($item)) {
                continue;
            }

            if (is_scalar($item)) {
                $simpleItems[] = trim((string) $item);

                continue;
            }

            $html .= $this->renderMixed($item, $subFields, $post, $headingLevel);
        }

        $simpleItems = array_values(array_filter($simpleItems, static fn (string $item): bool => $item !== ''));
        if ($simpleItems !== []) {
            $html = '<ul>'.implode('', array_map(
                static fn (string $item): string => '<li>'.esc_html($item).'</li>',
                $simpleItems
            )).'</ul>'.$html;
        }

        return $html;
    }

    private function renderWysiwyg(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $html = apply_filters('the_content', $value); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

        return wp_kses_post($html);
    }

    private function renderTextarea(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return '<p>'.nl2br(esc_html($value)).'</p>';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function blockFieldObject(array $data, string $name, \WP_Post $post): ?array
    {
        $fieldKey = $data['_'.$name] ?? null;
        if (! is_string($fieldKey) || ! function_exists('get_field_object')) {
            return null;
        }

        $field = get_field_object($fieldKey, $post->ID, false, false);

        return is_array($field) ? $field : null;
    }

    private function blockFieldValue(string $name, mixed $value, \WP_Post $post): mixed
    {
        if (! $this->isEmpty($value) || ! function_exists('get_field')) {
            return $value;
        }

        $stored = get_field($name, $post->ID);

        return $this->isEmpty($stored) ? $value : $stored;
    }

    /**
     * ACF block data can store repeaters/flexible layouts as a row count while
     * each subfield is stored separately with flattened row keys. Index the
     * subfields, not the container count.
     *
     * @param  array<string, mixed>  $field
     */
    private function isUnexpandedLayoutField(array $field, mixed $value): bool
    {
        if (is_array($value)) {
            return false;
        }

        return in_array((string) ($field['type'] ?? ''), ['flexible_content', 'group', 'repeater'], true);
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, array<string, mixed>>
     */
    private function subFieldsByName(array $field): array
    {
        $fields = [];

        foreach ((array) ($field['sub_fields'] ?? []) as $subField) {
            if (is_array($subField) && isset($subField['name'])) {
                $fields[(string) $subField['name']] = $subField;
            }
        }

        foreach ((array) ($field['layouts'] ?? []) as $layout) {
            if (! is_array($layout)) {
                continue;
            }

            foreach ((array) ($layout['sub_fields'] ?? []) as $subField) {
                if (is_array($subField) && isset($subField['name'])) {
                    $fields[(string) $subField['name']] = $subField;
                }
            }
        }

        return $fields;
    }

    /**
     * @param  array<int|string, mixed>  $value
     */
    private function isList(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || $value === false;
    }
}
