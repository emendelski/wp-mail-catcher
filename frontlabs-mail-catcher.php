<?php
/**
 * Plugin Name:       FrontLabs Mail Catcher
 * Plugin URI:        https://frontlabs.pl/
 * Description:       Records every message sent through wp_mail() and shows it in wp-admin. Depending on the mode, the message is blocked, redirected to test addresses, or sent normally. Meant for development servers.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            FrontLabs
 * Author URI:        https://frontlabs.pl/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       frontlabs-mail-catcher
 * Domain Path:       /languages
 *
 * @package frontlabs-mail-catcher
 */

defined( 'ABSPATH' ) || exit;

const FMC_VERSION    = '1.0.0';
const FMC_DB_VERSION = '1';

/** Capability required for every screen and action this plugin exposes. */
const FMC_CAPABILITY = 'manage_options';

define( 'FMC_FILE', __FILE__ );
define( 'FMC_DIR', plugin_dir_path( __FILE__ ) );
define( 'FMC_URL', plugin_dir_url( __FILE__ ) );

require_once FMC_DIR . 'includes/helpers.php';
require_once FMC_DIR . 'includes/settings.php';
require_once FMC_DIR . 'includes/store.php';
require_once FMC_DIR . 'includes/capture.php';
require_once FMC_DIR . 'includes/cron.php';
require_once FMC_DIR . 'includes/notices.php';
require_once FMC_DIR . 'includes/forward.php';
require_once FMC_DIR . 'includes/admin-page.php';

/**
 * Load translations.
 */
function fmc_load_textdomain() {
	load_plugin_textdomain(
		'frontlabs-mail-catcher',
		false,
		dirname( plugin_basename( FMC_FILE ) ) . '/languages'
	);
}
add_action( 'init', 'fmc_load_textdomain' );

/**
 * Create the table and schedule the clean-up.
 */
function fmc_activate() {
	fmc_install();
	fmc_schedule_prune();
}
register_activation_hook( __FILE__, 'fmc_activate' );

/**
 * Stop the clean-up. The messages stay until uninstall.
 */
function fmc_deactivate() {
	wp_clear_scheduled_hook( FMC_PRUNE_HOOK );
}
register_deactivation_hook( __FILE__, 'fmc_deactivate' );
