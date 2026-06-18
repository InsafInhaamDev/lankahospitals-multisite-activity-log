<?php
/**
 * Event listeners that translate WordPress actions into log entries.
 *
 * @package LH_Activity_Log
 */

defined('ABSPATH') || exit;

/**
 * Registers WordPress hooks and forwards them to the logger.
 */
class LH_AL_Hooks
{

	/**
	 * Singleton instance.
	 *
	 * @var LH_AL_Hooks|null
	 */
	private static $instance = null;

	/**
	 * Post statuses we ignore to avoid logging autosaves/revisions noise.
	 *
	 * @var string[]
	 */
	private $ignored_post_types = array('revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_global_styles', 'wp_navigation');

	/**
	 * Get the singleton instance.
	 *
	 * @return LH_AL_Hooks
	 */
	public static function instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire up all listeners.
	 */
	private function __construct()
	{
		// Authentication.
		add_action('wp_login', array($this, 'on_login'), 10, 2);
		add_action('wp_login_failed', array($this, 'on_login_failed'));
		add_action('wp_logout', array($this, 'on_logout'));
		add_action('after_password_reset', array($this, 'on_password_reset'));

		// Posts / pages / CPTs.
		add_action('transition_post_status', array($this, 'on_transition_post_status'), 10, 3);
		add_action('before_delete_post', array($this, 'on_before_delete_post'));
		add_action('wp_trash_post', array($this, 'on_trash_post'));

		// Attachments.
		add_action('add_attachment', array($this, 'on_add_attachment'));
		add_action('delete_attachment', array($this, 'on_delete_attachment'));

		// Users.
		add_action('user_register', array($this, 'on_user_register'));
		add_action('profile_update', array($this, 'on_profile_update'), 10, 2);
		add_action('delete_user', array($this, 'on_delete_user'));
		add_action('set_user_role', array($this, 'on_set_user_role'), 10, 3);

		// Plugins / themes.
		add_action('activated_plugin', array($this, 'on_activated_plugin'), 10, 2);
		add_action('deactivated_plugin', array($this, 'on_deactivated_plugin'), 10, 2);
		add_action('switch_theme', array($this, 'on_switch_theme'), 10, 2);

		// Settings.
		add_action('updated_option', array($this, 'on_updated_option'), 10, 3);

		// Comments.
		add_action('comment_post', array($this, 'on_comment_post'), 10, 2);
		add_action('transition_comment_status', array($this, 'on_transition_comment_status'), 10, 3);

		// Navigation menus.
		add_action('wp_create_nav_menu', array($this, 'on_create_nav_menu'), 10, 2);
		add_action('wp_update_nav_menu', array($this, 'on_update_nav_menu'), 10, 2);

		// Widgets (classic + block-based both write this option).
		add_action('update_option_sidebars_widgets', array($this, 'on_widgets_changed'), 10, 2);

		// Plugin / theme file editor.
		add_action('load-plugin-editor.php', array($this, 'on_file_edit_page'));
		add_action('load-theme-editor.php', array($this, 'on_file_edit_page'));
		add_action('wp_ajax_edit-theme-plugin-file', array($this, 'on_file_edit_ajax'), 1);

		// Core / plugin / theme installs & updates.
		add_action('upgrader_process_complete', array($this, 'on_upgrader_complete'), 10, 2);
		add_action('_core_updated_successfully', array($this, 'on_core_updated'));

		// Terms (categories, tags, custom taxonomies).
		add_action('created_term', array($this, 'on_created_term'), 10, 3);
		add_action('edited_term', array($this, 'on_edited_term'), 10, 3);
		add_action('delete_term', array($this, 'on_delete_term'), 10, 4);

		// Export / import.
		add_action('export_wp', array($this, 'on_export'));
		add_action('import_start', array($this, 'on_import_start'));
		add_action('import_end', array($this, 'on_import_end'));

		// REST (Application Password) + XML-RPC authentication.
		add_action('application_password_did_authenticate', array($this, 'on_app_password_auth'), 10, 2);
		add_action('application_password_failed_authentication', array($this, 'on_app_password_failed'), 10, 1);
		add_action('xmlrpc_call', array($this, 'on_xmlrpc_call'), 10, 1);

		// Multisite-specific.
		add_action('wp_initialize_site', array($this, 'on_site_created'), 950);
		add_action('wp_delete_site', array($this, 'on_site_deleted'));
	}

