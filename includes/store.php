<?php
/**
 * The message table.
 *
 * A table of its own rather than a post type: the log can grow by hundreds of
 * rows a day on a busy development copy, and none of it belongs in wp_posts,
 * the search index or the export tools.
 *
 * Every query goes through $wpdb->prepare() with %i for the table name, and
 * every ordering or filter value is checked against a fixed list first.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
 *
 * @package frontlabs-mail-catcher
 */

defined( 'ABSPATH' ) || exit;

const FMC_DB_VERSION_OPTION = 'fmc_db_version';

/**
 * Statuses a message can have, with their labels.
 *
 * @return array<string, string>
 */
function fmc_statuses() {
	return array(
		'pending'    => __( 'Pending', 'frontlabs-mail-catcher' ),
		'blocked'    => __( 'Blocked', 'frontlabs-mail-catcher' ),
		'redirected' => __( 'Redirected', 'frontlabs-mail-catcher' ),
		'sent'       => __( 'Sent', 'frontlabs-mail-catcher' ),
		'forwarded'  => __( 'Forwarded', 'frontlabs-mail-catcher' ),
		'failed'     => __( 'Failed', 'frontlabs-mail-catcher' ),
		'other'      => __( 'Taken over by another plugin', 'frontlabs-mail-catcher' ),
	);
}

/**
 * Columns a caller may write.
 *
 * @return string[]
 */
function fmc_store_columns() {
	return array(
		'created_at',
		'mode',
		'status',
		'subject',
		'from_header',
		'to_list',
		'cc_list',
		'bcc_list',
		'reply_to',
		'redirected_to',
		'headers',
		'content_type',
		'body',
		'attachments',
		'source',
		'error',
		'notes',
		'is_read',
		'parent_id',
	);
}

/**
 * Full table name.
 *
 * @return string
 */
function fmc_table() {
	global $wpdb;

	return $wpdb->prefix . 'fmc_messages';
}

/**
 * Create or update the table.
 */
function fmc_install() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table   = fmc_table();
	$collate = $wpdb->get_charset_collate();

	dbDelta(
		"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			mode varchar(20) NOT NULL default '',
			status varchar(20) NOT NULL default '',
			subject text NOT NULL,
			from_header text NOT NULL,
			to_list text NOT NULL,
			cc_list text NOT NULL,
			bcc_list text NOT NULL,
			reply_to text NOT NULL,
			redirected_to text NOT NULL,
			headers longtext NOT NULL,
			content_type varchar(100) NOT NULL default '',
			body longtext NOT NULL,
			attachments text NOT NULL,
			source text NOT NULL,
			error text NOT NULL,
			notes text NOT NULL,
			is_read tinyint(1) NOT NULL default 0,
			parent_id bigint(20) unsigned NOT NULL default 0,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY status (status)
		) {$collate};"
	);

	update_option( FMC_DB_VERSION_OPTION, FMC_DB_VERSION );
}

/**
 * Create the table if the plugin's files were updated without reactivation.
 *
 * A single autoloaded option compared on every request; dbDelta only runs on
 * a mismatch.
 */
function fmc_maybe_install() {
	if ( FMC_DB_VERSION !== get_option( FMC_DB_VERSION_OPTION ) ) {
		fmc_install();
	}
}
add_action( 'plugins_loaded', 'fmc_maybe_install' );

/**
 * Keep only known columns, as strings or ints.
 *
 * @param  array $data Column => value.
 * @return array
 */
function fmc_store_clean( array $data ) {
	$clean = array();

	foreach ( array_intersect_key( $data, array_flip( fmc_store_columns() ) ) as $column => $value ) {
		if ( is_array( $value ) ) {
			$value = wp_json_encode( $value );
		}

		$clean[ $column ] = in_array( $column, array( 'is_read', 'parent_id' ), true ) ? (int) $value : (string) $value;
	}

	return $clean;
}

/**
 * Insert a message.
 *
 * @param  array $record Column => value.
 * @return int New ID, or 0 when the row could not be written.
 */
function fmc_store_insert( array $record ) {
	global $wpdb;

	$record = array_merge(
		array_fill_keys( fmc_store_columns(), '' ),
		array(
			'created_at'  => current_time( 'mysql', true ),
			'is_read'     => 0,
			'parent_id'   => 0,
			'attachments' => '[]',
		),
		$record
	);

	return $wpdb->insert( fmc_table(), fmc_store_clean( $record ) ) ? (int) $wpdb->insert_id : 0;
}

