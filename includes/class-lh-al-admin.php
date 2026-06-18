<?php
/**
 * Admin / network-admin UI.
 *
 * @package LH_Activity_Log
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers menu pages, handles actions, and renders the log screen.
 */
class LH_AL_Admin {

	/**
	 * Singleton instance.
	 *
	 * @var LH_AL_Admin|null
	 */
	private static $instance = null;

	/**
	 * Admin page hook suffix.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Get the singleton instance.
	 *
	 * @return LH_AL_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hook into admin.
	 */
	private function __construct() {
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( $this, 'register_menu' ) );
		} else {
			add_action( 'admin_menu', array( $this, 'register_menu' ) );
		}
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_filter( 'set-screen-option', array( $this, 'save_screen_option' ), 10, 3 );
	}

	/**
	 * Capability required to view the log.
	 *
	 * @return string
	 */
	private function capability() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * Register the menu page.
	 */
	public function register_menu() {
		$this->hook = add_menu_page(
			__( 'Activity Log', 'lh-activity-log' ),
			__( 'Activity Log', 'lh-activity-log' ),
			$this->capability(),
			'lh-activity-log',
			array( $this, 'render_page' ),
			'dashicons-list-view',
			3
		);

		add_action( "load-{$this->hook}", array( $this, 'add_screen_options' ) );

		add_submenu_page(
			'lh-activity-log',
			__( 'Activity Log', 'lh-activity-log' ),
			__( 'View Log', 'lh-activity-log' ),
			$this->capability(),
			'lh-activity-log',
			array( $this, 'render_page' )
		);

		add_submenu_page(
			'lh-activity-log',
			__( 'Activity Log Settings', 'lh-activity-log' ),
			__( 'Settings', 'lh-activity-log' ),
			$this->capability(),
			'lh-activity-log-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Per-page screen option.
	 */
	public function add_screen_options() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Entries per page', 'lh-activity-log' ),
				'default' => 25,
				'option'  => 'lh_al_per_page',
			)
		);
	}

	/**
	 * Persist the per-page screen option.
	 *
	 * @param mixed  $status Default.
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 * @return mixed
	 */
	public function save_screen_option( $status, $option, $value ) {
		return 'lh_al_per_page' === $option ? (int) $value : $status;
	}

	/**
	 * Enqueue CSS on our screens only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'lh-activity-log' ) ) {
			return;
		}
		wp_enqueue_style( 'lh-al-admin', LH_AL_URL . 'assets/admin.css', array(), LH_AL_VERSION );
	}

	/**
	 * Base admin URL for our page.
	 *
	 * @return string
	 */
	private function base_url() {
		return is_network_admin() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );
	}

	/**
	 * Handle delete / clear / purge / settings actions.
	 */
	public function handle_actions() {
		if ( ! isset( $_REQUEST['page'] ) || 0 !== strpos( sanitize_key( wp_unslash( $_REQUEST['page'] ) ), 'lh-activity-log' ) ) {
			return;
		}
		if ( ! current_user_can( $this->capability() ) ) {
			return;
		}

		// Save settings.
		if ( isset( $_POST['lh_al_save_settings'] ) ) {
			check_admin_referer( 'lh_al_settings' );
			$days = isset( $_POST['retention_days'] ) ? max( 0, (int) $_POST['retention_days'] ) : 0;
			update_site_option( 'lh_al_retention_days', $days );
			$this->redirect_with_notice( 'lh-activity-log-settings', 'settings-saved' );
		}

		// Delete one row.
		if ( isset( $_GET['action'], $_GET['log_id'] ) && 'delete' === $_GET['action'] ) {
			$id = (int) $_GET['log_id'];
			check_admin_referer( 'lh_al_delete_' . $id );
			LH_AL_DB::instance()->delete( $id );
			$this->redirect_with_notice( 'lh-activity-log', 'deleted' );
		}

		// Clear all.
		if ( isset( $_POST['lh_al_clear_all'] ) ) {
			check_admin_referer( 'lh_al_clear_all' );
			LH_AL_DB::instance()->clear_all();
			$this->redirect_with_notice( 'lh-activity-log', 'cleared' );
		}

		// Manual purge.
		if ( isset( $_POST['lh_al_purge'] ) ) {
			check_admin_referer( 'lh_al_settings' );
			$days = (int) get_site_option( 'lh_al_retention_days', 0 );
			if ( $days > 0 ) {
				LH_AL_DB::instance()->purge_older_than( $days );
			}
			$this->redirect_with_notice( 'lh-activity-log-settings', 'purged' );
		}
	}

	/**
	 * Redirect back to a page with a notice flag.
	 *
	 * @param string $page   Page slug.
	 * @param string $notice Notice key.
	 */
	private function redirect_with_notice( $page, $notice ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => $page,
					'lh_al_notice' => $notice,
				),
				$this->base_url()
			)
		);
		exit;
	}

	/**
	 * Render an admin notice from the query flag.
	 */
	private function maybe_render_notice() {
		if ( ! isset( $_GET['lh_al_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$map = array(
			'deleted'       => __( 'Log entry deleted.', 'lh-activity-log' ),
			'cleared'       => __( 'Activity log cleared.', 'lh-activity-log' ),
			'purged'        => __( 'Old entries purged.', 'lh-activity-log' ),
			'settings-saved' => __( 'Settings saved.', 'lh-activity-log' ),
		);
		$key = sanitize_key( wp_unslash( $_GET['lh_al_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $map[ $key ] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $map[ $key ] ) );
		}
	}

	/**
	 * Render the main log page.
	 */
	public function render_page() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view the activity log.', 'lh-activity-log' ) );
		}

		require_once LH_AL_DIR . 'includes/class-lh-al-list-table.php';
		$table = new LH_AL_List_Table();
		$table->prepare_items();

		$db          = LH_AL_DB::instance();
		$event_types = $db->distinct_event_types();
		?>
		<div class="wrap lh-al-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Activity Log', 'lh-activity-log' ); ?></h1>
			<hr class="wp-header-end">
			<?php $this->maybe_render_notice(); ?>

			<form method="get">
				<input type="hidden" name="page" value="lh-activity-log">
				<div class="lh-al-filters">
					<?php if ( is_multisite() ) : ?>
						<select name="blog_id">
							<option value=""><?php esc_html_e( 'All sites', 'lh-activity-log' ); ?></option>
							<?php
							$current_blog = isset( $_GET['blog_id'] ) ? (int) $_GET['blog_id'] : -1; // phpcs:ignore WordPress.Security.NonceVerification
							foreach ( $this->get_sites_list() as $site_id => $site_name ) :
								?>
								<option value="<?php echo esc_attr( $site_id ); ?>" <?php selected( $current_blog, $site_id ); ?>>
									<?php echo esc_html( $site_name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>

					<select name="event_type">
						<option value=""><?php esc_html_e( 'All events', 'lh-activity-log' ); ?></option>
						<?php
						$current_event = isset( $_GET['event_type'] ) ? sanitize_text_field( wp_unslash( $_GET['event_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
						foreach ( $event_types as $event ) :
							?>
							<option value="<?php echo esc_attr( $event ); ?>" <?php selected( $current_event, $event ); ?>><?php echo esc_html( $event ); ?></option>
						<?php endforeach; ?>
					</select>

					<select name="severity">
						<?php
						$current_sev = isset( $_GET['severity'] ) ? sanitize_text_field( wp_unslash( $_GET['severity'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
						$severities  = array(
							''        => __( 'All severities', 'lh-activity-log' ),
							'info'    => __( 'Info', 'lh-activity-log' ),
							'notice'  => __( 'Notice', 'lh-activity-log' ),
							'warning' => __( 'Warning', 'lh-activity-log' ),
						);
						foreach ( $severities as $value => $label ) :
							?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_sev, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>

					<input type="date" name="date_from" value="<?php echo esc_attr( $this->get_req( 'date_from' ) ); ?>" placeholder="<?php esc_attr_e( 'From', 'lh-activity-log' ); ?>">
					<input type="date" name="date_to" value="<?php echo esc_attr( $this->get_req( 'date_to' ) ); ?>" placeholder="<?php esc_attr_e( 'To', 'lh-activity-log' ); ?>">

					<?php submit_button( __( 'Filter', 'lh-activity-log' ), 'secondary', '', false ); ?>
					<a href="<?php echo esc_url( add_query_arg( 'page', 'lh-activity-log', $this->base_url() ) ); ?>" class="button"><?php esc_html_e( 'Reset', 'lh-activity-log' ); ?></a>
				</div>

				<?php $table->search_box( __( 'Search log', 'lh-activity-log' ), 'lh-al-search' ); ?>
				<?php $table->display(); ?>
			</form>

			<form method="post" class="lh-al-clear-form" onsubmit="return confirm('<?php echo esc_js( __( 'Permanently delete ALL log entries? This cannot be undone.', 'lh-activity-log' ) ); ?>');">
				<?php wp_nonce_field( 'lh_al_clear_all' ); ?>
				<input type="hidden" name="page" value="lh-activity-log">
				<button type="submit" name="lh_al_clear_all" class="button button-link-delete"><?php esc_html_e( 'Clear entire log', 'lh-activity-log' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'lh-activity-log' ) );
		}
		$retention = (int) get_site_option( 'lh_al_retention_days', 0 );
		?>
		<div class="wrap lh-al-wrap">
			<h1><?php esc_html_e( 'Activity Log Settings', 'lh-activity-log' ); ?></h1>
			<?php $this->maybe_render_notice(); ?>

			<form method="post" action="<?php echo esc_url( add_query_arg( 'page', 'lh-activity-log-settings', $this->base_url() ) ); ?>">
				<?php wp_nonce_field( 'lh_al_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="retention_days"><?php esc_html_e( 'Retention period', 'lh-activity-log' ); ?></label></th>
						<td>
							<input name="retention_days" id="retention_days" type="number" min="0" value="<?php echo esc_attr( $retention ); ?>" class="small-text"> <?php esc_html_e( 'days', 'lh-activity-log' ); ?>
							<p class="description"><?php esc_html_e( 'Automatically delete entries older than this many days. Set to 0 to keep all entries forever.', 'lh-activity-log' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" name="lh_al_save_settings" class="button button-primary"><?php esc_html_e( 'Save Settings', 'lh-activity-log' ); ?></button>
					<?php if ( $retention > 0 ) : ?>
						<button type="submit" name="lh_al_purge" class="button"><?php esc_html_e( 'Purge old entries now', 'lh-activity-log' ); ?></button>
					<?php endif; ?>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Map of blog id => name for the filter dropdown.
	 *
	 * @return array
	 */
	private function get_sites_list() {
		$out = array();
		if ( ! is_multisite() ) {
			return $out;
		}
		$sites = get_sites(
			array(
				'number'   => 500,
				'archived' => 0,
				'deleted'  => 0,
			)
		);
		foreach ( $sites as $site ) {
			$details          = get_blog_details( $site->blog_id );
			$out[ $site->blog_id ] = $details ? $details->blogname . ' (' . $site->domain . $site->path . ')' : $site->domain . $site->path;
		}
		return $out;
	}

	/**
	 * Read a sanitized GET value for form repopulation.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private function get_req( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	}
}