	/* --------------------------------------------------------------------- */
	/* Authentication                                                        */
	/* --------------------------------------------------------------------- */

	/**
	 * Successful login.
	 *
	 * @param string  $user_login Username.
	 * @param WP_User $user       User object.
	 */
	public function on_login($user_login, $user = null)
	{
		$uid = $user instanceof WP_User ? $user->ID : 0;
		LH_AL_Logger::log(
			'user_login',
			array(
				'user_id' => $uid,
				'object_type' => 'user',
				'object_id' => $uid,
				'object_name' => $user_login,
				'severity' => 'info',
				/* translators: %s: username */
				'message' => sprintf(__('User "%s" logged in.', 'lh-activity-log'), $user_login),
			)
		);
	}

	/**
	 * Failed login attempt.
	 *
	 * @param string $user_login Attempted username.
	 */
	public function on_login_failed($user_login)
	{
		LH_AL_Logger::log(
			'user_login_failed',
			array(
				'user_id' => 0,
				'object_type' => 'user',
				'object_name' => $user_login,
				'severity' => 'warning',
				/* translators: %s: username */
				'message' => sprintf(__('Failed login attempt for "%s".', 'lh-activity-log'), $user_login),
			)
		);
	}

	/**
	 * Logout.
	 */
	public function on_logout()
	{
		$user = wp_get_current_user();
		$name = $user && $user->exists() ? $user->user_login : '';
		LH_AL_Logger::log(
			'user_logout',
			array(
				'object_type' => 'user',
				'object_id' => $user ? $user->ID : 0,
				'object_name' => $name,
				/* translators: %s: username */
				'message' => sprintf(__('User "%s" logged out.', 'lh-activity-log'), $name),
			)
		);
	}

