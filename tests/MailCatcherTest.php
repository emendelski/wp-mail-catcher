<?php
/**
 * FrontLabs Mail Catcher: modes, header handling, redirects, blocking, preview.
 *
 * @package frontlabs-mail-catcher
 */

namespace FrontLabs\MailCatcher\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Stands in for PHPMailer: only the recipient API the guard touches.
 */
class FakeMailer {

	/** @var array */
	public $to = array();

	/** @var array */
	public $cc = array();

	/** @var array */
	public $bcc = array();

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase -- PHPMailer API.
	/** @var string */
	public $From = 'site@example.com';

	/** @var string */
	public $FromName = 'Site';

	/** @var string */
	public $ContentType = 'text/html';
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/** @return void */
	public function clearAllRecipients() {
		$this->to  = array();
		$this->cc  = array();
		$this->bcc = array();
	}

	/**
	 * @param  string $address Address.
	 * @return bool
	 */
	public function addAddress( $address ) {
		$this->to[] = $address;

		return true;
	}
}

/**
 * @covers ::fmc_default_mode_for_env
 * @covers ::fmc_get_mode
 * @covers ::fmc_effective_mode
 * @covers ::fmc_header_lines
 * @covers ::fmc_redirect_atts
 * @covers ::fmc_maybe_block
 * @covers ::fmc_guard_phpmailer
 * @covers ::fmc_forward_message
 * @covers ::fmc_preview_document
 * @covers ::fmc_srcdoc_attr
 */
class MailCatcherTest extends PHPUnitTestCase {

	/** @var array<string, mixed> Options the stubbed get_option() returns. */
	private $options = array();

	/** @var array<int, array> Calls to the stubbed fmc_store_update(). */
	private $updates = array();

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array();
		$this->updates = array();

		$options = &$this->options;
		$updates = &$this->updates;

