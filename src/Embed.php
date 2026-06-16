<?php

namespace VragenAI;

class Embed
{
    /** @var list<string> */
    public const GLOBAL_BUILD_TYPES = ['popup', 'popover'];

    public function register(): void
    {
        add_action('init', [$this, 'registerBlock']);
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueGlobalEmbed']);
        add_action('wp_footer', [$this, 'renderGlobalContainer']);
    }

    public function registerRestRoutes(): void
    {
        register_rest_route('vragenai/v1', '/deployments', [
            'methods' => 'GET',
            'callback' => [$this, 'restDeployments'],
            'permission_callback' => static fn (): bool => current_user_can('edit_posts'),
        ]);
    }

    public function restDeployments(): \WP_REST_Response
    {
        return new \WP_REST_Response(self::deploymentList(), 200);
    }

    /**
     * Normalized, cached list of deployments from Vragen.ai. Returns an empty
     * array on any failure (no credentials, API error) so callers degrade to
     * manual entry. Optionally filtered to specific build types.
     *
     * @param  list<string>  $buildTypes  When non-empty, only these build types are returned.
     * @return list<array{slug: string, name: string, build_type: string}>
     */
    public static function deploymentList(array $buildTypes = []): array
    {
        $creds = ApiClient::credentials();
        if ($creds['customer'] === '' || $creds['token'] === '') {
            return [];
        }

        $cacheKey = 'vragenai_deployments_'.md5($creds['customer'].'|'.$creds['token']);
        $cached = get_transient($cacheKey);

        if (! is_array($cached)) {
            $result = ApiClient::fromSettings()->getDeployments();
            if (is_wp_error($result)) {
                return [];
            }

            $cached = [];
            foreach ((array) ($result['data'] ?? []) as $item) {
                $attributes = (array) ($item['attributes'] ?? []);
                $slug = (string) ($attributes['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }
                $cached[] = [
                    'slug' => $slug,
                    'name' => (string) ($attributes['name'] ?? $slug),
                    'build_type' => (string) ($attributes['build_type'] ?? ''),
                ];
            }

            set_transient($cacheKey, $cached, 5 * MINUTE_IN_SECONDS);
        }

        if ($buildTypes === []) {
            return $cached;
        }

        return array_values(array_filter(
            $cached,
            static fn (array $d): bool => in_array($d['build_type'], $buildTypes, true),
        ));
    }

    public function registerBlock(): void
    {
        // Without a customer there is no host to load the embed from, so keep
        // the block out of the inserter until that's configured. (Unlike the
        // related block it doesn't require a token — the editor falls back to
        // manual slug entry when the deployment list can't be fetched.)
        if (ApiClient::credentials()['customer'] === '') {
            return;
        }

        $assetVersion = static function (string $file): string {
            $path = VRAGENAI_PLUGIN_DIR.'blocks/embed/'.$file;

            return file_exists($path) ? (string) filemtime($path) : '2.1.0';
        };

        wp_register_script(
            'vragenai-embed-editor',
            VRAGENAI_PLUGIN_URL.'blocks/embed/edit.js',
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-api-fetch'],
            $assetVersion('edit.js'),
            true
        );
        wp_set_script_translations('vragenai-embed-editor', 'vragen-ai', VRAGENAI_PLUGIN_DIR.'languages');

        wp_register_style(
            'vragenai-embed',
            VRAGENAI_PLUGIN_URL.'blocks/embed/style.css',
            [],
            $assetVersion('style.css')
        );

        register_block_type(VRAGENAI_PLUGIN_DIR.'blocks/embed', [
            'render_callback' => [$this, 'renderBlock'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function renderBlock(array $attributes): string
    {
        $slug = $this->sanitizeSlug((string) ($attributes['deployment'] ?? ''));

        if ($slug === '') {
            return current_user_can('edit_posts')
                ? '<div '.get_block_wrapper_attributes().'><em>'.esc_html__('Vragen.ai: voer een deployment-slug in.', 'vragen-ai').'</em></div>'
                : '';
        }

        wp_enqueue_style('vragenai-embed');

        $containerId = wp_unique_id('vragenai-app-');
        $this->enqueueEmbedScript($slug, $containerId);

        return sprintf(
            '<div %s><div id="%s"></div></div>',
            get_block_wrapper_attributes(),
            esc_attr($containerId)
        );
    }

    public function enqueueGlobalEmbed(): void
    {
        $slug = $this->globalSlug();
        if ($slug === '') {
            return;
        }

        wp_enqueue_style('vragenai-embed');
        $this->enqueueEmbedScript($slug, 'vragenai-app-global');
    }

    public function renderGlobalContainer(): void
    {
        if ($this->globalSlug() === '') {
            return;
        }

        echo '<div id="vragenai-app-global" class="vragenai-embed--global"></div>';
    }

    private function enqueueEmbedScript(string $slug, string $containerId): void
    {
        $host = $this->embedHost();
        if ($host === '') {
            return;
        }

        /**
         * Filters the query args appended to the embed.js URL, e.g. to pass
         * copyOverride or other embed parameters.
         *
         * @param  array<string, scalar>  $args  Extra query args.
         * @param  string  $slug  The deployment slug.
         */
        $args = (array) apply_filters('vragenai_embed_query_args', [], $slug);
        $args['deployment'] = $slug;
        $args['containerSelector'] = '#'.$containerId;

        wp_enqueue_script(
            'vragenai-embed-'.$containerId,
            $host.'/embed.js?'.http_build_query($args),
            [],
            null,
            true
        );
    }

    private function embedHost(): string
    {
        $customer = ApiClient::credentials()['customer'];

        return $customer === '' ? '' : 'https://'.$customer.'.'.ApiClient::domain();
    }

    private function globalSlug(): string
    {
        $settings = (array) get_option('vragenai_settings', []);

        return $this->sanitizeSlug((string) ($settings['global_embed_deployment'] ?? ''));
    }

    private function sanitizeSlug(string $slug): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_-]/', '', $slug);
    }
}
