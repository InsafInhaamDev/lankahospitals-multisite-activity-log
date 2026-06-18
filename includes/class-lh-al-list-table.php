<?php
/**
 * WP_List_Table subclass that renders the activity log.
 *
 * @package LH_Activity_Log
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders log rows in the standard admin table style.
 */
class LH_AL_List_Table extends WP_List_Table {

	/**
	 * Cache of blog id => display name.
	 *
	 * @var array
	 */
	private $blog_names = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'activity',
				'plural'   => 'activities',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Column definitions.
	 *
	 * @return array
	 */
	public function get_columns() {
		$columns = array(
			'created_at' => __( 'Date', 'lh-activity-log' ),
			'user_login' => __( 'User', 'lh-activity-log' ),
			'event_type' => __( 'Event', 'lh-activity-log' ),
			'message'    => __( 'Description', 'lh-activity-log' ),
			'ip_address' => __( 'IP', 'lh-activity-log' ),
		);
		if ( is_multisite() ) {
			$columns = array_merge(
				array_slice( $columns, 0, 1, true ),
				array( 'blog_id' => __( 'Site', 'lh-activity-log' ) ),
				array_slice( $columns, 1, null, true )
			);
		}
		return $columns;
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'created_at' => array( 'created_at', true ),
			'user_login' => array( 'user_login', false ),
			'event_type' => array( 'event_type', false ),
			'blog_id'    => array( 'blog_id', false ),
		);
	}

	/**
	 * Default cell renderer.
	 *
	 * @param object $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'event_type':
				return '<span class="lh-al-event lh-al-sev-' . esc_attr( $item->severity ) . '">' . esc_html( $item->event_type ) . '</span>';
			case 'ip_address':
				return esc_html( $item->ip_address );
			default:
				return isset( $item->$column_name ) ? esc_html( $item->$column_name ) : '';
		}
	}

	/**
	 * Date column.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_created_at( $item ) {
		$ts = strtotime( $item->created_at );
		$out  = '<strong>' . esc_html( date_i18n( get_option( 'date_format' ), $ts ) ) . '</strong><br>';
		$out .= '<span class="lh-al-time">' . esc_html( date_i18n( get_option( 'time_format' ), $ts ) ) . '</span>';
		return $out;
	}

	/**
	 * User column.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_user_login( $item ) {
		$label = $item->user_login ? $item->user_login : __( 'system', 'lh-activity-log' );
		$out   = esc_html( $label );
		if ( $item->user_role ) {
			$out .= '<br><span class="lh-al-role">' . esc_html( $item->user_role ) . '</span>';
		}
		return $out;
	}

	/**
	 * Site column (multisite).
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_blog_id( $item ) {
		$id = (int) $item->blog_id;
		if ( ! isset( $this->blog_names[ $id ] ) ) {
			$details = get_blog_details( $id );
			$this->blog_names[ $id ] = $details ? $details->blogname : ( __( 'Site #', 'lh-activity-log' ) . $id );
		}
		return esc_html( $this->blog_names[ $id ] ) . '<br><span class="lh-al-role">#' . $id . '</span>';
	}

	/**
	 * Message column with optional row actions.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_message( $item ) {
		$message = $item->message ? $item->message : '&mdash;';
		$out     = esc_html( $message );

		if ( $item->object_name ) {
			$out .= '<br><span class="lh-al-object">' . esc_html( $item->object_type ) . ': ' . esc_html( $item->object_name ) . '</span>';
		}

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => 'lh-activity-log',
					'action' => 'delete',
					'log_id' => (int) $item->id,
				),
				$this->base_url()
			),
			'lh_al_delete_' . (int) $item->id
		);

		$actions = array(
			'delete' => '<a href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this log entry?', 'lh-activity-log' ) ) . '\');">' . esc_html__( 'Delete', 'lh-activity-log' ) . '</a>',
		);

		return $out . $this->row_actions( $actions );
	}

	/**
	 * Current admin base URL (network or site).
	 *
	 * @return string
	 */
	private function base_url() {
		return is_network_admin() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );
	}

	/**
	 * Load items, set up pagination.
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'lh_al_per_page', 25 );
		$paged    = $this->get_pagenum();

		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'created_at'; // phpcs:ignore WordPress.Security.NonceVerification
		$order   = isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification

		$args = array(
			'blog_id'    => $this->req( 'blog_id' ),
			'user_id'    => $this->req( 'user_id' ),
			'event_type' => $this->req( 'event_type' ),
			'severity'   => $this->req( 'severity' ),
			'search'     => $this->req( 's' ),
			'date_from'  => $this->req( 'date_from' ),
			'date_to'    => $this->req( 'date_to' ),
			'orderby'    => $orderby,
			'order'      => $order,
			'per_page'   => $per_page,
			'paged'      => $paged,
		);

		$result = LH_AL_DB::instance()->query( $args );

		$this->items = $result['items'];

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'created_at' );
	}

	/**
	 * Read a sanitized request value.
	 *
	 * @param string $key Request key.
	 * @return string
	 */
	private function req( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $_REQUEST[ $key ] ) || '' === $_REQUEST[ $key ] ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		return sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) );
	}

	/**
	 * Message shown when there are no rows.
	 */
	public function no_items() {
		esc_html_e( 'No activity recorded yet.', 'lh-activity-log' );
	}
}