		Functions\when( 'wp_get_environment_type' )->justReturn( 'local' );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) use ( &$options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $fallback;
			}
		);
		Functions\when( 'is_email' )->alias(
			static function ( $email ) {
				return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
			}
		);
		Functions\when( 'fmc_store_insert' )->justReturn( 7 );
		Functions\when( 'fmc_store_update' )->alias(
			static function ( $id, $data ) use ( &$updates ) {
				$updates[] = array( $id, $data );

				return true;
			}
		);

		$this->resetRuntime();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		$this->resetRuntime();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The capture state is static for the request; a suite is one process.
	 *
	 * @return void
	 */
	private function resetRuntime() {
		$state               = &fmc_runtime();
		$state['id']         = 0;
		$state['mode']       = '';
		$state['forward_to'] = '';
		$state['parent_id']  = 0;
	}

	public function test_default_mode_blocks_only_where_nobody_real_is_listening() {
		$this->assertSame( 'block', fmc_default_mode_for_env( 'local' ) );
		$this->assertSame( 'block', fmc_default_mode_for_env( 'development' ) );
		$this->assertSame( 'off', fmc_default_mode_for_env( 'staging' ) );
		$this->assertSame( 'off', fmc_default_mode_for_env( 'production' ) );
	}

	public function test_saved_mode_wins_and_garbage_falls_back_to_the_environment_default() {
		$this->assertSame( 'block', fmc_get_mode() );

		$this->options['fmc_mode'] = 'off';
		$this->assertSame( 'off', fmc_get_mode() );

		$this->options['fmc_mode'] = 'send-everything';
		$this->assertSame( 'block', fmc_get_mode() );

		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		$this->assertSame( 'off', fmc_get_mode() );
	}

	public function test_redirect_without_a_test_address_blocks_instead() {
		$this->options['fmc_mode']           = 'redirect';
		$this->options['fmc_test_addresses'] = array();

		$this->assertSame( 'block', fmc_effective_mode() );

		$this->options['fmc_test_addresses'] = array( 'dev@example.com' );
		$this->assertSame( 'redirect', fmc_effective_mode() );
	}

	public function test_header_lines_join_folded_lines_and_drop_carriage_returns() {
		$lines = fmc_header_lines( "From: Site <a@example.com>\r\nSubject-Extra: one\r\n two\r\n\r\nCc: x@example.com" );

		$this->assertSame(
			array( 'From: Site <a@example.com>', 'Subject-Extra: one two', 'Cc: x@example.com' ),
			$lines
		);
	}

	public function test_redirect_replaces_every_recipient_and_keeps_a_record_of_them() {
		$atts = fmc_redirect_atts(
			array(
				'to'      => 'customer@example.com, shop@example.com',
				'headers' => array(
					'From: Site <site@example.com>',
					'CC: boss@example.com',
					'bcc: hidden@example.com',
					'Reply-To: visitor@example.com',
					'Content-Type: text/html; charset=UTF-8',
				),
			),
			array( 'dev@example.com' )
		);

		$this->assertSame( array( 'dev@example.com' ), $atts['to'] );

		$names = array_map(
			static function ( $line ) {
				return strtolower( strstr( $line, ':', true ) );
			},
			$atts['headers']
		);

		$this->assertNotContains( 'cc', $names );
		$this->assertNotContains( 'bcc', $names );
		$this->assertContains( 'reply-to', $names );
		$this->assertContains( 'X-FMC-Original-To: customer@example.com, shop@example.com', $atts['headers'] );
		$this->assertContains( 'X-FMC-Original-Cc: boss@example.com', $atts['headers'] );
		$this->assertContains( 'X-FMC-Original-Bcc: hidden@example.com', $atts['headers'] );
	}

	public function test_redirect_for_a_forward_also_drops_reply_to_and_a_to_header() {
		$atts = fmc_redirect_atts(
			array(
				'to'      => 'customer@example.com',
				'headers' => "To: sneaky@example.com\nReply-To: visitor@example.com",
			),
			array( 'dev@example.com' ),
			true
		);

		foreach ( $atts['headers'] as $line ) {
			$this->assertStringStartsNotWith( 'To:', $line );
			$this->assertStringStartsNotWith( 'Reply-To:', $line );
		}

		$this->assertContains( 'X-FMC-Original-To: customer@example.com, sneaky@example.com', $atts['headers'] );
	}

	public function test_block_short_circuits_and_has_the_last_word() {
		$state         = &fmc_runtime();
		$state['mode'] = 'block';
		$state['id']   = 7;

		// An earlier filter tried to let it through.
		$this->assertTrue( fmc_maybe_block( null ) );
		$this->assertSame( array( array( 7, array( 'status' => 'blocked' ) ) ), $this->updates );
	}

	public function test_block_holds_even_when_the_message_was_not_recorded() {
		// Capture never ran (or the insert failed): no ID, no mode in flight.
		$this->assertTrue( fmc_maybe_block( null ) );
		$this->assertSame( array(), $this->updates );
	}

	public function test_other_modes_leave_wp_mail_alone() {
		$this->options['fmc_mode'] = 'off';

		$this->assertNull( fmc_maybe_block( null ) );
	}

	public function test_guard_strips_every_recipient_in_block_mode() {
		$state         = &fmc_runtime();
		$state['mode'] = 'block';

		$mailer      = new FakeMailer();
		$mailer->to  = array( 'customer@example.com' );
		$mailer->bcc = array( 'added-by-a-plugin@example.com' );

		fmc_guard_phpmailer( $mailer );

		$this->assertSame( array(), $mailer->to );
		$this->assertSame( array(), $mailer->bcc );
	}

	public function test_guard_leaves_only_test_addresses_in_redirect_mode() {
		$this->options['fmc_test_addresses'] = array( 'dev@example.com' );

		$state         = &fmc_runtime();
		$state['mode'] = 'redirect';

		$mailer     = new FakeMailer();
		$mailer->to = array( 'dev@example.com' );
		$mailer->cc = array( 'added-later@example.com' );

		fmc_guard_phpmailer( $mailer );

		$this->assertSame( array( 'dev@example.com' ), $mailer->to );
		$this->assertSame( array(), $mailer->cc );
	}

	public function test_guard_does_nothing_in_off_mode() {
		$state         = &fmc_runtime();
		$state['mode'] = 'off';

		$mailer     = new FakeMailer();
		$mailer->to = array( 'customer@example.com' );

		fmc_guard_phpmailer( $mailer );

		$this->assertSame( array( 'customer@example.com' ), $mailer->to );
	}

	public function test_forward_exception_is_lifted_even_when_sending_throws() {
		Functions\when( 'wp_mail' )->alias(
			static function () {
				throw new \RuntimeException( 'transport exploded' );
			}
		);

		$message = (object) array(
			'id'           => 3,
			'subject'      => 'Hi',
			'body'         => '<p>Hi</p>',
			'headers'      => '',
			'content_type' => 'text/html',
		);

		try {
			fmc_forward_message( $message, 'dev@example.com' );
			$this->fail( 'The exception should have propagated.' );
		} catch ( \RuntimeException $e ) {
			$state = &fmc_runtime();

			$this->assertSame( '', $state['forward_to'] );
			$this->assertSame( 0, $state['parent_id'] );
		}
	}

	public function test_forward_adds_the_content_type_the_original_got_from_a_filter() {
		$sent = null;

		Functions\when( 'wp_mail' )->alias(
			static function ( $to, $subject, $body, $headers ) use ( &$sent ) {
				$sent = $headers;

				return true;
			}
		);

		fmc_forward_message(
			(object) array(
				'id'           => 3,
				'subject'      => 'Hi',
				'body'         => '<p>Hi</p>',
				'headers'      => 'From: Site <site@example.com>',
				'content_type' => 'text/html',
			),
			'dev@example.com'
		);

		$this->assertContains( 'Content-Type: text/html; charset=UTF-8', $sent );
	}

	public function test_preview_puts_the_content_security_policy_first() {
		$doc = fmc_preview_document( '<html><body><img src="https://tracker.example/p.gif"></body></html>', 'text/html' );

		$this->assertStringStartsWith( '<!doctype html><meta charset="utf-8"><meta http-equiv="Content-Security-Policy"', $doc );
		$this->assertStringContainsString( "default-src 'none'", $doc );
		$this->assertStringNotContainsString( 'https:', explode( '<html>', $doc )[0] );

		$remote = fmc_preview_document( '<p>x</p>', 'text/html', true );
		$this->assertStringContainsString( 'img-src data: cid: https:', $remote );
	}

	public function test_plain_text_preview_is_escaped() {
		$doc = fmc_preview_document( '<script>alert(1)</script>', 'text/plain' );

		$this->assertStringNotContainsString( '<script>', $doc );
		$this->assertStringContainsString( '&lt;script&gt;', $doc );
	}

	public function test_srcdoc_encodes_existing_entities_again() {
		// Text that reads "<b>" must not turn into a tag after attribute decoding.
		$this->assertSame( '&amp;lt;b&amp;gt; &quot;x&quot;', fmc_srcdoc_attr( '&lt;b&gt; "x"' ) );
	}

	public function test_source_names_the_caller_of_wp_mail() {
		$trace = array(
			array( 'function' => 'fmc_capture' ),
			array(
				'function' => 'apply_filters',
				'class'    => 'WP_Hook',
			),
			array(
				'function' => 'wp_mail',
				'file'     => '/var/www/html/wp-content/plugins/contact-form-7/includes/mail.php',
				'line'     => 210,
			),
			array(
				'function' => 'send',
				'class'    => 'WPCF7_Mail',
				'type'     => '->',
			),
		);

		$this->assertSame(
			'WPCF7_Mail->send() wp-content/plugins/contact-form-7/includes/mail.php:210',
			fmc_source_from_trace( $trace, '/var/www/html/' )
		);
	}
}
