<?php
/**
 * PHPUnit bootstrap: enough of WordPress to load the plugin's pure parts.
 *
 * @package frontlabs-mail-catcher
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// The files below register hooks as they load, before any test's setUp()
// has put Brain Monkey's add_action()/add_filter() in place.
Brain\Monkey\setUp();
Brain\Monkey\tearDown();

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__ ) . '/' );

/**
 * Identity translation.
 *
 * @param  string $text Text.
 * @return string
 */
function __( $text ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionDoubleUnderscore
	return $text;
}

/**
 * Real escaping, so assertions read the markup a browser would.
 *
 * @param  string $text Text.
 * @return string
 */
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * @param  string $text Text.
 * @return string
 */
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * @param  mixed $maybeint Value.
 * @return int
 */
function absint( $maybeint ) {
	return abs( (int) $maybeint );
}

/**
 * @param  string $path   Path.
 * @param  string $suffix Suffix to strip.
 * @return string
 */
function wp_basename( $path, $suffix = '' ) {
	return basename( str_replace( '\\', '/', (string) $path ), $suffix );
}

require_once dirname( __DIR__ ) . '/includes/helpers.php';
require_once dirname( __DIR__ ) . '/includes/settings.php';
require_once dirname( __DIR__ ) . '/includes/capture.php';
