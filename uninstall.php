<?php
/**
 * Remove everything this plugin stored: the message table, its options, the cron event.
 *
 * Names are written out literally: the plugin's own files are not loaded
 * during uninstall, so none of its constants exist here.
 *
 * @package frontlabs-mail-catcher
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'fmc_messages' ) );

foreach ( array( 'fmc_mode', 'fmc_test_addresses', 'fmc_retention_days', 'fmc_db_version' ) as $option ) {
	delete_option( $option );
}

wp_clear_scheduled_hook( 'fmc_prune' );
