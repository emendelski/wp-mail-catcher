<?php
/**
 * Mode, test addresses and retention.
 *
 * Every getter falls back to a default derived from wp_get_environment_type()
 * while nothing is saved, so activating the plugin on production does not stop
 * a single customer email: there it starts in "off", which only records.
 *
 * @package frontlabs-mail-catcher
 */

defined( 'ABSPATH' ) || exit;

const FMC_MODE_OPTION           = 'fmc_mode';
const FMC_TEST_ADDRESSES_OPTION = 'fmc_test_addresses';
const FMC_RETENTION_OPTION      = 'fmc_retention_days';
const FMC_SETTINGS_GROUP        = 'fmc_settings';

/** Longest retention the settings screen accepts, in days. */
const FMC_RETENTION_MAX = 3650;

/**
 * The modes, with their labels.
 *
 * @return array<string, string>
 */
function fmc_modes() {
	return array(
		'block'    => __( 'Block — record only, nothing is sent', 'frontlabs-mail-catcher' ),
		'redirect' => __( 'Redirect — record, and send only to the test addresses', 'frontlabs-mail-catcher' ),
		'off'      => __( 'Off — record, and send to the original recipients', 'frontlabs-mail-catcher' ),
	);
}

/**
 * Short label of one mode.
 *
 * @param  string $mode Mode key.
 * @return string
 */
function fmc_mode_label( $mode ) {
	$labels = array(
		'block'    => __( 'Block', 'frontlabs-mail-catcher' ),
		'redirect' => __( 'Redirect', 'frontlabs-mail-catcher' ),
		'off'      => __( 'Off', 'frontlabs-mail-catcher' ),
		'forward'  => __( 'Forward', 'frontlabs-mail-catcher' ),
	);

	return $labels[ $mode ] ?? $mode;
}

/**
 * Whether this environment is one where real people receive the mail.
 *
 * @param  string|null $env Environment type; the current one when null.
 * @return bool
 */
function fmc_is_live_environment( $env = null ) {
	$env = $env ?? wp_get_environment_type();

	return ! in_array( $env, array( 'local', 'development' ), true );
}

/**
 * Mode used while none is saved.
 *
 * @param  string|null $env Environment type; the current one when null.
 * @return string
 */
function fmc_default_mode_for_env( $env = null ) {
	return fmc_is_live_environment( $env ) ? 'off' : 'block';
}

/**
 * Retention used while none is saved, in days.
 *
 * Shorter on staging and production: the stored messages hold form data and
 * password reset links, and they travel with every database backup.
 *
 * @param  string|null $env Environment type; the current one when null.
 * @return int
 */
function fmc_default_retention_for_env( $env = null ) {
	return fmc_is_live_environment( $env ) ? 7 : 30;
}

/**
 * Settings API callback for the mode. Unknown values fall back to the default.
 *
 * @param  mixed $value Submitted value.
 * @return string
 */
function fmc_sanitize_mode( $value ) {
	$value = is_string( $value ) ? $value : '';

	return array_key_exists( $value, fmc_modes() ) ? $value : fmc_default_mode_for_env();
}

/**
 * The configured mode.
 *
 * @return string block, redirect or off.
 */
function fmc_get_mode() {
	$saved = get_option( FMC_MODE_OPTION, '' );

	return is_string( $saved ) && in_array( $saved, array( 'block', 'redirect', 'off' ), true )
		? $saved
		: fmc_default_mode_for_env();
}

/**
 * The mode capture actually applies.
 *
 * Redirect with no test address to send to would otherwise mean "send to
 * nobody, then report a failure" at best -- it is treated as Block instead, so
 * the failure is safe in the direction that matters.
 *
 * @return string block, redirect or off.
 */
function fmc_effective_mode() {
	$mode = fmc_get_mode();

	if ( 'redirect' === $mode && ! fmc_get_test_addresses() ) {
		return 'block';
	}

	return $mode;
}

/**
 * Settings API callback for the test addresses.
 *
 * @param  mixed $value Comma, space or newline separated addresses, or an array.
 * @return string[]
 */
function fmc_sanitize_test_addresses( $value ) {
	$raw = is_array( $value ) ? implode( ',', $value ) : (string) $value;

	$valid   = array();
	$invalid = array();

	foreach ( preg_split( '/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $candidate ) {
		$email = sanitize_email( $candidate );

		if ( $email && is_email( $email ) ) {
			$valid[ strtolower( $email ) ] = $email;
		} else {
			$invalid[] = $candidate;
		}
	}

	if ( $invalid && function_exists( 'add_settings_error' ) ) {
		add_settings_error(
			FMC_TEST_ADDRESSES_OPTION,
			'fmc_invalid_addresses',
			sprintf(
				/* translators: %s: comma-separated list of rejected values. */
				__( 'Ignored invalid addresses: %s', 'frontlabs-mail-catcher' ),
				implode( ', ', $invalid )
			)
		);
	}

	return array_values( $valid );
}

/**
 * Addresses the Redirect mode and the Forward button may send to.
 *
 * Until the setting is saved, the site's admin email.
 *
 * @return string[]
 */
function fmc_get_test_addresses() {
	$saved = get_option( FMC_TEST_ADDRESSES_OPTION, false );

	if ( false === $saved ) {
		$saved = array( get_option( 'admin_email', '' ) );
	}

	$addresses = array();

	foreach ( (array) $saved as $address ) {
		if ( is_string( $address ) && is_email( $address ) ) {
			$addresses[] = $address;
		}
	}

	return $addresses;
}

/**
 * Whether an address is one of the configured test addresses.
 *
 * @param  string $address Candidate.
 * @return bool
 */
function fmc_is_test_address( $address ) {
	$address = strtolower( trim( (string) $address ) );

	foreach ( fmc_get_test_addresses() as $allowed ) {
		if ( strtolower( $allowed ) === $address ) {
			return true;
		}
	}

	return false;
}

/**
 * Settings API callback for retention.
 *
 * @param  mixed $value Submitted value.
 * @return int Days; 0 keeps messages forever.
 */
function fmc_sanitize_retention( $value ) {
	return min( absint( $value ), FMC_RETENTION_MAX );
}

/**
 * Days messages are kept; 0 keeps them forever.
 *
 * @return int
 */
function fmc_get_retention_days() {
	$saved = get_option( FMC_RETENTION_OPTION, false );

	return false === $saved ? fmc_default_retention_for_env() : fmc_sanitize_retention( $saved );
}

/**
 * Register the three options with the Settings API.
 */
function fmc_register_settings() {
	register_setting(
		FMC_SETTINGS_GROUP,
		FMC_MODE_OPTION,
		array(
			'type'              => 'string',
			'sanitize_callback' => 'fmc_sanitize_mode',
			'show_in_rest'      => false,
		)
	);

	register_setting(
		FMC_SETTINGS_GROUP,
		FMC_TEST_ADDRESSES_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'fmc_sanitize_test_addresses',
			'show_in_rest'      => false,
		)
	);

	register_setting(
		FMC_SETTINGS_GROUP,
		FMC_RETENTION_OPTION,
		array(
			'type'              => 'integer',
			'sanitize_callback' => 'fmc_sanitize_retention',
			'show_in_rest'      => false,
		)
	);
}
add_action( 'admin_init', 'fmc_register_settings' );

/**
 * Let the settings form be saved by whoever may see the plugin's screens.
 *
 * @return string
 */
function fmc_settings_capability() {
	return FMC_CAPABILITY;
}
add_filter( 'option_page_capability_' . FMC_SETTINGS_GROUP, 'fmc_settings_capability' );
