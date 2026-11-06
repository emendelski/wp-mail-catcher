<?php
/**
 * Recording, blocking and redirecting wp_mail().
 *
 * Inside wp_mail() the hooks run in this order, and each layer here has one job:
 *
 *   wp_mail filter       fmc_capture()          record the original message;
 *                                               rewrite recipients for Redirect
 *   pre_wp_mail filter   fmc_maybe_block()      Block: stop before PHPMailer
 *   phpmailer_init       fmc_guard_phpmailer()  enforce the allowed recipients
 *   wp_mail_succeeded    fmc_on_succeeded()     record the outcome
 *   wp_mail_failed       fmc_on_failed()
 *
 * The filters run last (PHP_INT_MAX) so nothing registered after them can
 * undo their decision. phpmailer_init is the backstop: it still fires when a
 * plugin has removed the wp_mail filters -- the WP Mail SMTP queue does exactly
 * that when it sends a queued message -- and it sees recipients other plugins
 * add straight to PHPMailer.
 *
 * Recording must never be what decides whether mail goes out: every database
 * call is wrapped so a missing table or a failed insert leaves the blocking
 * and redirecting intact.
 *
 * @package frontlabs-mail-catcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * Per-request state shared by the hooks of one wp_mail() call.
 *
 * - id:         row of the message being sent, 0 when not recorded
 * - mode:       mode applied to that message, '' outside a wp_mail() call
 * - forward_to: set only by fmc_forward_message() for one call
 * - parent_id:  message being forwarded
 *
 * @return array Reference to the state.
 */
function &fmc_runtime() {
	static $state = array(
		'id'         => 0,
		'mode'       => '',
		'forward_to' => '',
		'parent_id'  => 0,
	);

	return $state;
}

/**
 * Forget the message in flight; keeps a forward in progress.
 */
function fmc_runtime_reset() {
	$state         = &fmc_runtime();
	$state['id']   = 0;
	$state['mode'] = '';
}

/**
 * Run a store call without letting a database failure reach wp_mail().
 *
 * @param  callable $callback Store function.
 * @param  mixed    ...$args  Its arguments.
 * @return mixed The callback's result, or null if it threw.
 */
function fmc_safe_store( $callback, ...$args ) {
	try {
		return $callback( ...$args );
	} catch ( \Throwable $e ) {
		return null;
	}
}

/**
 * The From header WordPress would use when the caller gives none.
 *
 * @return string
 */
