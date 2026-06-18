<?php
/**
 * Database layer for the activity log.
 *
 * @package LH_Activity_Log
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles schema creation and read/write access to the shared log table.
 */
class LH_AL_DB {

	/**
	 * Singleton instance.
	 *
	 * @var LH_AL_DB|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return LH_AL_DB
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Fully-qualified table name.
	 *
	 * On multisite the log lives in the network "base" prefix so every site
	 * writes to one shared table. On single site it uses the normal prefix.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		$prefix = is_multisite() ? $wpdb->base_prefix : $wpdb->prefix;
		return $prefix . LH_AL_TABLE;
	}

	/**
	 * Create or upgrade the log table.
	 */
	public static function install() {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			blog_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			user_login VARCHAR(60) NOT NULL DEFAULT '',
			user_role VARCHAR(100) NOT NULL DEFAULT '',
			ip_address VARCHAR(100) NOT NULL DEFAULT '',
			event_type VARCHAR(60) NOT NULL DEFAULT '',
			object_type VARCHAR(60) NOT NULL DEFAULT '',
			object_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			object_name VARCHAR(255) NOT NULL DEFAULT '',
			severity VARCHAR(20) NOT NULL DEFAULT 'info',
			message TEXT NULL,
			meta LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY blog_id (blog_id),
			KEY user_id (user_id),
			KEY event_type (event_type),
			KEY object_type (object_type),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );

		update_site_option( 'lh_al_db_version', LH_AL_VERSION );
	}

	/**
	 * Insert a log row.
	 *
	 * @param array $data Column => value pairs.
	 * @return int|false Inserted row id or false on failure.
	 */
	public function insert( array $data ) {
		global $wpdb;

		$defaults = array(
			'blog_id'     => get_current_blog_id(),
			'user_id'     => 0,
			'user_login'  => '',
			'user_role'   => '',
			'ip_address'  => '',
			'event_type'  => '',
			'object_type' => '',
			'object_id'   => 0,
			'object_name' => '',
			'severity'    => 'info',
			'message'     => '',
			'meta'        => '',
			'created_at'  => current_time( 'mysql' ),
		);

		$row = wp_parse_args( $data, $defaults );

		if ( is_array( $row['meta'] ) ) {
			$row['meta'] = wp_json_encode( $row['meta'] );
		}

		$formats = array(
			'%d', // blog_id.
			'%d', // user_id.
			'%s', // user_login.
			'%s', // user_role.
			'%s', // ip_address.
			'%s', // event_type.
			'%s', // object_type.
			'%d', // object_id.
			'%s', // object_name.
			'%s', // severity.
			'%s', // message.
			'%s', // meta.
			'%s', // created_at.
		);

		// Keep column order aligned with $formats.
		$ordered = array(
			'blog_id'     => (int) $row['blog_id'],
			'user_id'     => (int) $row['user_id'],
			'user_login'  => (string) $row['user_login'],
			'user_role'   => (string) $row['user_role'],
			'ip_address'  => (string) $row['ip_address'],
			'event_type'  => (string) $row['event_type'],
			'object_type' => (string) $row['object_type'],
			'object_id'   => (int) $row['object_id'],
			'object_name' => (string) $row['object_name'],
			'severity'    => (string) $row['severity'],
			'message'     => (string) $row['message'],
			'meta'        => (string) $row['meta'],
			'created_at'  => (string) $row['created_at'],
		);

		$result = $wpdb->insert( self::table_name(), $ordered, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Query log rows with filters and pagination.
	 *
	 * @param array $args Query args.
	 * @return array{items:array,total:int}
	 */
	public function query( array $args = array() ) {
		global $wpdb;

		$defaults = array(
			'blog_id'    => '',
			'user_id'    => '',
			'event_type' => '',
			'severity'   => '',
			'search'     => '',
			'date_from'  => '',
			'date_to'    => '',
			'orderby'    => 'created_at',
			'order'      => 'DESC',
			'per_page'   => 25,
			'paged'      => 1,
		);

		$args  = wp_parse_args( $args, $defaults );
		$table = self::table_name();

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['blog_id'] && null !== $args['blog_id'] ) {
			$where[]  = 'blog_id = %d';
			$params[] = (int) $args['blog_id'];
		}
		if ( '' !== $args['user_id'] ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $args['user_id'];
		}
		if ( '' !== $args['event_type'] ) {
			$where[]  = 'event_type = %s';
			$params[] = $args['event_type'];
		}
		if ( '' !== $args['severity'] ) {
			$where[]  = 'severity = %s';
			$params[] = $args['severity'];
		}
		if ( '' !== $args['date_from'] ) {
			$where[]  = 'created_at >= %s';
			$params[] = $args['date_from'] . ' 00:00:00';
		}
		if ( '' !== $args['date_to'] ) {
			$where[]  = 'created_at <= %s';
			$params[] = $args['date_to'] . ' 23:59:59';
		}
		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(message LIKE %s OR user_login LIKE %s OR object_name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		// Whitelist orderby / order.
		$allowed_orderby = array( 'id', 'blog_id', 'user_login', 'event_type', 'severity', 'created_at' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		$per_page = max( 1, (int) $args['per_page'] );
		$paged    = max( 1, (int) $args['paged'] );
		$offset   = ( $paged - 1 ) * $per_page;

		// Total count.
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		if ( $params ) {
			$count_sql = $wpdb->prepare( $count_sql, $params ); // phpcs:ignore
		}
		$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore

		// Page of rows.
		$data_params   = array_merge( $params, array( $per_page, $offset ) );
		$data_sql      = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$prepared_data = $wpdb->prepare( $data_sql, $data_params ); // phpcs:ignore
		$items         = $wpdb->get_results( $prepared_data ); // phpcs:ignore

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Distinct event types currently in the log (for filter dropdowns).
	 *
	 * @return array
	 */
	public function distinct_event_types() {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_col( "SELECT DISTINCT event_type FROM {$table} ORDER BY event_type ASC" ); // phpcs:ignore
	}

	/**
	 * Delete a single row.
	 *
	 * @param int $id Row id.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::table_name(), array( 'id' => (int) $id ), array( '%d' ) ); // phpcs:ignore
	}

	/**
	 * Delete every row in the log.
	 *
	 * @return int|false Rows affected.
	 */
	public function clear_all() {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore
	}

	/**
	 * Purge rows older than a number of days.
	 *
	 * @param int $days Retention window in days.
	 * @return int|false Rows affected.
	 */
	public function purge_older_than( $days ) {
		global $wpdb;
		$days = max( 1, (int) $days );
		$table = self::table_name();
		$sql   = $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < ( NOW() - INTERVAL %d DAY )", $days ); // phpcs:ignore
		return $wpdb->query( $sql ); // phpcs:ignore
	}
}
