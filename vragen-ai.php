<?php

use VragenAI\Admin;
use VragenAI\ApiClient;
use VragenAI\Cli\SyncCommand;
use VragenAI\DocumentSync;
use VragenAI\Embed;
use VragenAI\Search\NativeSearch;
use VragenAI\Search\Related;
use VragenAI\Search\SearchService;

/**
 * Plugin Name:       Vragen.ai
 * Description:       Synchronises WordPress content with the vragen.ai knowledge base.
 * Version:           2.1.0
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
define('VRAGENAI_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once VRAGENAI_PLUGIN_DIR.'vendor/autoload.php';

require_once VRAGENAI_PLUGIN_DIR.'vendor/woocommerce/action-scheduler/action-scheduler.php';

add_action('init', static function (): void {
    load_plugin_textdomain('vragen-ai', false, dirname(plugin_basename(__FILE__)).'/languages');
});

add_action('plugins_loaded', static function (): void {
    (new DocumentSync(ApiClient::fromSettings()))->register();
    (new Embed)->register();
    (new NativeSearch(new SearchService(ApiClient::fromSettings())))->register();
    (new Related(new SearchService(ApiClient::fromSettings())))->register();

    if (is_admin()) {
        (new Admin)->register();
    }

    if (defined('WP_CLI') && WP_CLI) {
        WP_CLI::add_command('vragenai', SyncCommand::class);
    }
});
