<?php
/**
 * State-changing admin actions: forward, delete, delete all.
 *
 * All of them go through admin-post.php, check the capability and a nonce
 * bound to the action (and the message, where there is one), and redirect
 * back with a fixed notice code -- never with text taken from the request.
 *
 * @package frontlabs-mail-catcher
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stop unless the current user may use the plugin.
 */
function fmc_require_capability() {
	if ( ! current_user_can( FMC_CAPABILITY ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'frontlabs-mail-catcher' ), 403 );
	}
}

/**
 * Redirect to a plugin screen with a notice code, and stop.
 *
 * @param array $args Query arguments.
 */
function fmc_redirect_back( array $args ) {
	wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
	exit;
}

/**
 * Handle admin-post: send one message to one of the test addresses.
 */
function fmc_handle_forward() {
	fmc_require_capability();

	$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

	check_admin_referer( 'fmc_forward_' . $id );

	$address = isset( $_POST['address'] ) ? sanitize_email( wp_unslash( $_POST['address'] ) ) : '';
	$message = $id ? fmc_store_get( $id ) : null;
	$back    = array(
		'page'   => FMC_MESSAGES_SLUG,
		'action' => 'view',
		'id'     => $id,
	);

	// Only ever an address from the settings: the button is a way to look at a
	// message in a real mail client, not a way to send stored mail anywhere.
	if ( ! $message || ! fmc_is_test_address( $address ) ) {
		fmc_redirect_back( $back + array( 'fmc_notice' => 'forward_invalid' ) );
	}

	$sent = fmc_forward_message( $message, $address );

	fmc_redirect_back( $back + array( 'fmc_notice' => $sent ? 'forwarded' : 'forward_failed' ) );
}
add_action( 'admin_post_fmc_forward', 'fmc_handle_forward' );

/**
 * Handle admin-post: delete one message.
 */
function fmc_handle_delete() {
	fmc_require_capability();

	$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

	check_admin_referer( 'fmc_delete_' . $id );

	fmc_store_delete( array( $id ) );

	fmc_redirect_back(
		array(
			'page'       => FMC_MESSAGES_SLUG,
			'fmc_notice' => 'deleted',
		)
	);
}
add_action( 'admin_post_fmc_delete', 'fmc_handle_delete' );

/**
 * Handle admin-post: delete every message.
 */
function fmc_handle_delete_all() {
	fmc_require_capability();
	check_admin_referer( 'fmc_delete_all' );

	fmc_store_delete_all();

	fmc_redirect_back(
		array(
			'page'       => FMC_MESSAGES_SLUG,
			'fmc_notice' => 'deleted_all',
		)
	);
}
add_action( 'admin_post_fmc_delete_all', 'fmc_handle_delete_all' );

/**
 * Text of a notice code from a redirect.
 *
 * @param  string $code Notice code.
 * @return array{0: string, 1: string}|null Type and message.
 */
function fmc_notice_text( $code ) {
	$notices = array(
		'forwarded'       => array( 'success', __( 'Message forwarded.', 'frontlabs-mail-catcher' ) ),
		'forward_failed'  => array( 'error', __( 'Forwarding failed. The new entry in the list has the error.', 'frontlabs-mail-catcher' ) ),
		'forward_invalid' => array( 'error', __( 'Choose one of the test addresses from the settings.', 'frontlabs-mail-catcher' ) ),
		'deleted'         => array( 'success', __( 'Messages deleted.', 'frontlabs-mail-catcher' ) ),
		'deleted_all'     => array( 'success', __( 'All messages deleted.', 'frontlabs-mail-catcher' ) ),
	);

	return $notices[ $code ] ?? null;
}
