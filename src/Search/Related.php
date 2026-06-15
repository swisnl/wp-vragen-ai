<?php

namespace VragenAI\Search;

/**
 * "Related content" for the current post, powered by the vragen.ai similar
 * endpoint. Exposed as a server-rendered Gutenberg block and a matching
 * [vragenai_related] shortcode. The source post must already be synced;
 * when it isn't (or the API fails) nothing is rendered for visitors.
 */
class Related
{
    private const DEFAULT_COUNT = 4;

    public function __construct(private readonly SearchService $service) {}

    public function register(): void
    {
        add_action('init', [$this, 'registerBlock']);
        add_shortcode('vragenai_related', [$this, 'renderShortcode']);
    }

    public function registerBlock(): void
    {
        // Registered as handles (not file: refs) so the no-build editor script
        // can declare its wp.* dependencies explicitly. Mirrors blocks/embed.
        $assetVersion = static function (string $file): string {
            $path = VRAGENAI_PLUGIN_DIR.'blocks/related/'.$file;

            return file_exists($path) ? (string) filemtime($path) : '2.0.0';
        };

        wp_register_script(
            'vragenai-related-editor',
            VRAGENAI_PLUGIN_URL.'blocks/related/edit.js',
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render', 'wp-data'],
            $assetVersion('edit.js'),
            true
        );
        wp_set_script_translations('vragenai-related-editor', 'vragen-ai', VRAGENAI_PLUGIN_DIR.'languages');

        wp_register_style(
            'vragenai-related',
            VRAGENAI_PLUGIN_URL.'blocks/related/style.css',
            [],
            $assetVersion('style.css')
        );

        register_block_type(VRAGENAI_PLUGIN_DIR.'blocks/related', [
            'render_callback' => [$this, 'renderBlock'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function renderBlock(array $attributes, string $content = '', ?\WP_Block $block = null): string
    {
        $postId = ($block instanceof \WP_Block && isset($block->context['postId']))
            ? (int) $block->context['postId']
            : (int) get_the_ID();

        $count = max(1, (int) ($attributes['numberOfItems'] ?? self::DEFAULT_COUNT));
        $items = $this->resolveItems($postId, $count);

        if ($items === []) {
            // Hint authors in the editor/preview; render nothing for visitors.
            return current_user_can('edit_posts')
                ? '<div '.get_block_wrapper_attributes().'><em>'.esc_html__('Vragen.ai: nog geen gerelateerde content beschikbaar.', 'vragen-ai').'</em></div>'
                : '';
        }

        wp_enqueue_style('vragenai-related');

        $cards = ($attributes['displayStyle'] ?? 'list') === 'cards';

        $heading = (string) ($attributes['title'] ?? '');
        $headingHtml = $heading !== ''
            ? '<h2 class="vragenai-related__heading">'.esc_html($heading).'</h2>'
            : '';

        $listClass = $cards ? 'vragenai-related__list vragenai-related__list--cards' : 'vragenai-related__list';
        $list = '';
        foreach ($items as $item) {
            $list .= $cards ? $this->renderCard($item) : $this->renderListItem($item);
        }

        return sprintf(
            '<div %s>%s<ul class="%s">%s</ul></div>',
            get_block_wrapper_attributes(),
            $headingHtml,
            esc_attr($listClass),
            $list
        );
    }

    /**
     * @param  array{id: int, title: string, url: string}  $item
     */
    private function renderListItem(array $item): string
    {
        return sprintf(
            '<li class="vragenai-related__item"><a class="vragenai-related__link" href="%s">%s</a></li>',
            esc_url($item['url']),
            esc_html($item['title'])
        );
    }

    /**
     * @param  array{id: int, title: string, url: string}  $item
     */
    private function renderCard(array $item): string
    {
        $image = get_the_post_thumbnail_url($item['id'], 'medium');
        $imageHtml = is_string($image) && $image !== ''
            ? sprintf('<img class="vragenai-related__image" src="%s" alt="" loading="lazy" />', esc_url($image))
            : '';

        $excerpt = get_the_excerpt($item['id']);
        $excerptHtml = $excerpt !== ''
            ? '<span class="vragenai-related__excerpt">'.esc_html($excerpt).'</span>'
            : '';

        return sprintf(
            '<li class="vragenai-related__card"><a class="vragenai-related__card-link" href="%s">%s<span class="vragenai-related__card-title">%s</span>%s</a></li>',
            esc_url($item['url']),
            $imageHtml,
            esc_html($item['title']),
            $excerptHtml
        );
    }

    /**
     * Shortcode wrapper around the block renderer for classic-editor and
     * template placement.
     *
     * @param  array<string, mixed>|string  $atts
     */
    public function renderShortcode($atts): string
    {
        $atts = shortcode_atts(
            ['title' => '', 'items' => self::DEFAULT_COUNT, 'layout' => 'list'],
            (array) $atts,
            'vragenai_related'
        );

        return $this->renderBlock([
            'title' => (string) $atts['title'],
            'numberOfItems' => (int) $atts['items'],
            'displayStyle' => $atts['layout'] === 'cards' ? 'cards' : 'list',
        ]);
    }

    /**
     * Resolve the related post IDs to renderable {id, title, url} rows.
     *
     * @return list<array{id: int, title: string, url: string}>
     */
    private function resolveItems(int $postId, int $count): array
    {
        if ($postId <= 0) {
            return [];
        }

        $settings = (array) get_option('vragenai_settings', []);
        $result = $this->service->related($postId, $count, [
            'language_fallback' => (bool) ($settings['search_language_fallback'] ?? true),
        ]);

        if ($result === null) {
            return [];
        }

        $items = [];
        foreach (array_slice($result['ids'], 0, $count) as $id) {
            $url = get_permalink($id);
            if (is_string($url) && $url !== '') {
                $items[] = ['id' => (int) $id, 'title' => (string) get_the_title($id), 'url' => $url];
            }
        }

        return $items;
    }
}
