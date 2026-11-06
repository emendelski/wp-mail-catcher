<?php
/**
 * The message list, in the shape of Flamingo's inbound messages.
 *
 * Every value printed here came from an email, and much of that from a form a
 * stranger filled in, so every cell is escaped as text.
 *
 * @package frontlabs-mail-catcher
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Message list table.
 */
class FMC_List_Table extends WP_List_Table {

	/**
	 * Set up labels; plural "messages" makes the bulk nonce "bulk-messages".
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'message',
				'plural'   => 'messages',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Requested status filter, if it is a known one.
	 *
	 * @return string
	 */
	private function current_status() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$status = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '';

		return array_key_exists( $status, fmc_statuses() ) ? $status : '';
	}

	/**
	 * Load the current page of messages.
	 */
	public function prepare_items() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list arguments.
		$per_page = 20;
		$result   = fmc_store_query(
			array(
				'status'   => $this->current_status(),
				'search'   => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
				'orderby'  => isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'date',
				'order'    => isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'desc',
				'per_page' => $per_page,
				'paged'    => $this->get_pagenum(),
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->items = $result['items'];

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'subject' );
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'      => '<input type="checkbox" />',
			'subject' => __( 'Subject', 'frontlabs-mail-catcher' ),
			'to'      => __( 'To', 'frontlabs-mail-catcher' ),
			'from'    => __( 'From', 'frontlabs-mail-catcher' ),
			'status'  => __( 'Status', 'frontlabs-mail-catcher' ),
			'source'  => __( 'Source', 'frontlabs-mail-catcher' ),
			'date'    => __( 'Date', 'frontlabs-mail-catcher' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'subject' => array( 'subject', false ),
			'status'  => array( 'status', false ),
			'date'    => array( 'date', true ),
		);
	}

	/**
	 * All | Blocked | Redirected | ... with counts.
	 *
	 * @return array
	 */
	protected function get_views() {
		$counts  = fmc_store_status_counts();
		$current = $this->current_status();
		$base    = admin_url( 'admin.php?page=' . FMC_MESSAGES_SLUG );

		$views = array(
			'all' => sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a>',
				esc_url( $base ),
				'' === $current ? ' class="current" aria-current="page"' : '',
				esc_html__( 'All', 'frontlabs-mail-catcher' ),
				array_sum( $counts )
			),
		);

		foreach ( fmc_statuses() as $status => $label ) {
			if ( empty( $counts[ $status ] ) ) {
				continue;
			}

			$views[ $status ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a>',
				esc_url( add_query_arg( 'status', $status, $base ) ),
				$current === $status ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				$counts[ $status ]
			);
		}

		return $views;
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array( 'delete' => __( 'Delete', 'frontlabs-mail-catcher' ) );
	}

	/**
	 * Message shown with nothing to list.
	 */
	public function no_items() {
		esc_html_e( 'No messages recorded yet.', 'frontlabs-mail-catcher' );
	}

	/**
	 * Checkbox column.
	 *
	 * @param  object $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="ids[]" value="%d" />', (int) $item->id );
	}

	/**
	 * Subject with row actions; bold while unread.
	 *
	 * @param  object $item Row.
	 * @return string
	 */
	protected function column_subject( $item ) {
		$view    = fmc_message_url( (int) $item->id );
		$subject = '' !== $item->subject ? $item->subject : __( '(no subject)', 'frontlabs-mail-catcher' );
		$title   = sprintf( '<a class="row-title" href="%1$s">%2$s</a>', esc_url( $view ), esc_html( $subject ) );

		if ( ! (int) $item->is_read ) {
			$title = '<strong>' . $title . '</strong>';
		}

		$actions = array(
			'view'   => sprintf( '<a href="%1$s">%2$s</a>', esc_url( $view ), esc_html__( 'View', 'frontlabs-mail-catcher' ) ),
			'delete' => sprintf( '<a href="%1$s" class="submitdelete">%2$s</a>', esc_url( fmc_delete_url( (int) $item->id ) ), esc_html__( 'Delete', 'frontlabs-mail-catcher' ) ),
		);

		return $title . $this->row_actions( $actions );
	}

	/**
	 * Recipients; for redirected mail, also where it really went.
	 *
	 * @param  object $item Row.
	 * @return string
	 */
	protected function column_to( $item ) {
		$out = esc_html( $item->to_list );

		if ( '' !== $item->redirected_to ) {
			$out .= '<br><span class="fmc-muted">→ ' . esc_html( $item->redirected_to ) . '</span>';
		}

		return $out;
	}

	/**
	 * Sender.
	 *
	 * @param  object $item Row.
	 * @return string
	 */
	protected function column_from( $item ) {
		return esc_html( $item->from_header );
	}

	/**
	 * Status badge.
	 *
	 * @param  object $item Row.
	 * @return string
	 */
	protected function column_status( $item ) {
		return fmc_status_badge( $item->status );
	}

	/**
	 * Calling code.
	 *
	 * @param  object $item Row.
	 * @return string
	 */
	protected function column_source( $item ) {
		return '<code class="fmc-source">' . esc_html( $item->source ) . '</code>';
	}

	/**
	 * Date in the site's time zone.
	 *
	 * @param  object $item Row.
	 * @return string
	 */
	protected function column_date( $item ) {
		return esc_html( fmc_format_date( $item->created_at ) );
	}
}
