<?php

namespace VragenAI;

class Admin
{
    public function register(): void
    {
        add_action('admin_menu',                       [$this, 'addMenuPage']);
        add_action('admin_init',                       [$this, 'registerSettings']);
        add_action('admin_post_vragenai_bulk_sync',    [$this, 'bulkSync']);
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

    public function sanitizeSettings(mixed $input): array
    {
        $existing = (array) get_option('vragenai_settings', []);

        return [
            'customer'          => sanitize_text_field($input['customer'] ?? ''),
            // Preserve the stored token when the password field is submitted empty.
            'token'             => !empty($input['token'])
                ? sanitize_text_field($input['token'])
                : ($existing['token'] ?? ''),
            'system_id'         => sanitize_text_field($input['system_id'] ?? ''),
            'post_types'        => array_map('sanitize_key', (array) ($input['post_types'] ?? [])),
            'language_fallback' => !empty($input['language_fallback']),
        ];
    }

    public function renderSettingsPage(): void
    {
        $settings     = (array) get_option('vragenai_settings', []);
        $postTypes    = get_post_types(['public' => true], 'objects');
        $enabledTypes = (array) ($settings['post_types'] ?? ['post', 'page']);
        $systems      = $this->loadSystems($settings);
        $synced       = isset($_GET['synced']) ? (int) $_GET['synced'] : null;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Vragen.ai instellingen', 'vragen-ai'); ?></h1>

            <?php if ($synced !== null) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php printf(
                        /* translators: %d: number of posts queued */
                        esc_html__('%d posts in de wachtrij geplaatst voor synchronisatie.', 'vragen-ai'),
                        $synced
                    ); ?>
                </p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('vragenai'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="vragenai_customer"><?php esc_html_e('Klantnaam', 'vragen-ai'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="vragenai_customer" name="vragenai_settings[customer]"
                                   value="<?php echo esc_attr($settings['customer'] ?? ''); ?>"
                                   class="regular-text" placeholder="jouw-organisatie" />
                            <p class="description"><?php esc_html_e('Subdomein van {klantnaam}.vragen.ai', 'vragen-ai'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="vragenai_token"><?php esc_html_e('API-token', 'vragen-ai'); ?></label>
                        </th>
                        <td>
                            <input type="password" id="vragenai_token" name="vragenai_settings[token]"
                                   value="<?php echo esc_attr($settings['token'] ?? ''); ?>"
                                   class="regular-text" autocomplete="new-password" />
                        </td>
                    </tr>

                    <?php if (!is_wp_error($systems) && !empty($systems['data'])) : ?>
                    <tr>
                        <th scope="row">
                            <label for="vragenai_system_id"><?php esc_html_e('Zoeksysteem', 'vragen-ai'); ?></label>
                        </th>
                        <td>
                            <select id="vragenai_system_id" name="vragenai_settings[system_id]">
                                <option value=""><?php esc_html_e('— Globaal (geen systeem) —', 'vragen-ai'); ?></option>
                                <?php foreach ($systems['data'] as $system) : ?>
                                    <option value="<?php echo esc_attr($system['id']); ?>"
                                            <?php selected($settings['system_id'] ?? '', $system['id']); ?>>
                                        <?php echo esc_html($system['attributes']['name'] ?? $system['id']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Laat leeg om zonder systeem te zoeken.', 'vragen-ai'); ?></p>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <tr>
                        <th scope="row"><?php esc_html_e('Te synchroniseren contenttypen', 'vragen-ai'); ?></th>
                        <td>
                            <?php foreach ($postTypes as $type) : ?>
                                <label style="display:block;margin-bottom:4px">
                                    <input type="checkbox" name="vragenai_settings[post_types][]"
                                           value="<?php echo esc_attr($type->name); ?>"
                                           <?php checked(in_array($type->name, $enabledTypes, true)); ?> />
                                    <?php echo esc_html($type->labels->singular_name); ?>
                                    (<code><?php echo esc_html($type->name); ?></code>)
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Taalfallback', 'vragen-ai'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="vragenai_settings[language_fallback]" value="1"
                                       <?php checked(!empty($settings['language_fallback'])); ?> />
                                <?php esc_html_e('Val terug op de canonieke taal als de voorkeurtaal niet beschikbaar is.', 'vragen-ai'); ?>
                            </label>
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
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Geen toegang.', 'vragen-ai'));
        }
        check_admin_referer('vragenai_bulk_sync');

        $settings     = (array) get_option('vragenai_settings', []);
        $enabledTypes = (array) ($settings['post_types'] ?? ['post', 'page']);

        $ids = get_posts([
            'post_type'      => $enabledTypes,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
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

    private function loadSystems(array $settings): array|\WP_Error
    {
        if (empty($settings['customer']) || empty($settings['token'])) {
            return ['data' => []];
        }

        $cacheKey = 'vragenai_systems_' . md5($settings['customer'] . $settings['token']);
        $cached   = get_transient($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        $result = (new ApiClient($settings['customer'], $settings['token']))->getSystems();

        if (!is_wp_error($result)) {
            set_transient($cacheKey, $result, HOUR_IN_SECONDS);
        }

        return $result;
    }
}
