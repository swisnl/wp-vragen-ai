<?php

namespace VragenAI;

class Admin
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_post_vragenai_bulk_sync', [$this, 'bulkSync']);
    }

    public function addMenuPage(): void
    {
        add_options_page(
            __('Vragen.ai', 'vragen-ai'),
            __('Vragen.ai', 'vragen-ai'),
            'manage_options',
            'vragenai',
            [$this, 'renderSettingsPage']
        );
    }

    public function registerSettings(): void
    {
        register_setting('vragenai', 'vragenai_settings', [
            'sanitize_callback' => [$this, 'sanitizeSettings'],
        ]);
    }

    /**
     * @return array{customer: string, token: string, post_types: list<string>, global_embed_deployment: string}
     */
    public function sanitizeSettings(mixed $input): array
    {
        $existing = (array) get_option('vragenai_settings', []);

        return [
            'customer' => ! empty($input['customer'])
                ? sanitize_text_field($input['customer'])
                : (string) ($existing['customer'] ?? ''),
            'token' => ! empty($input['token'])
                ? sanitize_text_field($input['token'])
                : (string) ($existing['token'] ?? ''),
            'post_types' => array_map('sanitize_key', (array) ($input['post_types'] ?? [])),
            'global_embed_deployment' => $this->sanitizeGlobalEmbed($input['global_embed_deployment'] ?? ''),
        ];
    }

    /**
     * Sanitize the site-wide embed slug. When the deployment list is available,
     * only overlay (popup/popover) build types are allowed — a page-type
     * deployment makes no sense site-wide. Unknown slugs (list unavailable) pass
     * through so the feature still works when the API can't be reached.
     */
    private function sanitizeGlobalEmbed(mixed $value): string
    {
        $slug = (string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) $value);
        if ($slug === '') {
            return '';
        }

        foreach (Embed::deploymentList() as $deployment) {
            if ($deployment['slug'] === $slug) {
                return in_array($deployment['build_type'], Embed::GLOBAL_BUILD_TYPES, true) ? $slug : '';
            }
        }

        return $slug;
    }

    public function renderSettingsPage(): void
    {
        $settings = (array) get_option('vragenai_settings', []);
        $postTypes = get_post_types(['public' => true], 'objects');
        $enabledTypes = (array) ($settings['post_types'] ?? ['post', 'page']);
        $customerManaged = defined('VRAGENAI_CUSTOMER');
        $tokenManaged = defined('VRAGENAI_TOKEN');
        $creds = ApiClient::credentials();
        $configured = $creds['customer'] !== '' && $creds['token'] !== '';
        $connection = $configured ? $this->checkConnection() : null;
        $synced = isset($_GET['synced']) ? (int) $_GET['synced'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $globalSlug = (string) ($settings['global_embed_deployment'] ?? '');
        $globalDeployments = $configured ? Embed::deploymentList(Embed::GLOBAL_BUILD_TYPES) : [];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Vragen.ai instellingen', 'vragen-ai'); ?></h1>

            <?php if ($connection !== null) { ?>
                <?php if (is_wp_error($connection)) { ?>
                    <div class="notice notice-error"><p>
                        <?php echo esc_html(sprintf(
                            /* translators: %s: API error message */
                            __('Verbinding met vragen.ai mislukt: %s', 'vragen-ai'),
                            $connection->get_error_message()
                        )); ?>
                    </p></div>
                <?php } else { ?>
                    <div class="notice notice-success"><p>
                        <?php esc_html_e('Verbonden met vragen.ai.', 'vragen-ai'); ?>
                    </p></div>
                <?php } ?>
            <?php } ?>

            <?php if ($synced !== null) { ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php echo esc_html(sprintf(
                        /* translators: %d: number of posts queued */
                        __('%d posts in de wachtrij geplaatst voor synchronisatie.', 'vragen-ai'),
                        $synced
                    )); ?>
                </p></div>
            <?php } ?>

            <form method="post" action="options.php">
                <?php settings_fields('vragenai'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="vragenai_customer"><?php esc_html_e('Klantnaam', 'vragen-ai'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="vragenai_customer" name="vragenai_settings[customer]"
                                   value="<?php echo esc_attr($customerManaged ? $creds['customer'] : ($settings['customer'] ?? '')); ?>"
                                   class="regular-text" placeholder="jouw-organisatie"
                                   <?php disabled($customerManaged); ?> />
                            <?php if ($customerManaged) { ?>
                                <p class="description"><?php esc_html_e('Ingesteld via de constante VRAGENAI_CUSTOMER in wp-config.php.', 'vragen-ai'); ?></p>
                            <?php } else { ?>
                                <p class="description"><?php echo esc_html(sprintf(
                                    /* translators: %s: API root domain, e.g. vragen.ai */
                                    __('Subdomein van {klantnaam}.%s', 'vragen-ai'),
                                    ApiClient::domain()
                                )); ?></p>
                            <?php } ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="vragenai_token"><?php esc_html_e('API-token', 'vragen-ai'); ?></label>
                        </th>
                        <td>
                            <input type="password" id="vragenai_token" name="vragenai_settings[token]"
                                   value="" class="regular-text" autocomplete="new-password"
                                   <?php disabled($tokenManaged); ?> />
                            <?php if ($tokenManaged) { ?>
                                <p class="description"><?php esc_html_e('Ingesteld via de constante VRAGENAI_TOKEN in wp-config.php.', 'vragen-ai'); ?></p>
                            <?php } elseif (! empty($settings['token'])) { ?>
                                <p class="description"><?php esc_html_e('Er is een token opgeslagen. Laat dit veld leeg om het te behouden.', 'vragen-ai'); ?></p>
                            <?php } else { ?>
                                <p class="description"><?php esc_html_e('Het API-token van vragen.ai.', 'vragen-ai'); ?></p>
                            <?php } ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Te synchroniseren contenttypen', 'vragen-ai'); ?></th>
                        <td>
                            <?php foreach ($postTypes as $type) { ?>
                                <label style="display:block;margin-bottom:4px">
                                    <input type="checkbox" name="vragenai_settings[post_types][]"
                                           value="<?php echo esc_attr($type->name); ?>"
                                           <?php checked(in_array($type->name, $enabledTypes, true)); ?> />
                                    <?php echo esc_html($type->labels->singular_name); ?>
                                    (<code><?php echo esc_html($type->name); ?></code>)
                                </label>
                            <?php } ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="vragenai_global_embed"><?php esc_html_e('Globale embed', 'vragen-ai'); ?></label>
                        </th>
                        <td>
                            <?php if ($globalDeployments !== []) { ?>
                                <select id="vragenai_global_embed" name="vragenai_settings[global_embed_deployment]">
                                    <option value=""><?php esc_html_e('— Geen —', 'vragen-ai'); ?></option>
                                    <?php foreach ($globalDeployments as $deployment) { ?>
                                        <option value="<?php echo esc_attr($deployment['slug']); ?>" <?php selected($globalSlug, $deployment['slug']); ?>>
                                            <?php echo esc_html($deployment['name'].' ('.$deployment['build_type'].')'); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            <?php } else { ?>
                                <input type="text" id="vragenai_global_embed" name="vragenai_settings[global_embed_deployment]"
                                       value="<?php echo esc_attr($globalSlug); ?>"
                                       class="regular-text" placeholder="my-deployment" />
                            <?php } ?>
                            <p class="description"><?php esc_html_e('Optioneel. Laadt een popup- of popover-deployment site-breed. Laat leeg om uit te schakelen. Gebruik voor pagina-embeds het blok "Vragen.ai embed".', 'vragen-ai'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Instellingen opslaan', 'vragen-ai')); ?>
            </form>

            <hr>
            <h2><?php esc_html_e('Bulk-synchronisatie', 'vragen-ai'); ?></h2>
            <p><?php esc_html_e('Synchroniseer alle gepubliceerde content naar vragen.ai. Posts worden via de wachtrij verwerkt.', 'vragen-ai'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="vragenai_bulk_sync" />
                <?php wp_nonce_field('vragenai_bulk_sync'); ?>
                <?php submit_button(__('Bulk-synchronisatie starten', 'vragen-ai'), 'secondary'); ?>
            </form>
        </div>
        <?php
    }

    public function bulkSync(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Geen toegang.', 'vragen-ai'));
        }
        check_admin_referer('vragenai_bulk_sync');

        $settings = (array) get_option('vragenai_settings', []);
        $enabledTypes = (array) ($settings['post_types'] ?? ['post', 'page']);

        $ids = get_posts([
            'post_type' => $enabledTypes,
            'post_status' => ['publish', 'inherit'],
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        foreach ($ids as $postId) {
            as_schedule_single_action(time(), 'vragenai_sync_post', ['post_id' => $postId], 'vragenai');
        }

        wp_safe_redirect(add_query_arg(
            ['page' => 'vragenai', 'synced' => count($ids)],
            admin_url('options-general.php')
        ));
        exit;
    }

    /**
     * Probe the API with the effective credentials to confirm they work.
     * The successful response is cached for an hour, keyed on the credentials.
     *
     * @return array<string, mixed>|\WP_Error
     */
    private function checkConnection(): array|\WP_Error
    {
        $creds = ApiClient::credentials();

        $cacheKey = 'vragenai_systems_'.md5($creds['customer'].'|'.$creds['token']);
        $cached = get_transient($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        $result = ApiClient::fromSettings()->getSystems();

        if (! is_wp_error($result)) {
            set_transient($cacheKey, $result, HOUR_IN_SECONDS);
        }

        return $result;
    }
}