/**
 * Update columns of one message.
 *
 * @param  int   $id   Message ID.
 * @param  array $data Column => value.
 * @return bool
 */
function fmc_store_update( $id, array $data ) {
	global $wpdb;

	$id   = absint( $id );
	$data = fmc_store_clean( $data );

	if ( ! $id || ! $data ) {
		return false;
	}

	return false !== $wpdb->update( fmc_table(), $data, array( 'id' => $id ) );
}

/**
 * One message.
 *
 * @param  int $id Message ID.
 * @return object|null
 */
function fmc_store_get( $id ) {
	global $wpdb;

	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', fmc_table(), absint( $id ) ) );
}

/**
 * WHERE clause shared by the list and its count.
 *
 * @param  array $args status, search.
 * @return string Prepared SQL fragment, starting with "WHERE 1=1".
 */
function fmc_store_where( array $args ) {
	global $wpdb;

	$where = 'WHERE 1=1';

	if ( ! empty( $args['status'] ) && array_key_exists( $args['status'], fmc_statuses() ) ) {
		$where .= $wpdb->prepare( ' AND status = %s', $args['status'] );
	}

	if ( isset( $args['search'] ) && '' !== $args['search'] ) {
		$like   = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		$where .= $wpdb->prepare( ' AND ( subject LIKE %s OR from_header LIKE %s OR to_list LIKE %s )', $like, $like, $like );
	}

	return $where;
}

/**
 * A page of messages.
 *
 * @param  array $args status, search, orderby, order, per_page, paged.
 * @return array{items: object[], total: int}
 */
function fmc_store_query( array $args ) {
	global $wpdb;

	$orderby_allowed = array(
		'date'    => 'created_at',
		'subject' => 'subject',
		'status'  => 'status',
	);

	$orderby  = $orderby_allowed[ $args['orderby'] ?? 'date' ] ?? 'created_at';
	$order    = 'asc' === strtolower( (string) ( $args['order'] ?? 'desc' ) ) ? 'ASC' : 'DESC';
	$per_page = max( 1, min( 200, absint( $args['per_page'] ?? 20 ) ) );
	$offset   = ( max( 1, absint( $args['paged'] ?? 1 ) ) - 1 ) * $per_page;
	$where    = fmc_store_where( $args );

	// $where is built from prepare() above, $order from a two-value whitelist.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$items = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, created_at, mode, status, subject, from_header, to_list, redirected_to, source, is_read FROM %i {$where} ORDER BY %i {$order}, id {$order} LIMIT %d OFFSET %d",
			fmc_table(),
			$orderby,
			$per_page,
			$offset
		)
	);

	$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i {$where}", fmc_table() ) );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return array(
		'items' => $items ? $items : array(),
		'total' => $total,
	);
}

/**
 * Number of messages per status.
 *
 * @return array<string, int>
 */
function fmc_store_status_counts() {
	global $wpdb;

	$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM %i GROUP BY status', fmc_table() ) );
	$counts = array();

	foreach ( (array) $rows as $row ) {
		$counts[ $row->status ] = (int) $row->total;
	}

	return $counts;
}

/**
 * IDs of the neighbouring messages in date order.
 *
 * @param  int $id Message ID.
 * @return array{newer: int, older: int}
 */
function fmc_store_adjacent( $id ) {
	global $wpdb;

	$id = absint( $id );

	return array(
		'newer' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id > %d ORDER BY id ASC LIMIT 1', fmc_table(), $id ) ),
		'older' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id < %d ORDER BY id DESC LIMIT 1', fmc_table(), $id ) ),
	);
}

/**
 * Delete messages.
 *
 * @param  int[] $ids Message IDs.
 * @return int Rows deleted.
 */
function fmc_store_delete( array $ids ) {
	global $wpdb;

	$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );

	if ( ! $ids ) {
		return 0;
	}

	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

	// $placeholders is a run of literal "%d" tokens, one per ID.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id IN ({$placeholders})", fmc_table(), ...$ids ) );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
}

/**
 * Delete every message.
 *
 * @return int Rows deleted.
 */
function fmc_store_delete_all() {
	global $wpdb;

	return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', fmc_table() ) );
}

/**
 * Delete messages older than a number of days.
 *
 * @param  int $days Age limit; 0 does nothing.
 * @return int Rows deleted.
 */
function fmc_store_prune( $days ) {
	global $wpdb;

	$days = absint( $days );

	if ( ! $days ) {
		return 0;
	}

	$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

	return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', fmc_table(), $cutoff ) );
}
