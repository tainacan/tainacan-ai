<?php
/**
 * Uninstall - Remove plugin data
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Remove options
delete_option('tainacan_ai_options');
delete_option('tainacan_ai_db_version');

// Remove collection prompt post meta
$wpdb->query(
    "DELETE FROM {$wpdb->postmeta}
    WHERE meta_key LIKE 'tainacan_ai_prompt_%'"
);

// Remove per-metadatum extraction opt-out flags
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
        'tainacan_ai_exclude'
    )
);

// Remove transients (cache)
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_tainacan_ai_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_tainacan_ai_%'");
