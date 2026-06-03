<?php
/**
 * Plugin Name: Vragen.ai
 * Description: Synchronises WordPress content with the vragen.ai knowledge base.
 * Version:     2.0.0
 * Author:      SWIS
 * License:     GPL-2.0-or-later
 * Text Domain: vragen-ai
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

define('VRAGENAI_VERSION', '2.0.0');
define('VRAGENAI_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once VRAGENAI_PLUGIN_DIR . 'vendor/autoload.php';

add_action('plugins_loaded', static function (): void {
    load_plugin_textdomain('vragen-ai', false, dirname(plugin_basename(__FILE__)) . '/languages');

    $settings = (array) get_option('vragenai_settings', []);
    $client   = new \VragenAI\ApiClient($settings['customer'] ?? '', $settings['token'] ?? '');

    (new \VragenAI\DocumentSync($client))->register();

    if (is_admin()) {
        (new \VragenAI\Admin())->register();
    }

    if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::add_command('vragenai', \VragenAI\Cli\SyncCommand::class);
    }
});
