<?php
/**
 * Mail Catcher screens: message list, message details, settings.
 *
 * @package frontlabs-mail-catcher
 */

defined( 'ABSPATH' ) || exit;

const FMC_MESSAGES_SLUG = 'fmc-messages';
const FMC_SETTINGS_SLUG = 'fmc-settings';

/**
 * URL of one message's details.
 *
 * @param  int   $id   Message ID.
 * @param  array $args Extra query arguments.
 * @return string
 */
function fmc_message_url( $id, array $args = array() ) {
	return add_query_arg(
		array_merge(
			array(
				'page'   => FMC_MESSAGES_SLUG,
				'action' => 'view',
				'id'     => absint( $id ),
			),
			$args
		),
		admin_url( 'admin.php' )
	);
}

/**
 * Nonced URL deleting one message.
 *
 * @param  int $id Message ID.
 * @return string
 */
function fmc_delete_url( $id ) {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'fmc_delete',
				'id'     => absint( $id ),
			),
			admin_url( 'admin-post.php' )
		),
		'fmc_delete_' . absint( $id )
	);
}

/**
 * Status badge markup.
 *
 * @param  string $status Status key.
 * @return string
 */
function fmc_status_badge( $status ) {
	$labels = fmc_statuses();

	return sprintf(
		'<span class="fmc-badge fmc-badge--%1$s">%2$s</span>',
		esc_attr( sanitize_html_class( $status ) ),
		esc_html( $labels[ $status ] ?? $status )
	);
}

/**
 * A stored GMT date in the site's time zone and format.
 *
 * @param  string $gmt MySQL datetime, GMT.
 * @return string
 */
