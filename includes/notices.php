<?php
/**
 * Warnings: the environment, and anything that can get mail past the catcher.
 *
 * Shown only on the plugin's own screens and on the plugin list -- on
 * staging and production the point is to make someone reconsider having the
 * plugin there at all, not to nag on every screen.
 *
 * @package frontlabs-mail-catcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hook suffixes of the plugin's screens, filled in by fmc_add_admin_pages().
 *
 * @param  string|null $add Suffix to remember.
 * @return string[]
 */
function fmc_screen_hooks( $add = null ) {
	static $hooks = array();

	if ( $add ) {
		$hooks[] = $add;
	}

	return $hooks;
}

/**
 * Whether the current screen is one of the plugin's, or the plugin list.
 *
 * @param  bool $include_plugins Count plugins.php too.
 * @return bool
 */
function fmc_on_own_screen( $include_plugins = true ) {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen ) {
		return false;
	}

	if ( $include_plugins && 'plugins' === $screen->id ) {
		return true;
	}

	return in_array( $screen->id, fmc_screen_hooks(), true );
}

/**
 * Whether wp_mail() is the core function, which is what the hooks rely on.
 *
 * @return bool
 */
function fmc_wp_mail_is_core() {
	try {
		$file = ( new ReflectionFunction( 'wp_mail' ) )->getFileName();
	} catch ( ReflectionException $e ) {
		return true;
	}

	return str_ends_with( str_replace( '\\', '/', (string) $file ), 'wp-includes/pluggable.php' );
}

/**
 * Print one notice.
 *
 * @param string $type    error, warning or info.
 * @param string $message Already-escaped HTML.
 */
function fmc_print_notice( $type, $message ) {
	printf(
		'<div class="notice notice-%1$s"><p>%2$s</p></div>',
		esc_attr( $type ),
		$message // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by every caller.
	);
}

/**
 * Action admin_notices.
 */
function fmc_admin_notices() {
	if ( ! current_user_can( FMC_CAPABILITY ) || ! fmc_on_own_screen() ) {
		return;
	}

	$env  = wp_get_environment_type();
	$mode = fmc_effective_mode();

	if ( fmc_is_live_environment( $env ) ) {
		fmc_print_notice(
			'warning',
			sprintf(
				/* translators: %s: environment type, for example "production". */
				esc_html__( 'Environment: %s. Mail Catcher stores the full content of every email in the database — personal data and password reset links included — and the table goes into every backup. Consider deactivating this plugin here.', 'frontlabs-mail-catcher' ),
				'<strong>' . esc_html( $env ) . '</strong>'
			)
		);

		if ( 'block' === $mode ) {
			fmc_print_notice( 'error', esc_html__( 'Mode is Block: no email from this site reaches its recipients, customers included.', 'frontlabs-mail-catcher' ) );
		} elseif ( 'redirect' === $mode ) {
			fmc_print_notice( 'error', esc_html__( 'Mode is Redirect: every email from this site goes only to the test addresses, not to its recipients.', 'frontlabs-mail-catcher' ) );
		}
	}

	if ( 'redirect' === fmc_get_mode() && 'block' === $mode ) {
		fmc_print_notice( 'warning', esc_html__( 'Redirect is selected but no test address is set, so messages are blocked instead.', 'frontlabs-mail-catcher' ) );
	}

	if ( ! fmc_on_own_screen( false ) ) {
		return;
	}

	if ( ! fmc_wp_mail_is_core() ) {
		fmc_print_notice( 'error', esc_html__( 'wp_mail() is replaced by another plugin. Messages sent through the replacement may bypass Mail Catcher entirely.', 'frontlabs-mail-catcher' ) );
	}

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Mail SMTP's hook.
	if ( apply_filters( 'wp_mail_smtp_queue_is_enabled', false ) ) {
		fmc_print_notice( 'warning', esc_html__( 'The WP Mail SMTP email queue is on. Queued messages are sent with the wp_mail hooks removed; Mail Catcher still enforces the mode on them through phpmailer_init, but each one may be listed twice.', 'frontlabs-mail-catcher' ) );
	}
}
add_action( 'admin_notices', 'fmc_admin_notices' );

/**
 * Filter plugin_row_meta: show the mode and environment on the plugin list.
 *
 * @param  string[] $meta Row meta links.
 * @param  string   $file Plugin file.
 * @return string[]
 */
function fmc_plugin_row_meta( $meta, $file ) {
	if ( plugin_basename( FMC_FILE ) === $file ) {
		$meta[] = esc_html(
			sprintf(
				/* translators: 1: mode label, 2: environment type. */
				__( 'Mode: %1$s · Environment: %2$s', 'frontlabs-mail-catcher' ),
				fmc_mode_label( fmc_effective_mode() ),
				wp_get_environment_type()
			)
		);
	}

	return $meta;
}
add_filter( 'plugin_row_meta', 'fmc_plugin_row_meta', 10, 2 );
