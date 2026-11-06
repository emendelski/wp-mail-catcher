<?php
/**
 * Pure helpers: headers, address lists, redirects, the preview document.
 *
 * Nothing here touches the database or registers a hook, so the whole file can
 * be loaded by the unit tests as it is.
 *
 * @package frontlabs-mail-catcher
 */

defined( 'ABSPATH' ) || exit;

/** Bodies longer than this are cut before they are stored. */
const FMC_BODY_LIMIT = 1048576;

/**
 * Header lines the way wp_mail() reads them.
 *
 * WordPress's wp_mail() accepts a newline-separated string or an array of lines. Folded
 * continuation lines (starting with a space or tab) are joined to the line
 * above, and any stray carriage return is dropped so a value can never carry
 * a line break back into a header.
 *
 * @param  string|array $headers Headers as passed to wp_mail().
 * @return string[] One "Name: value" line per header.
 */
function fmc_header_lines( $headers ) {
	if ( empty( $headers ) ) {
		return array();
	}

	if ( ! is_array( $headers ) ) {
		$headers = explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) );
	}

	$lines = array();

	foreach ( $headers as $header ) {
		$header = str_replace( array( "\r", "\n" ), ' ', (string) $header );

		if ( '' === trim( $header ) ) {
			continue;
		}

		if ( ( ' ' === $header[0] || "\t" === $header[0] ) && $lines ) {
			$lines[ count( $lines ) - 1 ] .= ' ' . trim( $header );
			continue;
		}

		$lines[] = trim( $header );
	}

	return $lines;
}

/**
 * Split header lines into name/value pairs.
 *
 * @param  string|array $headers Headers as passed to wp_mail().
 * @return array<int, array{name: string, value: string}> Names lower-cased.
 */
function fmc_parse_headers( $headers ) {
	$parsed = array();

	foreach ( fmc_header_lines( $headers ) as $line ) {
		if ( ! str_contains( $line, ':' ) ) {
			continue;
		}

		list( $name, $value ) = explode( ':', $line, 2 );

		$parsed[] = array(
			'name'  => strtolower( trim( $name ) ),
			'value' => trim( $value ),
		);
	}

	return $parsed;
}

/**
 * Every value of one header, in order.
 *
 * @param  string|array $headers Headers as passed to wp_mail().
 * @param  string       $name    Header name, any case.
 * @return string[]
 */
function fmc_header_values( $headers, $name ) {
	$name   = strtolower( $name );
	$values = array();

	foreach ( fmc_parse_headers( $headers ) as $header ) {
		if ( $name === $header['name'] ) {
			$values[] = $header['value'];
		}
	}

	return $values;
}

/**
 * Turn a recipient argument or header value into a flat list.
 *
 * Splits on commas the way wp_mail() does, so the list shows exactly the
 * recipients WordPress will try.
 *
 * @param  string|array $addresses Comma-separated string, or array of them.
 * @return string[]
 */
function fmc_address_list( $addresses ) {
	$list = array();

	foreach ( (array) $addresses as $chunk ) {
		foreach ( explode( ',', (string) $chunk ) as $address ) {
			$address = trim( str_replace( array( "\r", "\n" ), '', $address ) );

			if ( '' !== $address ) {
				$list[] = $address;
			}
		}
	}

	return $list;
}

/**
 * The MIME type part of a Content-Type value, lower-cased.
 *
 * @param  string $value For example "text/html; charset=UTF-8".
 * @return string For example "text/html".
 */