function fmc_format_date( $gmt ) {
	return get_date_from_gmt( $gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
}

/**
 * Register the menu and its two screens.
 */
function fmc_add_admin_pages() {
	$list = add_menu_page(
		__( 'Mail Catcher', 'frontlabs-mail-catcher' ),
		__( 'Mail Catcher', 'frontlabs-mail-catcher' ),
		FMC_CAPABILITY,
		FMC_MESSAGES_SLUG,
		'fmc_render_messages_page',
		'dashicons-email-alt',
		81
	);

	add_submenu_page(
		FMC_MESSAGES_SLUG,
		__( 'Messages', 'frontlabs-mail-catcher' ),
		__( 'Messages', 'frontlabs-mail-catcher' ),
		FMC_CAPABILITY,
		FMC_MESSAGES_SLUG,
		'fmc_render_messages_page'
	);

	$settings = add_submenu_page(
		FMC_MESSAGES_SLUG,
		__( 'Mail Catcher settings', 'frontlabs-mail-catcher' ),
		__( 'Settings', 'frontlabs-mail-catcher' ),
		FMC_CAPABILITY,
		FMC_SETTINGS_SLUG,
		'fmc_render_settings_page'
	);

	fmc_screen_hooks( $list );
	fmc_screen_hooks( $settings );

	add_action( 'load-' . $list, 'fmc_load_messages_page' );
}
add_action( 'admin_menu', 'fmc_add_admin_pages' );

/**
 * Before the list renders: bulk delete, and tidy a submitted search form's URL.
 */
function fmc_load_messages_page() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce checked below before anything changes.
	$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

	if ( '' === $action || '-1' === $action ) {
		$action = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';
	}

	if ( 'delete' === $action && ! empty( $_REQUEST['ids'] ) ) {
		check_admin_referer( 'bulk-messages' );

		fmc_store_delete( array_map( 'absint', (array) wp_unslash( $_REQUEST['ids'] ) ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => FMC_MESSAGES_SLUG,
					'fmc_notice' => 'deleted',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	if ( ! empty( $_GET['_wp_http_referer'] ) && isset( $_SERVER['REQUEST_URI'] ) ) {
		wp_safe_redirect( remove_query_arg( array( '_wp_http_referer', '_wpnonce', 'action', 'action2' ), esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) );
		exit;
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
}

/**
 * Styles and the tab script, on the plugin's screens only.
 *
 * @param string $hook_suffix Current admin page.
 */
function fmc_admin_assets( $hook_suffix ) {
	if ( ! in_array( $hook_suffix, fmc_screen_hooks(), true ) ) {
		return;
	}

	$css = FMC_DIR . 'assets/admin.css';
	$js  = FMC_DIR . 'assets/admin.js';

	wp_enqueue_style( 'fmc-admin', FMC_URL . 'assets/admin.css', array(), is_file( $css ) ? (string) filemtime( $css ) : FMC_VERSION );
	wp_enqueue_script( 'fmc-admin', FMC_URL . 'assets/admin.js', array(), is_file( $js ) ? (string) filemtime( $js ) : FMC_VERSION, true );
}
add_action( 'admin_enqueue_scripts', 'fmc_admin_assets' );

/**
 * Notice from a redirect, by code.
 */
function fmc_render_redirect_notice() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects one of a fixed set of messages.
	$code   = isset( $_GET['fmc_notice'] ) ? sanitize_key( wp_unslash( $_GET['fmc_notice'] ) ) : '';
	$notice = fmc_notice_text( $code );

	if ( $notice ) {
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $notice[0] ),
			esc_html( $notice[1] )
		);
	}
}

/**
 * One line with the mode in force, above the list and the details.
 */
function fmc_render_mode_bar() {
	printf(
		'<p class="fmc-mode-bar">%1$s <strong>%2$s</strong> · %3$s <code>%4$s</code> · <a href="%5$s">%6$s</a></p>',
		esc_html__( 'Mode:', 'frontlabs-mail-catcher' ),
		esc_html( fmc_mode_label( fmc_effective_mode() ) ),
		esc_html__( 'Environment:', 'frontlabs-mail-catcher' ),
		esc_html( wp_get_environment_type() ),
		esc_url( admin_url( 'admin.php?page=' . FMC_SETTINGS_SLUG ) ),
		esc_html__( 'Change', 'frontlabs-mail-catcher' )
	);
}

/**
 * Messages screen: the list, or one message.
 */
function fmc_render_messages_page() {
	if ( ! current_user_can( FMC_CAPABILITY ) ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing.
	$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
	$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	if ( 'view' === $action && $id ) {
		fmc_render_message( $id );

		return;
	}

	require_once FMC_DIR . 'includes/class-fmc-list-table.php';

	$table = new FMC_List_Table();
	$table->prepare_items();
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Mail Catcher', 'frontlabs-mail-catcher' ); ?></h1>
		<hr class="wp-header-end">
		<?php
		fmc_render_redirect_notice();
		fmc_render_mode_bar();
		$table->views();
		?>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( FMC_MESSAGES_SLUG ); ?>">
			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- carries the read-only filter over.
			$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

			if ( array_key_exists( $status, fmc_statuses() ) ) {
				printf( '<input type="hidden" name="status" value="%s">', esc_attr( $status ) );
			}

			$table->search_box( __( 'Search messages', 'frontlabs-mail-catcher' ), 'fmc-search' );
			$table->display();
			?>
		</form>
	</div>
	<?php
}

/**
 * A rough plain-text rendering of an HTML body.
 *
 * @param  string $html HTML body.
 * @return string
 */
function fmc_html_to_text( $html ) {
	$text = preg_replace( '#<(br|/p|/div|/tr|/h[1-6]|/li)\b[^>]*>#i', "\n", (string) $html );
	$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

	return trim( preg_replace( "/\n\s*\n\s*\n+/", "\n\n", $text ) );
}

/**
 * Details of one message.
 *
 * @param int $id Message ID.
 */
function fmc_render_message( $id ) {
	$message = fmc_store_get( $id );

	echo '<div class="wrap fmc-message">';

	if ( ! $message ) {
		printf(
			'<h1>%1$s</h1><p>%2$s</p><p><a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Message not found', 'frontlabs-mail-catcher' ),
			esc_html__( 'It may have been deleted, or removed by the retention clean-up.', 'frontlabs-mail-catcher' ),
			esc_url( admin_url( 'admin.php?page=' . FMC_MESSAGES_SLUG ) ),
			esc_html__( 'Back to messages', 'frontlabs-mail-catcher' )
		);

		return;
	}

	if ( ! (int) $message->is_read ) {
		fmc_store_update( $id, array( 'is_read' => 1 ) );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display toggle only.
	$allow_remote = ! empty( $_GET['remote'] );
	$is_html      = 'text/html' === $message->content_type;
	$adjacent     = fmc_store_adjacent( $id );
	$attachments  = json_decode( (string) $message->attachments, true );
	$attachments  = is_array( $attachments ) ? $attachments : array();
	$subject      = '' !== $message->subject ? $message->subject : __( '(no subject)', 'frontlabs-mail-catcher' );

	$attachment_text = array();

	foreach ( $attachments as $attachment ) {
		$attachment_text[] = (string) ( $attachment['name'] ?? '' )
			. ( isset( $attachment['size'] ) ? ' (' . size_format( (int) $attachment['size'] ) . ')' : '' );
	}

	$parent = (int) $message->parent_id;

	$fields = array(
		__( 'Subject', 'frontlabs-mail-catcher' )      => $message->subject,
		__( 'From', 'frontlabs-mail-catcher' )         => $message->from_header,
		__( 'To', 'frontlabs-mail-catcher' )           => $message->to_list,
		__( 'Cc', 'frontlabs-mail-catcher' )           => $message->cc_list,
		__( 'Bcc', 'frontlabs-mail-catcher' )          => $message->bcc_list,
		__( 'Reply-To', 'frontlabs-mail-catcher' )     => $message->reply_to,
		__( 'Delivered to', 'frontlabs-mail-catcher' ) => $message->redirected_to,
		__( 'Date', 'frontlabs-mail-catcher' )         => fmc_format_date( $message->created_at ),
		__( 'Mode', 'frontlabs-mail-catcher' )         => fmc_mode_label( $message->mode ),
		__( 'Error', 'frontlabs-mail-catcher' )        => $message->error,
		__( 'Content-Type', 'frontlabs-mail-catcher' ) => $message->content_type,
		__( 'Attachments', 'frontlabs-mail-catcher' )  => implode( ', ', $attachment_text ),
		__( 'Source', 'frontlabs-mail-catcher' )       => $message->source,
		__( 'Notes', 'frontlabs-mail-catcher' )        => $message->notes,
	);
	?>
	<h1 class="wp-heading-inline"><?php echo esc_html( $subject ); ?></h1>
	<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=' . FMC_MESSAGES_SLUG ) ); ?>"><?php esc_html_e( 'Back to messages', 'frontlabs-mail-catcher' ); ?></a>
	<hr class="wp-header-end">
	<?php
	fmc_render_redirect_notice();
	fmc_render_mode_bar();
	?>

	<div class="fmc-columns">
		<div class="fmc-main">
			<table class="widefat striped fmc-fields">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'frontlabs-mail-catcher' ); ?></th>
						<td><?php echo fmc_status_badge( $message->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></td>
					</tr>
					<?php foreach ( $fields as $label => $value ) : ?>
						<?php
						if ( '' === (string) $value ) {
							continue;
						}
						?>
						<tr>
							<th scope="row"><?php echo esc_html( $label ); ?></th>
							<td><?php echo esc_html( $value ); ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( $parent ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Forwarded from', 'frontlabs-mail-catcher' ); ?></th>
							<td><a href="<?php echo esc_url( fmc_message_url( $parent ) ); ?>">#<?php echo (int) $parent; ?></a></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<div class="fmc-tabs" data-fmc-tabs>
				<div class="fmc-tablist" role="tablist">
					<?php if ( $is_html ) : ?>
						<button type="button" role="tab" class="fmc-tab" aria-controls="fmc-panel-preview"><?php esc_html_e( 'HTML preview', 'frontlabs-mail-catcher' ); ?></button>
					<?php endif; ?>
					<button type="button" role="tab" class="fmc-tab" aria-controls="fmc-panel-text"><?php esc_html_e( 'Text', 'frontlabs-mail-catcher' ); ?></button>
					<button type="button" role="tab" class="fmc-tab" aria-controls="fmc-panel-headers"><?php esc_html_e( 'Headers', 'frontlabs-mail-catcher' ); ?></button>
					<?php if ( $is_html ) : ?>
						<button type="button" role="tab" class="fmc-tab" aria-controls="fmc-panel-source"><?php esc_html_e( 'HTML source', 'frontlabs-mail-catcher' ); ?></button>
					<?php endif; ?>
				</div>

				<?php if ( $is_html ) : ?>
					<section id="fmc-panel-preview" class="fmc-panel" role="tabpanel">
						<h2 class="fmc-panel-title"><?php esc_html_e( 'HTML preview', 'frontlabs-mail-catcher' ); ?></h2>
						<p class="fmc-muted">
							<?php if ( $allow_remote ) : ?>
								<?php esc_html_e( 'Remote images and styles are loaded.', 'frontlabs-mail-catcher' ); ?>
								<a href="<?php echo esc_url( fmc_message_url( $id ) ); ?>"><?php esc_html_e( 'Block them', 'frontlabs-mail-catcher' ); ?></a>
							<?php else : ?>
								<?php esc_html_e( 'Remote images and styles are blocked, so opening a message does not tell its sender.', 'frontlabs-mail-catcher' ); ?>
								<a href="<?php echo esc_url( fmc_message_url( $id, array( 'remote' => 1 ) ) ); ?>"><?php esc_html_e( 'Show remote images', 'frontlabs-mail-catcher' ); ?></a>
							<?php endif; ?>
						</p>
						<?php
						// Empty sandbox: no scripts, no forms, no popups, and a unique
						// origin, so the message cannot reach this admin page's cookies
						// or DOM. Do not add allow-scripts or allow-same-origin.
						printf(
							'<iframe class="fmc-preview" sandbox="" referrerpolicy="no-referrer" title="%1$s" srcdoc="%2$s"></iframe>',
							esc_attr__( 'HTML preview', 'frontlabs-mail-catcher' ),
							fmc_srcdoc_attr( fmc_preview_document( $message->body, 'text/html', $allow_remote ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fmc_srcdoc_attr() escapes; see its docblock for why not esc_attr().
						);
						?>
					</section>
				<?php endif; ?>

				<section id="fmc-panel-text" class="fmc-panel" role="tabpanel">
					<h2 class="fmc-panel-title"><?php esc_html_e( 'Text', 'frontlabs-mail-catcher' ); ?></h2>
					<pre class="fmc-pre"><?php echo esc_html( $is_html ? fmc_html_to_text( $message->body ) : $message->body ); ?></pre>
				</section>

				<section id="fmc-panel-headers" class="fmc-panel" role="tabpanel">
					<h2 class="fmc-panel-title"><?php esc_html_e( 'Headers', 'frontlabs-mail-catcher' ); ?></h2>
					<pre class="fmc-pre"><?php echo esc_html( '' !== $message->headers ? $message->headers : __( '(none passed to wp_mail)', 'frontlabs-mail-catcher' ) ); ?></pre>
				</section>

				<?php if ( $is_html ) : ?>
					<section id="fmc-panel-source" class="fmc-panel" role="tabpanel">
						<h2 class="fmc-panel-title"><?php esc_html_e( 'HTML source', 'frontlabs-mail-catcher' ); ?></h2>
						<pre class="fmc-pre"><?php echo esc_html( $message->body ); ?></pre>
					</section>
				<?php endif; ?>
			</div>
		</div>

		<div class="fmc-side">
			<div class="postbox">
				<h2 class="hndle"><?php esc_html_e( 'Forward to my address', 'frontlabs-mail-catcher' ); ?></h2>
				<div class="inside">
					<?php $addresses = fmc_get_test_addresses(); ?>
					<?php if ( $addresses ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="fmc_forward">
							<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
							<?php wp_nonce_field( 'fmc_forward_' . $id ); ?>
							<p>
								<label for="fmc-forward-address" class="screen-reader-text"><?php esc_html_e( 'Test address', 'frontlabs-mail-catcher' ); ?></label>
								<select id="fmc-forward-address" name="address" class="widefat">
									<?php foreach ( $addresses as $address ) : ?>
										<option value="<?php echo esc_attr( $address ); ?>"><?php echo esc_html( $address ); ?></option>
									<?php endforeach; ?>
								</select>
							</p>
							<p class="fmc-muted"><?php esc_html_e( 'Sent for real, even in Block mode, but only to this address: original To, Cc, Bcc and Reply-To are dropped. Attachments are not stored, so they are not included.', 'frontlabs-mail-catcher' ); ?></p>
							<?php submit_button( __( 'Forward', 'frontlabs-mail-catcher' ), 'secondary', 'submit', false ); ?>
						</form>
					<?php else : ?>
						<p>
							<?php esc_html_e( 'No test address is set.', 'frontlabs-mail-catcher' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . FMC_SETTINGS_SLUG ) ); ?>"><?php esc_html_e( 'Add one in the settings.', 'frontlabs-mail-catcher' ); ?></a>
						</p>
					<?php endif; ?>
				</div>
			</div>

			<p class="fmc-nav">
				<?php if ( $adjacent['newer'] ) : ?>
					<a class="button" href="<?php echo esc_url( fmc_message_url( $adjacent['newer'] ) ); ?>">&larr; <?php esc_html_e( 'Newer', 'frontlabs-mail-catcher' ); ?></a>
				<?php endif; ?>
				<?php if ( $adjacent['older'] ) : ?>
					<a class="button" href="<?php echo esc_url( fmc_message_url( $adjacent['older'] ) ); ?>"><?php esc_html_e( 'Older', 'frontlabs-mail-catcher' ); ?> &rarr;</a>
				<?php endif; ?>
			</p>
			<p>
				<a class="submitdelete fmc-delete" href="<?php echo esc_url( fmc_delete_url( $id ) ); ?>"><?php esc_html_e( 'Delete message', 'frontlabs-mail-catcher' ); ?></a>
			</p>
		</div>
	</div>
	</div>
	<?php
}

/**
 * Settings screen.
 */
function fmc_render_settings_page() {
	if ( ! current_user_can( FMC_CAPABILITY ) ) {
		return;
	}

	$env     = wp_get_environment_type();
	$mode    = fmc_get_mode();
	$saved   = get_option( FMC_MODE_OPTION, '' );
	$confirm = __( 'Delete every recorded message? This cannot be undone.', 'frontlabs-mail-catcher' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Mail Catcher settings', 'frontlabs-mail-catcher' ); ?></h1>
		<?php settings_errors(); ?>

		<form method="post" action="options.php">
			<?php settings_fields( FMC_SETTINGS_GROUP ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Mode', 'frontlabs-mail-catcher' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Mode', 'frontlabs-mail-catcher' ); ?></legend>
							<?php foreach ( fmc_modes() as $key => $label ) : ?>
								<label>
									<input type="radio" name="<?php echo esc_attr( FMC_MODE_OPTION ); ?>" value="<?php echo esc_attr( $key ); ?>" <?php checked( $mode, $key ); ?>>
									<?php echo esc_html( $label ); ?>
								</label><br>
							<?php endforeach; ?>
						</fieldset>
						<p class="description">
							<?php
							printf(
								/* translators: 1: environment type, 2: mode label. */
								esc_html__( 'Environment: %1$s. Default here, while nothing is saved: %2$s.', 'frontlabs-mail-catcher' ),
								'<code>' . esc_html( $env ) . '</code>',
								'<strong>' . esc_html( fmc_mode_label( fmc_default_mode_for_env( $env ) ) ) . '</strong>'
							);

							if ( '' === $saved ) {
								echo ' ' . esc_html__( 'Currently using the default.', 'frontlabs-mail-catcher' );
							}
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fmc-test-addresses"><?php esc_html_e( 'Test addresses', 'frontlabs-mail-catcher' ); ?></label></th>
					<td>
						<textarea id="fmc-test-addresses" name="<?php echo esc_attr( FMC_TEST_ADDRESSES_OPTION ); ?>" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", fmc_get_test_addresses() ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One per line. Redirect mode sends every message only here, and the Forward button can send to any of them.', 'frontlabs-mail-catcher' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fmc-retention"><?php esc_html_e( 'Keep messages for', 'frontlabs-mail-catcher' ); ?></label></th>
					<td>
						<input id="fmc-retention" type="number" min="0" max="<?php echo (int) FMC_RETENTION_MAX; ?>" step="1" class="small-text" name="<?php echo esc_attr( FMC_RETENTION_OPTION ); ?>" value="<?php echo (int) fmc_get_retention_days(); ?>">
						<?php esc_html_e( 'days', 'frontlabs-mail-catcher' ); ?>
						<p class="description"><?php esc_html_e( 'Older messages are deleted once a day. 0 keeps them forever.', 'frontlabs-mail-catcher' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<hr>
		<h2><?php esc_html_e( 'Delete all messages', 'frontlabs-mail-catcher' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('<?php echo esc_js( $confirm ); ?>');">
			<input type="hidden" name="action" value="fmc_delete_all">
			<?php wp_nonce_field( 'fmc_delete_all' ); ?>
			<?php submit_button( __( 'Delete all messages', 'frontlabs-mail-catcher' ), 'delete', 'submit', false ); ?>
		</form>
	</div>
	<?php
}