	/**
	 * Password reset completed.
	 *
	 * @param WP_User $user User.
	 */
	public function on_password_reset($user)
	{
		$name = $user instanceof WP_User ? $user->user_login : '';
		LH_AL_Logger::log(
			'user_password_reset',
			array(
				'object_type' => 'user',
				'object_id' => $user instanceof WP_User ? $user->ID : 0,
				'object_name' => $name,
				'severity' => 'notice',
				/* translators: %s: username */
				'message' => sprintf(__('Password was reset for "%s".', 'lh-activity-log'), $name),
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Posts                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * Post status transitions (create, publish, update, trash, etc).
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public function on_transition_post_status($new_status, $old_status, $post)
	{
		if (!$post instanceof WP_Post) {
			return;
		}
		if (in_array($post->post_type, $this->ignored_post_types, true)) {
			return;
		}
		if ('auto-draft' === $new_status || ('auto-draft' === $old_status && 'draft' === $new_status)) {
			return;
		}
		// Skip autosaves.
		if (wp_is_post_autosave($post->ID) || wp_is_post_revision($post->ID)) {
			return;
		}

		$type_label = $this->post_type_label($post);
		$title = $post->post_title ? $post->post_title : __('(no title)', 'lh-activity-log');

		if ('trash' === $new_status) {
			$event = 'post_trashed';
			/* translators: 1: post type, 2: post title */
			$message = sprintf(__('%1$s "%2$s" moved to trash.', 'lh-activity-log'), $type_label, $title);
		} elseif ('publish' === $new_status && 'publish' !== $old_status) {
			$event = 'post_published';
			/* translators: 1: post type, 2: post title */
			$message = sprintf(__('%1$s "%2$s" published.', 'lh-activity-log'), $type_label, $title);
		} elseif ('publish' !== $old_status && 'publish' !== $new_status && 'new' === $old_status) {
			$event = 'post_created';
			/* translators: 1: post type, 2: post title */
			$message = sprintf(__('%1$s "%2$s" created.', 'lh-activity-log'), $type_label, $title);
		} else {
			$event = 'post_updated';
			/* translators: 1: post type, 2: post title */
			$message = sprintf(__('%1$s "%2$s" updated.', 'lh-activity-log'), $type_label, $title);
		}

		LH_AL_Logger::log(
			$event,
			array(
				'object_type' => $post->post_type,
				'object_id' => $post->ID,
				'object_name' => $title,
				'message' => $message,
				'meta' => array(
					'old_status' => $old_status,
					'new_status' => $new_status,
					'edit_link' => get_edit_post_link($post->ID, 'raw'),
				),
			)
		);
	}

	/**
	 * Post permanently deleted.
	 *
	 * @param int $post_id Post id.
	 */
	public function on_before_delete_post($post_id)
	{
		$post = get_post($post_id);
		if (!$post || in_array($post->post_type, $this->ignored_post_types, true)) {
			return;
		}
		$title = $post->post_title ? $post->post_title : __('(no title)', 'lh-activity-log');
		LH_AL_Logger::log(
			'post_deleted',
			array(
				'object_type' => $post->post_type,
				'object_id' => $post->ID,
				'object_name' => $title,
				'severity' => 'warning',
				/* translators: 1: post type, 2: post title */
				'message' => sprintf(__('%1$s "%2$s" permanently deleted.', 'lh-activity-log'), $this->post_type_label($post), $title),
			)
		);
	}

	/**
	 * Catch explicit trash action (redundant with transition but kept for clarity).
	 *
	 * @param int $post_id Post id.
	 */
	public function on_trash_post($post_id)
	{
		// Handled by transition_post_status; intentionally left as a no-op hook
		// point so integrators can extend without duplicate logging.
		unset($post_id);
	}

	/**
	 * Human-readable post type label.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private function post_type_label($post)
	{
		$obj = get_post_type_object($post->post_type);
		if ($obj && isset($obj->labels->singular_name)) {
			return $obj->labels->singular_name;
		}
		return ucfirst($post->post_type);
	}

	/* --------------------------------------------------------------------- */
	/* Attachments                                                           */
	/* --------------------------------------------------------------------- */

	/**
	 * Media uploaded.
	 *
	 * @param int $post_id Attachment id.
	 */
	public function on_add_attachment($post_id)
	{
		$title = get_the_title($post_id);
		LH_AL_Logger::log(
			'media_uploaded',
			array(
				'object_type' => 'attachment',
				'object_id' => $post_id,
				'object_name' => $title,
				/* translators: %s: file title */
				'message' => sprintf(__('Media "%s" uploaded.', 'lh-activity-log'), $title),
			)
		);
	}

	/**
	 * Media deleted.
	 *
	 * @param int $post_id Attachment id.
	 */
	public function on_delete_attachment($post_id)
	{
		$title = get_the_title($post_id);
		LH_AL_Logger::log(
			'media_deleted',
			array(
				'object_type' => 'attachment',
				'object_id' => $post_id,
				'object_name' => $title,
				'severity' => 'warning',
				/* translators: %s: file title */
				'message' => sprintf(__('Media "%s" deleted.', 'lh-activity-log'), $title),
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Users                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * New user registered/created.
	 *
	 * @param int $user_id User id.
	 */
	public function on_user_register($user_id)
	{
		$user = get_userdata($user_id);
		$name = $user ? $user->user_login : '#' . $user_id;
		LH_AL_Logger::log(
			'user_created',
			array(
				'object_type' => 'user',
				'object_id' => $user_id,
				'object_name' => $name,
				'severity' => 'notice',
				/* translators: %s: username */
				'message' => sprintf(__('User "%s" was created.', 'lh-activity-log'), $name),
			)
		);
	}

	/**
	 * Profile updated.
	 *
	 * @param int     $user_id       User id.
	 * @param WP_User $old_user_data Previous data.
	 */
	public function on_profile_update($user_id, $old_user_data = null)
	{
		$user = get_userdata($user_id);
		$name = $user ? $user->user_login : '#' . $user_id;
		LH_AL_Logger::log(
			'user_updated',
			array(
				'object_type' => 'user',
				'object_id' => $user_id,
				'object_name' => $name,
				/* translators: %s: username */
				'message' => sprintf(__('Profile for "%s" was updated.', 'lh-activity-log'), $name),
			)
		);
	}

	/**
	 * User deleted.
	 *
	 * @param int $user_id User id.
	 */
	public function on_delete_user($user_id)
	{
		$user = get_userdata($user_id);
		$name = $user ? $user->user_login : '#' . $user_id;
		LH_AL_Logger::log(
			'user_deleted',
			array(
				'object_type' => 'user',
				'object_id' => $user_id,
				'object_name' => $name,
				'severity' => 'warning',
				/* translators: %s: username */
				'message' => sprintf(__('User "%s" was deleted.', 'lh-activity-log'), $name),
			)
		);
	}

	/**
	 * User role changed.
	 *
	 * @param int    $user_id   User id.
	 * @param string $role      New role.
	 * @param array  $old_roles Old roles.
	 */
	public function on_set_user_role($user_id, $role, $old_roles)
	{
		// user_register also triggers set_user_role; skip the initial assignment.
		if (empty($old_roles)) {
			return;
		}
		$user = get_userdata($user_id);
		$name = $user ? $user->user_login : '#' . $user_id;
		LH_AL_Logger::log(
			'user_role_changed',
			array(
				'object_type' => 'user',
				'object_id' => $user_id,
				'object_name' => $name,
				'severity' => 'notice',
				/* translators: 1: username, 2: old roles, 3: new role */
				'message' => sprintf(__('Role for "%1$s" changed from "%2$s" to "%3$s".', 'lh-activity-log'), $name, implode(', ', (array) $old_roles), $role),
				'meta' => array(
					'old_roles' => $old_roles,
					'new_role' => $role,
				),
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Plugins / themes                                                      */
	/* --------------------------------------------------------------------- */

	/**
	 * Plugin activated.
	 *
	 * @param string $plugin       Plugin file.
	 * @param bool   $network_wide Network activation.
	 */
	public function on_activated_plugin($plugin, $network_wide)
	{
		LH_AL_Logger::log(
			'plugin_activated',
			array(
				'object_type' => 'plugin',
				'object_name' => $plugin,
				'severity' => 'notice',
				/* translators: 1: plugin file, 2: scope */
				'message' => sprintf(__('Plugin "%1$s" activated (%2$s).', 'lh-activity-log'), $plugin, $network_wide ? __('network-wide', 'lh-activity-log') : __('this site', 'lh-activity-log')),
				'meta' => array('network_wide' => (bool) $network_wide),
			)
		);
	}

	/**
	 * Plugin deactivated.
	 *
	 * @param string $plugin       Plugin file.
	 * @param bool   $network_wide Network deactivation.
	 */
	public function on_deactivated_plugin($plugin, $network_wide)
	{
		LH_AL_Logger::log(
			'plugin_deactivated',
			array(
				'object_type' => 'plugin',
				'object_name' => $plugin,
				'severity' => 'notice',
				/* translators: 1: plugin file, 2: scope */
				'message' => sprintf(__('Plugin "%1$s" deactivated (%2$s).', 'lh-activity-log'), $plugin, $network_wide ? __('network-wide', 'lh-activity-log') : __('this site', 'lh-activity-log')),
				'meta' => array('network_wide' => (bool) $network_wide),
			)
		);
	}

	/**
	 * Theme switched.
	 *
	 * @param string   $new_name  New theme name.
	 * @param WP_Theme $new_theme New theme.
	 */
	public function on_switch_theme($new_name, $new_theme = null)
	{
		LH_AL_Logger::log(
			'theme_switched',
			array(
				'object_type' => 'theme',
				'object_name' => $new_name,
				'severity' => 'notice',
				/* translators: %s: theme name */
				'message' => sprintf(__('Active theme switched to "%s".', 'lh-activity-log'), $new_name),
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Settings                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * Whitelisted options we track changes for (avoids noisy transients/caches).
	 *
	 * @return string[]
	 */
	private function tracked_options()
	{
		$defaults = array(
			'blogname',
			'blogdescription',
			'siteurl',
			'home',
			'admin_email',
			'users_can_register',
			'default_role',
			'timezone_string',
			'date_format',
			'time_format',
			'start_of_week',
			'WPLANG',
			'permalink_structure',
			'show_on_front',
			'page_on_front',
			'page_for_posts',
			'posts_per_page',
			'default_comment_status',
			'blog_public',
		);
		/**
		 * Filter the list of options whose changes are logged.
		 *
		 * @param string[] $defaults Option names.
		 */
		return apply_filters('lh_al_tracked_options', $defaults);
	}

	/**
	 * Option updated.
	 *
	 * @param string $option    Option name.
	 * @param mixed  $old_value Old value.
	 * @param mixed  $value     New value.
	 */
	public function on_updated_option($option, $old_value, $value)
	{
		if (!in_array($option, $this->tracked_options(), true)) {
			return;
		}
		LH_AL_Logger::log(
			'setting_updated',
			array(
				'object_type' => 'option',
				'object_name' => $option,
				'severity' => 'notice',
				/* translators: %s: option name */
				'message' => sprintf(__('Setting "%s" was updated.', 'lh-activity-log'), $option),
				'meta' => array(
					'old' => $this->scalarize($old_value),
					'new' => $this->scalarize($value),
				),
			)
		);
	}

	/**
	 * Reduce a value to a short scalar for storage.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function scalarize($value)
	{
		if (is_scalar($value)) {
			return (string) $value;
		}
		return wp_json_encode($value);
	}

	/* --------------------------------------------------------------------- */
	/* Comments                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * New comment posted.
	 *
	 * @param int        $comment_id       Comment id.
	 * @param int|string $comment_approved Approval state.
	 */
	public function on_comment_post($comment_id, $comment_approved)
	{
		$comment = get_comment($comment_id);
		if (!$comment) {
			return;
		}
		LH_AL_Logger::log(
			'comment_created',
			array(
				'object_type' => 'comment',
				'object_id' => $comment_id,
				'object_name' => $comment->comment_author,
				/* translators: 1: author, 2: post title */
				'message' => sprintf(__('New comment by "%1$s" on "%2$s".', 'lh-activity-log'), $comment->comment_author, get_the_title($comment->comment_post_ID)),
			)
		);
	}

	/**
	 * Comment status changed (approved, spam, trash).
	 *
	 * @param string     $new_status New status.
	 * @param string     $old_status Old status.
	 * @param WP_Comment $comment    Comment.
	 */
	public function on_transition_comment_status($new_status, $old_status, $comment)
	{
		if ($new_status === $old_status) {
			return;
		}
		LH_AL_Logger::log(
			'comment_status_changed',
			array(
				'object_type' => 'comment',
				'object_id' => $comment->comment_ID,
				'object_name' => $comment->comment_author,
				'severity' => 'spam' === $new_status ? 'warning' : 'info',
				/* translators: 1: author, 2: new status */
				'message' => sprintf(__('Comment by "%1$s" marked as "%2$s".', 'lh-activity-log'), $comment->comment_author, $new_status),
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Navigation menus                                                      */
	/* --------------------------------------------------------------------- */

	/**
	 * Navigation menu created.
	 *
	 * @param int   $menu_id   Term id of the new menu.
	 * @param array $menu_data Menu data (includes "menu-name").
	 */
	public function on_create_nav_menu($menu_id, $menu_data = array())
	{
		$name = $this->nav_menu_name($menu_id, $menu_data);
		LH_AL_Logger::log(
			'menu_created',
			array(
				'object_type' => 'nav_menu',
				'object_id' => (int) $menu_id,
				'object_name' => $name,
				'severity' => 'notice',
				/* translators: %s: menu name */
				'message' => sprintf(__('Menu "%s" was created.', 'lh-activity-log'), $name),
			)
		);
	}

	/**
	 * Navigation menu updated.
	 *
	 * Fires once per save (e.g. when the menu is saved in Appearance > Menus),
	 * not once per menu item.
	 *
	 * @param int   $menu_id   Term id of the menu.
	 * @param array $menu_data Menu data, when available.
	 */
	public function on_update_nav_menu($menu_id, $menu_data = array())
	{
		$name = $this->nav_menu_name($menu_id, $menu_data);
		LH_AL_Logger::log(
			'menu_updated',
			array(
				'object_type' => 'nav_menu',
				'object_id' => (int) $menu_id,
				'object_name' => $name,
				'severity' => 'info',
				/* translators: %s: menu name */
				'message' => sprintf(__('Menu "%s" was updated.', 'lh-activity-log'), $name),
			)
		);
	}

	/**
	 * Resolve a navigation menu's display name.
	 *
	 * @param int   $menu_id   Menu term id.
	 * @param array $menu_data Optional menu data containing "menu-name".
	 * @return string
	 */
	private function nav_menu_name($menu_id, $menu_data = array())
	{
		if (is_array($menu_data) && !empty($menu_data['menu-name'])) {
			return (string) $menu_data['menu-name'];
		}
		$term = get_term((int) $menu_id, 'nav_menu');
		if ($term && !is_wp_error($term)) {
			return $term->name;
		}
		return __('(menu #', 'lh-activity-log') . (int) $menu_id . ')';
	}

	/* --------------------------------------------------------------------- */
	/* Widgets                                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * Sidebar widgets changed (classic widgets screen or block widgets editor).
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $value     New value.
	 */
	public function on_widgets_changed($old_value, $value)
	{
		static $logged = false;
		if ($logged) {
			return; // The option can be written more than once per request.
		}
		$logged = true;

		LH_AL_Logger::log(
			'widgets_changed',
			array(
				'object_type' => 'widget',
				'object_name' => __('Sidebar widgets', 'lh-activity-log'),
				'severity' => 'info',
				'message' => __('Widgets were changed.', 'lh-activity-log'),
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Plugin / theme file editor                                            */
	/* --------------------------------------------------------------------- */

	/**
	 * File edited through the non-AJAX editor page fallback.
	 */
	public function on_file_edit_page()
	{
		// phpcs:ignore WordPress.Security.NonceVerification
		if (empty($_POST) || !isset($_POST['newcontent'])) {
			return; // Just viewing the editor, not saving.
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		$this->log_file_edit($_POST);
	}

	/**
	 * File edited through the default AJAX editor.
	 */
	public function on_file_edit_ajax()
	{
		// phpcs:ignore WordPress.Security.NonceVerification
		$this->log_file_edit($_REQUEST);
	}

	/**
	 * Record a file edit from the editor request data.
	 *
	 * Best-effort: WordPress provides no post-save action for the built-in file
	 * editor, so we log the edit request (the file name and container).
	 *
	 * @param array $req Request data ($_POST or $_REQUEST).
	 */
	private function log_file_edit($req)
	{
		static $logged = false;
		if ($logged) {
			return;
		}
		$logged = true;

		$file = isset($req['file']) ? sanitize_text_field(wp_unslash($req['file'])) : '';

		if (!empty($req['plugin'])) {
			$type = 'plugin';
			$container = sanitize_text_field(wp_unslash($req['plugin']));
		} elseif (!empty($req['theme'])) {
			$type = 'theme';
			$container = sanitize_text_field(wp_unslash($req['theme']));
		} else {
			$type = 'file';
			$container = $file;
		}

		LH_AL_Logger::log(
			'file_edited',
			array(
				'object_type' => $type . '_file',
				'object_name' => $file ? $file : $container,
				'severity' => 'warning',
				/* translators: 1: plugin/theme, 2: file path */
				'message' => sprintf(__('%1$s file "%2$s" was edited via the built-in editor.', 'lh-activity-log'), ucfirst($type), $file ? $file : $container),
				'meta' => array(
					'container' => $container,
					'file' => $file,
				),
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Installs & updates                                                    */
	/* --------------------------------------------------------------------- */

	/**
	 * Plugin/theme install or update finished.
	 *
	 * @param WP_Upgrader $upgrader   Upgrader instance.
	 * @param array       $hook_extra Context (type, action, plugins/themes).
	 */
	public function on_upgrader_complete($upgrader, $hook_extra)
	{
		if (empty($hook_extra['type'])) {
			return;
		}
		$type = $hook_extra['type'];
		if (!in_array($type, array('plugin', 'theme'), true)) {
			return; // Core handled by _core_updated_successfully; translations skipped.
		}

		$action = isset($hook_extra['action']) ? $hook_extra['action'] : 'update';

		$items = array();
		if ('plugin' === $type) {
			if (!empty($hook_extra['plugins'])) {
				$items = (array) $hook_extra['plugins'];
			} elseif (!empty($hook_extra['plugin'])) {
				$items = array($hook_extra['plugin']);
			}
		} elseif (!empty($hook_extra['themes'])) {
			$items = (array) $hook_extra['themes'];
		} elseif (!empty($hook_extra['theme'])) {
			$items = array($hook_extra['theme']);
		}

		if (empty($items)) {
			$items = array('');
		}

		$installed = ('install' === $action);
		$event = $type . ($installed ? '_installed' : '_updated');

		foreach ($items as $item) {
			$name = $item ? $item : ucfirst($type);
			if ($installed) {
				/* translators: 1: plugin/theme, 2: name */
				$message = sprintf(__('%1$s "%2$s" was installed.', 'lh-activity-log'), ucfirst($type), $name);
			} else {
				/* translators: 1: plugin/theme, 2: name */
				$message = sprintf(__('%1$s "%2$s" was updated.', 'lh-activity-log'), ucfirst($type), $name);
			}

			LH_AL_Logger::log(
				$event,
				array(
					'object_type' => $type,
					'object_name' => $name,
					'severity' => 'notice',
					'message' => $message,
					'meta' => array('action' => $action),
				)
			);
		}
	}

	/**
	 * WordPress core updated successfully.
	 *
	 * @param string $wp_version New version.
	 */
	public function on_core_updated($wp_version)
	{
		LH_AL_Logger::log(
			'core_updated',
			array(
				'object_type' => 'core',
				'object_name' => 'WordPress ' . $wp_version,
				'severity' => 'notice',
				/* translators: %s: version number */
				'message' => sprintf(__('WordPress core was updated to version %s.', 'lh-activity-log'), $wp_version),
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Terms                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * Term created.
	 *
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy.
	 */
	public function on_created_term($term_id, $tt_id, $taxonomy)
	{
		if ('nav_menu' === $taxonomy) {
			return; // Handled by the navigation menu listeners.
		}
		$name = $this->term_name($term_id, $taxonomy);
		LH_AL_Logger::log(
			'term_created',
			array(
				'object_type' => $taxonomy,
				'object_id' => (int) $term_id,
				'object_name' => $name,
				'severity' => 'info',
				/* translators: 1: taxonomy label, 2: term name */
				'message' => sprintf(__('%1$s "%2$s" was created.', 'lh-activity-log'), $this->taxonomy_label($taxonomy), $name),
			)
		);
	}

	/**
	 * Term edited.
	 *
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy.
	 */
	public function on_edited_term($term_id, $tt_id, $taxonomy)
	{
		if ('nav_menu' === $taxonomy) {
			return;
		}
		$name = $this->term_name($term_id, $taxonomy);
		LH_AL_Logger::log(
			'term_updated',
			array(
				'object_type' => $taxonomy,
				'object_id' => (int) $term_id,
				'object_name' => $name,
				'severity' => 'info',
				/* translators: 1: taxonomy label, 2: term name */
				'message' => sprintf(__('%1$s "%2$s" was updated.', 'lh-activity-log'), $this->taxonomy_label($taxonomy), $name),
			)
		);
	}

	/**
	 * Term deleted.
	 *
	 * @param int     $term         Term id.
	 * @param int     $tt_id        Term taxonomy id.
	 * @param string  $taxonomy     Taxonomy.
	 * @param WP_Term $deleted_term Copy of the deleted term.
	 */
	public function on_delete_term($term, $tt_id, $taxonomy, $deleted_term = null)
	{
		if ('nav_menu' === $taxonomy) {
			return;
		}
		$name = ($deleted_term instanceof WP_Term) ? $deleted_term->name : ('#' . (int) $term);
		LH_AL_Logger::log(
			'term_deleted',
			array(
				'object_type' => $taxonomy,
				'object_id' => (int) $term,
				'object_name' => $name,
				'severity' => 'warning',
				/* translators: 1: taxonomy label, 2: term name */
				'message' => sprintf(__('%1$s "%2$s" was deleted.', 'lh-activity-log'), $this->taxonomy_label($taxonomy), $name),
			)
		);
	}

	/**
	 * Human-readable taxonomy label.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return string
	 */
	private function taxonomy_label($taxonomy)
	{
		$tax = get_taxonomy($taxonomy);
		if ($tax && isset($tax->labels->singular_name)) {
			return $tax->labels->singular_name;
		}
		return ucfirst($taxonomy);
	}

	/**
	 * Resolve a term's name.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 * @return string
	 */
	private function term_name($term_id, $taxonomy)
	{
		$term = get_term((int) $term_id, $taxonomy);
		if ($term && !is_wp_error($term)) {
			return $term->name;
		}
		return '#' . (int) $term_id;
	}

	/* --------------------------------------------------------------------- */
	/* Export / import                                                       */
	/* --------------------------------------------------------------------- */

	/**
	 * Content export started (Tools > Export).
	 *
	 * @param array $args Export args.
	 */
	public function on_export($args)
	{
		$what = is_array($args) && !empty($args['content']) ? $args['content'] : 'all';
		LH_AL_Logger::log(
			'content_exported',
			array(
				'object_type' => 'export',
				'object_name' => $what,
				'severity' => 'notice',
				/* translators: %s: content type being exported */
				'message' => sprintf(__('Content export started (%s).', 'lh-activity-log'), $what),
			)
		);
	}

	/**
	 * Content import started (fired by the WordPress Importer).
	 */
	public function on_import_start()
	{
		LH_AL_Logger::log(
			'content_import_started',
			array(
				'object_type' => 'import',
				'severity' => 'notice',
				'message' => __('A content import was started.', 'lh-activity-log'),
			)
		);
	}

	/**
	 * Content import finished.
	 */
	public function on_import_end()
	{
		LH_AL_Logger::log(
			'content_import_finished',
			array(
				'object_type' => 'import',
				'severity' => 'notice',
				'message' => __('A content import finished.', 'lh-activity-log'),
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* REST / XML-RPC authentication                                         */
	/* --------------------------------------------------------------------- */

	/**
	 * Application Password authentication succeeded (REST / external apps).
	 *
	 * @param WP_User $user The authenticated user.
	 * @param array   $item The application password record.
	 */
	public function on_app_password_auth($user, $item)
	{
		$login = $user instanceof WP_User ? $user->user_login : '';
		$pwd_name = is_array($item) && isset($item['name']) ? $item['name'] : '';
		LH_AL_Logger::log(
			'rest_auth_success',
			array(
				'user_id' => $user instanceof WP_User ? $user->ID : 0,
				'object_type' => 'authentication',
				'object_name' => $login,
				'severity' => 'info',
				/* translators: 1: username, 2: application password name */
				'message' => sprintf(__('Application Password authentication succeeded for "%1$s" (%2$s).', 'lh-activity-log'), $login, $pwd_name),
				'meta' => array('app_password_name' => $pwd_name),
			)
		);
	}

	/**
	 * Application Password authentication failed.
	 *
	 * @param WP_Error $error The authentication error.
	 */
	public function on_app_password_failed($error)
	{
		$detail = $error instanceof WP_Error ? $error->get_error_message() : '';
		LH_AL_Logger::log(
			'rest_auth_failed',
			array(
				'object_type' => 'authentication',
				'severity' => 'warning',
				'message' => trim(__('Application Password authentication failed.', 'lh-activity-log') . ' ' . $detail),
			)
		);
	}

	/**
	 * XML-RPC request received. Logged once per request to avoid flooding on
	 * multicall. Failed XML-RPC logins are already captured via wp_login_failed.
	 *
	 * @param string $method The XML-RPC method name.
	 */
	public function on_xmlrpc_call($method)
	{
		static $logged = false;
		if ($logged) {
			return;
		}
		$logged = true;
		LH_AL_Logger::log(
			'xmlrpc_call',
			array(
				'object_type' => 'xmlrpc',
				'object_name' => (string) $method,
				'severity' => 'info',
				/* translators: %s: XML-RPC method name */
				'message' => sprintf(__('XML-RPC request received (method: %s).', 'lh-activity-log'), (string) $method),
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Multisite                                                             */
	/* --------------------------------------------------------------------- */

	/**
	 * New site created in the network.
	 *
	 * @param WP_Site $site Site.
	 */
	public function on_site_created($site)
	{
		$name = $site instanceof WP_Site ? $site->domain . $site->path : '';
		LH_AL_Logger::log(
			'site_created',
			array(
				'blog_id' => $site instanceof WP_Site ? (int) $site->blog_id : get_current_blog_id(),
				'object_type' => 'site',
				'object_id' => $site instanceof WP_Site ? (int) $site->blog_id : 0,
				'object_name' => $name,
				'severity' => 'notice',
				/* translators: %s: site address */
				'message' => sprintf(__('New site "%s" was added to the network.', 'lh-activity-log'), $name),
			)
		);
	}

	/**
	 * Site deleted from the network.
	 *
	 * @param WP_Site $site Site.
	 */
	public function on_site_deleted($site)
	{
		$name = $site instanceof WP_Site ? $site->domain . $site->path : '';
		LH_AL_Logger::log(
			'site_deleted',
			array(
				'blog_id' => $site instanceof WP_Site ? (int) $site->blog_id : 0,
				'object_type' => 'site',
				'object_id' => $site instanceof WP_Site ? (int) $site->blog_id : 0,
				'object_name' => $name,
				'severity' => 'warning',
				/* translators: %s: site address */
				'message' => sprintf(__('Site "%s" was deleted from the network.', 'lh-activity-log'), $name),
			)
		);
	}
}
