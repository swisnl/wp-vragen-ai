<?php

/**
 * Removes all data the plugin stored when it is deleted.
 */
defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('vragenai_settings');

// Cancel any queued sync/delete actions. Action Scheduler is only available
// here if another active plugin has loaded it; pending actions are otherwise
// harmless once the hooks no longer exist.
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('vragenai_sync_post');
    as_unschedule_all_actions('vragenai_delete_document');
}

// Drop the connection-check transients (keyed on a credentials hash). A direct
// query is used because the transient names are dynamic, so they cannot be
// removed with a single delete_transient() call; caching does not apply to a
// one-off cleanup that runs only on uninstall.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\_transient\_vragenai\_%'
        OR option_name LIKE '\_transient\_timeout\_vragenai\_%'"
);
