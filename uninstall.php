<?php
/**
 * Uninstall - Remove plugin data
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove options
delete_option('tainacan_ai_options');
delete_option('tainacan_ai_db_version');

// Remove collection prompt post meta
$wpdb->query(
    "DELETE FROM {$wpdb->postmeta}
    WHERE meta_key LIKE 'tainacan_ai_prompt_%'"
);

// Remove transients (cache)
global $wpdb;
$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_tainacan_ai_%'");
$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_timeout_tainacan_ai_%'");

// Remove custom mappings
$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE 'tainacan_ai_mapping_%'");

// Legacy alpha tables (no longer created on activate)
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}tainacan_ai_logs");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}tainacan_ai_collection_prompts");
