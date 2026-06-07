<?php

use VragenAI\Admin;
use VragenAI\ApiClient;
use VragenAI\Cli\SyncCommand;
use VragenAI\DocumentSync;

/**
 * Plugin Name:       Vragen.ai
 * Description:       Synchronises WordPress content with the vragen.ai knowledge base.
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            SWIS
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vragen-ai
 * Domain Path:       /languages
 */
defined('ABSPATH') || exit;

define('VRAGENAI_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once VRAGENAI_PLUGIN_DIR.'vendor/autoload.php';

// Action Scheduler does not self-load via the Composer autoloader; its bootstrap
// file must be required so as_schedule_single_action() and friends are defined.
require_once VRAGENAI_PLUGIN_DIR.'vendor/woocommerce/action-scheduler/action-scheduler.php';

// Kept for WP 6.0–6.6, which don't auto-load bundled translations, this runs on init to avoid the 6.7+ early-load notice.
add_action('init', static function (): void {
    load_plugin_textdomain('vragen-ai', false, dirname(plugin_basename(__FILE__)).'/languages');
});

add_action('plugins_loaded', static function (): void {
    (new DocumentSync(ApiClient::fromSettings()))->register();

    if (is_admin()) {
        (new Admin)->register();
    }

    if (defined('WP_CLI') && WP_CLI) {
        WP_CLI::add_command('vragenai', SyncCommand::class);
    }
});