function fmc_mime_type( $value ) {
	$type = strtolower( trim( explode( ';', (string) $value )[0] ) );

	return preg_match( '#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $type ) ? $type : '';
}

/**
 * Names and sizes of the attachments. The files are never copied.
 *
 * Only the base name is kept: the full path would put the server's directory
 * layout on an admin screen and in every database backup, for no benefit.
 *
 * @param  string|array $attachments As passed to wp_mail().
 * @return array<int, array{name: string, size: int|null}>
 */
function fmc_attachment_summary( $attachments ) {
	if ( empty( $attachments ) ) {
		return array();
	}

	if ( ! is_array( $attachments ) ) {
		$attachments = explode( "\n", str_replace( "\r\n", "\n", (string) $attachments ) );
	}

	$summary = array();

	foreach ( $attachments as $key => $path ) {
		$path = trim( (string) $path );

		if ( '' === $path ) {
			continue;
		}

		// Since WordPress 6.2 a string key is the file name the recipient sees.
		$name = is_string( $key ) && '' !== $key ? $key : wp_basename( $path );

		$summary[] = array(
			'name' => $name,
			'size' => is_file( $path ) ? (int) filesize( $path ) : null,
		);
	}

	return $summary;
}

/**
 * Cut a body to the storage limit without splitting a UTF-8 character.
 *
 * @param  string $body Message body.
 * @return array{0: string, 1: bool} The body to store, and whether it was cut.
 */
function fmc_truncate_body( $body ) {
	$body = (string) $body;

	if ( strlen( $body ) <= FMC_BODY_LIMIT ) {
		return array( $body, false );
	}

	return array( mb_strcut( $body, 0, FMC_BODY_LIMIT, 'UTF-8' ), true );
}

/**
 * Point a message at other recipients.
 *
 * The To argument is replaced, and To/Cc/Bcc headers are removed so nothing
 * the caller added reaches the original audience. What was removed is kept in
 * X-FMC-Original-* headers so the test inbox shows who the message was for.
 *
 * This is the readable half of the redirect. The enforcing half is
 * fmc_force_recipients(), applied to PHPMailer right before it sends.
 *
 * @param  array    $atts           wp_mail() arguments (to, subject, message, headers, attachments).
 * @param  string[] $targets        Addresses that may receive the message.
 * @param  bool     $strip_reply_to Also drop Reply-To (used when forwarding one stored message).
 * @return array The rewritten arguments.
 */
function fmc_redirect_atts( array $atts, array $targets, $strip_reply_to = false ) {
	$removed = array(
		'to'  => fmc_address_list( $atts['to'] ?? '' ),
		'cc'  => array(),
		'bcc' => array(),
	);

	$drop = array( 'to', 'cc', 'bcc' );

	if ( $strip_reply_to ) {
		$drop[] = 'reply-to';
	}

	$kept = array();

	foreach ( fmc_header_lines( $atts['headers'] ?? array() ) as $line ) {
		$name = strtolower( trim( strstr( $line, ':', true ) ) );

		if ( in_array( $name, $drop, true ) ) {
			if ( isset( $removed[ $name ] ) ) {
				$removed[ $name ] = array_merge( $removed[ $name ], fmc_address_list( substr( $line, strpos( $line, ':' ) + 1 ) ) );
			}

			continue;
		}

		// Never let a previous redirect's bookkeeping pile up.
		if ( str_starts_with( $name, 'x-fmc-original-' ) ) {
			continue;
		}

		$kept[] = $line;
	}

	foreach ( $removed as $name => $addresses ) {
		if ( $addresses ) {
			$kept[] = 'X-FMC-Original-' . ucfirst( $name ) . ': ' . implode( ', ', $addresses );
		}
	}

	$atts['to']      = array_values( $targets );
	$atts['headers'] = $kept;

	return $atts;
}

/**
 * Leave PHPMailer with exactly the allowed recipients and no others.
 *
 * Runs last on phpmailer_init, so it also covers recipients other plugins add
 * there, and messages sent while the wp_mail filters were removed (the WP Mail
 * SMTP queue does that). An empty list leaves no recipient at all, and
 * PHPMailer then refuses to send.
 *
 * @param  object   $phpmailer PHPMailer instance.
 * @param  string[] $allowed   Addresses that may receive the message.
 * @return void
 */
function fmc_force_recipients( $phpmailer, array $allowed ) {
	$phpmailer->clearAllRecipients();

	foreach ( $allowed as $address ) {
		$phpmailer->addAddress( $address );
	}
}

/**
 * The document shown inside the preview iframe.
 *
 * A Content-Security-Policy is put in front of the message: with nothing
 * loaded from outside, opening a message cannot tell its sender that an
 * administrator looked at it. Scripts are already stopped by the iframe's
 * empty sandbox; the policy says so again for any browser that ignores it.
 *
 * @param  string $body         Message body.
 * @param  string $content_type MIME type, for example "text/html".
 * @param  bool   $allow_remote Load remote images, styles and fonts.
 * @return string Raw HTML. Escape with fmc_srcdoc_attr() before printing.
 */
function fmc_preview_document( $body, $content_type, $allow_remote = false ) {
	$policy = $allow_remote
		? "default-src 'none'; img-src data: cid: https: http:; style-src 'unsafe-inline' https: http:; font-src data: https: http:; base-uri 'none'; form-action 'none'"
		: "default-src 'none'; img-src data: cid:; style-src 'unsafe-inline'; font-src data:; base-uri 'none'; form-action 'none'";

	$head = '<!doctype html><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="' . $policy . '"><meta name="referrer" content="no-referrer">';

	if ( 'text/html' !== $content_type ) {
		return $head . '<pre style="white-space:pre-wrap;word-wrap:break-word;font:13px/1.5 monospace;margin:16px">'
			. htmlspecialchars( (string) $body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' )
			. '</pre>';
	}

	return $head . (string) $body;
}

/**
 * Escape a document for an iframe's srcdoc attribute.
 *
 * Not esc_attr(): that leaves existing entities alone, so a message containing
 * the text "&lt;b&gt;" would come out of the attribute as a real <b> tag. Every
 * ampersand has to be encoded again for the document to arrive unchanged.
 *
 * @param  string $document Raw HTML.
 * @return string
 */
function fmc_srcdoc_attr( $document ) {
	return htmlspecialchars( (string) $document, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true );
}

/**
 * Where wp_mail() was called from, for the Source column.
 *
 * @param  array  $trace   debug_backtrace() frames, innermost first.
 * @param  string $root    Path prefix to strip, normally ABSPATH.
 * @return string For example "WPCF7_Mail->send() wp-content/plugins/contact-form-7/includes/mail.php:123".
 */
function fmc_source_from_trace( array $trace, $root = '' ) {
	foreach ( $trace as $index => $frame ) {
		if ( 'wp_mail' !== ( $frame['function'] ?? '' ) || isset( $frame['class'] ) ) {
			continue;
		}

		$where = '';

		if ( isset( $frame['file'] ) ) {
			$file  = str_replace( '\\', '/', $frame['file'] );
			$root  = str_replace( '\\', '/', (string) $root );
			$file  = ( '' !== $root && str_starts_with( $file, $root ) ) ? substr( $file, strlen( $root ) ) : wp_basename( $file );
			$where = $file . ( isset( $frame['line'] ) ? ':' . (int) $frame['line'] : '' );
		}

		$caller = $trace[ $index + 1 ] ?? array();
		$name   = '';

		if ( isset( $caller['function'] ) ) {
			$name = ( isset( $caller['class'] ) ? $caller['class'] . ( $caller['type'] ?? '::' ) : '' ) . $caller['function'] . '()';
		}

		return trim( $name . ' ' . $where );
	}

	return '';
}