function fmc_default_from() {
	$host = (string) wp_parse_url( network_home_url(), PHP_URL_HOST );

	if ( str_starts_with( $host, 'www.' ) ) {
		$host = substr( $host, 4 );
	}

	$email = apply_filters( 'wp_mail_from', 'wordpress@' . $host ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
	$name  = apply_filters( 'wp_mail_from_name', 'WordPress' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.

	return $name ? sprintf( '%s <%s>', $name, $email ) : (string) $email;
}

/**
 * A storable record from wp_mail() arguments.
 *
 * @param  array $atts wp_mail() arguments.
 * @return array Column => value.
 */
function fmc_record_from_atts( array $atts ) {
	$headers = $atts['headers'] ?? array();

	$from = fmc_header_values( $headers, 'from' );
	$type = fmc_header_values( $headers, 'content-type' );
	$type = $type ? fmc_mime_type( end( $type ) ) : '';

	if ( '' === $type ) {
		$type = fmc_mime_type( apply_filters( 'wp_mail_content_type', 'text/plain' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
	}

	list( $body, $cut ) = fmc_truncate_body( $atts['message'] ?? '' );

	return array(
		'subject'      => (string) ( $atts['subject'] ?? '' ),
		'from_header'  => $from ? end( $from ) : fmc_default_from(),
		'to_list'      => implode( ', ', fmc_address_list( $atts['to'] ?? '' ) ),
		'cc_list'      => implode( ', ', fmc_address_list( fmc_header_values( $headers, 'cc' ) ) ),
		'bcc_list'     => implode( ', ', fmc_address_list( fmc_header_values( $headers, 'bcc' ) ) ),
		'reply_to'     => implode( ', ', fmc_address_list( fmc_header_values( $headers, 'reply-to' ) ) ),
		'headers'      => implode( "\n", fmc_header_lines( $headers ) ),
		'content_type' => $type ? $type : 'text/plain',
		'body'         => $body,
		'notes'        => $cut ? __( 'Body cut to 1 MB before storing.', 'frontlabs-mail-catcher' ) : '',
		'attachments'  => fmc_attachment_summary( $atts['attachments'] ?? array() ),
	);
}

/**
 * Where wp_mail() was called from.
 *
 * @return string
 */
function fmc_detect_source() {
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- names the caller, arguments excluded.
	return fmc_source_from_trace( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 40 ), ABSPATH );
}

/**
 * Filter wp_mail: record the message and, for Redirect, rewrite recipients.
 *
 * @param  array $atts wp_mail() arguments.
 * @return array
 */
function fmc_capture( $atts ) {
	if ( ! is_array( $atts ) ) {
		return $atts;
	}

	$state = &fmc_runtime();
	$mode  = '' !== $state['forward_to'] ? 'forward' : fmc_effective_mode();

	$state['mode'] = $mode;
	$state['id']   = 0;

	$record = fmc_record_from_atts( $atts );

	$record['mode']      = $mode;
	$record['status']    = 'pending';
	$record['source']    = fmc_detect_source();
	$record['parent_id'] = 'forward' === $mode ? (int) $state['parent_id'] : 0;

	if ( 'redirect' === $mode ) {
		$targets                 = fmc_get_test_addresses();
		$atts                    = fmc_redirect_atts( $atts, $targets );
		$record['redirected_to'] = implode( ', ', $targets );
	} elseif ( 'forward' === $mode ) {
		$atts                    = fmc_redirect_atts( $atts, array( $state['forward_to'] ), true );
		$record['redirected_to'] = $state['forward_to'];
	}

	$state['id'] = (int) fmc_safe_store( 'fmc_store_insert', $record );

	return $atts;
}
add_filter( 'wp_mail', 'fmc_capture', PHP_INT_MAX );

/**
 * Filter pre_wp_mail: in Block mode, end wp_mail() here.
 *
 * Runs last so a later filter cannot hand back null and let the message
 * through. Blocks even when the message could not be recorded.
 *
 * @param  null|bool $result Short-circuit value from earlier filters.
 * @return null|bool
 */
function fmc_maybe_block( $result ) {
	$state = &fmc_runtime();
	$mode  = '' !== $state['mode'] ? $state['mode'] : fmc_effective_mode();

	if ( 'block' === $mode ) {
		if ( $state['id'] ) {
			fmc_safe_store( 'fmc_store_update', $state['id'], array( 'status' => 'blocked' ) );
		}

		fmc_runtime_reset();

		return true;
	}

	if ( null !== $result ) {
		if ( $state['id'] ) {
			fmc_safe_store( 'fmc_store_update', $state['id'], array( 'status' => 'other' ) );
		}

		fmc_runtime_reset();
	}

	return $result;
}
add_filter( 'pre_wp_mail', 'fmc_maybe_block', PHP_INT_MAX );

/**
 * Addresses PHPMailer may send to in a mode; null means "leave as they are".
 *
 * @param  string $mode Mode applied to the message.
 * @return string[]|null
 */
function fmc_allowed_recipients( $mode ) {
	$state = &fmc_runtime();

	switch ( $mode ) {
		case 'block':
			return array();
		case 'redirect':
			return fmc_get_test_addresses();
		case 'forward':
			return array( $state['forward_to'] );
		default:
			return null;
	}
}

/**
 * A storable record read back from PHPMailer.
 *
 * For a message whose wp_mail filters never ran, the only place left to see it.
 *
 * @param  object $phpmailer PHPMailer instance.
 * @return array Column => value.
 */
function fmc_record_from_phpmailer( $phpmailer ) {
	$join = static function ( $entries ) {
		$out = array();

		foreach ( (array) $entries as $entry ) {
			$out[] = $entry[1] ? sprintf( '%s <%s>', $entry[1], $entry[0] ) : $entry[0];
		}

		return implode( ', ', $out );
	};

	$headers = array();

	foreach ( (array) $phpmailer->getCustomHeaders() as $header ) {
		$headers[] = $header[0] . ': ' . $header[1];
	}

	$attachments = array();

	foreach ( (array) $phpmailer->getAttachments() as $attachment ) {
		$attachments[ (string) $attachment[2] ] = (string) $attachment[0];
	}

	list( $body, $cut ) = fmc_truncate_body( $phpmailer->Body ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
	return array(
		'subject'      => (string) $phpmailer->Subject,
		'from_header'  => $phpmailer->FromName ? sprintf( '%s <%s>', $phpmailer->FromName, $phpmailer->From ) : (string) $phpmailer->From,
		'to_list'      => $join( $phpmailer->getToAddresses() ),
		'cc_list'      => $join( $phpmailer->getCcAddresses() ),
		'bcc_list'     => $join( $phpmailer->getBccAddresses() ),
		'reply_to'     => $join( $phpmailer->getReplyToAddresses() ),
		'headers'      => implode( "\n", $headers ),
		'content_type' => fmc_mime_type( $phpmailer->ContentType ),
		'body'         => $body,
		'notes'        => trim(
			__( 'Recorded from PHPMailer: the wp_mail filters were removed before sending (for example by the WP Mail SMTP queue).', 'frontlabs-mail-catcher' )
			. ( $cut ? ' ' . __( 'Body cut to 1 MB before storing.', 'frontlabs-mail-catcher' ) : '' )
		),
		'attachments'  => fmc_attachment_summary( $attachments ),
	);
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}

/**
 * Action phpmailer_init: leave PHPMailer with only the allowed recipients.
 *
 * @param  object $phpmailer PHPMailer instance (passed by reference by core).
 * @return void
 */
function fmc_guard_phpmailer( $phpmailer ) {
	$state = &fmc_runtime();
	$mode  = $state['mode'];

	if ( '' === $mode ) {
		// wp_mail() ran without our filters. Decide, and record, here.
		$mode = fmc_effective_mode();

		$record = fmc_record_from_phpmailer( $phpmailer );

		$record['mode']   = $mode;
		$record['source'] = fmc_detect_source();
		$record['status'] = array(
			'block'    => 'blocked',
			'redirect' => 'redirected',
		)[ $mode ] ?? 'sent';

		if ( 'redirect' === $mode ) {
			$record['redirected_to'] = implode( ', ', fmc_get_test_addresses() );
		}

		// Recorded with its final status: wp_mail_succeeded and wp_mail_failed
		// are among the hooks that were removed, so no outcome will follow.
		fmc_safe_store( 'fmc_store_insert', $record );
	} elseif ( $state['id'] ) {
		// What PHPMailer will really use, after every plugin has had its say.
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer API.
		fmc_safe_store(
			'fmc_store_update',
			$state['id'],
			array(
				'from_header'  => $phpmailer->FromName ? sprintf( '%s <%s>', $phpmailer->FromName, $phpmailer->From ) : (string) $phpmailer->From,
				'content_type' => fmc_mime_type( $phpmailer->ContentType ),
			)
		);
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	$allowed = fmc_allowed_recipients( $mode );

	if ( null !== $allowed ) {
		fmc_force_recipients( $phpmailer, $allowed );
	}
}
add_action( 'phpmailer_init', 'fmc_guard_phpmailer', PHP_INT_MAX );

/**
 * Action wp_mail_succeeded.
 */
function fmc_on_succeeded() {
	$state = &fmc_runtime();

	if ( $state['id'] ) {
		$status = array(
			'redirect' => 'redirected',
			'forward'  => 'forwarded',
		)[ $state['mode'] ] ?? 'sent';

		fmc_safe_store( 'fmc_store_update', $state['id'], array( 'status' => $status ) );
	}

	fmc_runtime_reset();
}
add_action( 'wp_mail_succeeded', 'fmc_on_succeeded' );

/**
 * Action wp_mail_failed.
 *
 * @param WP_Error $error Failure reported by wp_mail().
 */
function fmc_on_failed( $error ) {
	$state = &fmc_runtime();

	if ( $state['id'] ) {
		fmc_safe_store(
			'fmc_store_update',
			$state['id'],
			array(
				'status' => 'failed',
				'error'  => is_wp_error( $error ) ? $error->get_error_message() : '',
			)
		);
	}

	fmc_runtime_reset();
}
add_action( 'wp_mail_failed', 'fmc_on_failed' );

/**
 * Send one stored message again, to one test address, past Block mode.
 *
 * The exception covers this single wp_mail() call and is lifted in finally,
 * so neither an error nor a message sent from inside a hook during the call
 * can inherit it afterwards. Callers must have checked that the address is a
 * configured test address.
 *
 * @param  object $message Stored message row.
 * @param  string $address Test address.
 * @return bool Whether wp_mail() reported success.
 */
function fmc_forward_message( $message, $address ) {
	$state = &fmc_runtime();

	$headers = fmc_header_lines( $message->headers );

	// The original may have had its type from the wp_mail_content_type filter
	// rather than a header; without this an HTML message would arrive as text.
	if ( ! fmc_header_values( $headers, 'content-type' ) && $message->content_type ) {
		$headers[] = 'Content-Type: ' . $message->content_type . '; charset=UTF-8';
	}

	$state['forward_to'] = $address;
	$state['parent_id']  = (int) $message->id;

	try {
		return (bool) wp_mail( $address, $message->subject, $message->body, $headers );
	} finally {
		$state['forward_to'] = '';
		$state['parent_id']  = 0;
		fmc_runtime_reset();
	}
}
