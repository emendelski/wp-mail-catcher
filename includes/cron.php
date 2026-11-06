<?php
/**
 * Daily removal of messages older than the retention setting.
 *
 * @package frontlabs-mail-catcher
 */

defined( 'ABSPATH' ) || exit;

const FMC_PRUNE_HOOK = 'fmc_prune';

/**
 * Schedule the daily run if it is not scheduled yet.
 */
function fmc_schedule_prune() {
	if ( ! wp_next_scheduled( FMC_PRUNE_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', FMC_PRUNE_HOOK );
	}
}

// Also on admin_init, in case the plugin's files arrived without activation
// (a deploy, or a database copied from another server with the plugin active).
add_action( 'admin_init', 'fmc_schedule_prune' );

/**
 * Delete what is past retention.
 */
function fmc_run_prune() {
	fmc_store_prune( fmc_get_retention_days() );
}
add_action( FMC_PRUNE_HOOK, 'fmc_run_prune' );
