<?php
/**
 * Logging API.
 *
 * @package LH_Activity_Log
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static helper used by hooks (and third-party code) to record events.
 */
class LH_AL_Logger {

	/**
	 * Record an activity event.
	 *
	 * @param string $event_type Machine event key, e.g. "post_updated".
	 * @param array  $args       Optional overrides (object_type, object_id, object_name, message, severity, meta, user_id).
	 * @return int|false Inserted row id.
	 */
	public static function log( $event_type, array $args = array() ) {
		/**
		 * Short-circuit / mute specific events.
		 *
		 * @param bool   $skip       Return true to skip logging.
		 * @param string $event_type Event key.
		 * @param array  $args       Event args.
		 */
		if ( apply_filters( 'lh_al_skip_event', false, $event_type, $args ) ) {
			return false;
		}

		$user = self::current_user_context( isset( $args['user_id'] ) ? (int) $args['user_id'] : null );

		$data = array(
			'blog_id'     => get_current_blog_id(),
			'user_id'     => $user['id'],
			'user_login'  => $user['login'],
			'user_role'   => $user['role'],
			'ip_address'  => self::client_ip(),
			'event_type'  => $event_type,
			'object_type' => isset( $args['object_type'] ) ? (string) $args['object_type'] : '',
			'object_id'   => isset( $args['object_id'] ) ? (int) $args['object_id'] : 0,
			'object_name' => isset( $args['object_name'] ) ? self::truncate( $args['object_name'], 255 ) : '',
			'severity'    => isset( $args['severity'] ) ? (string) $args['severity'] : 'info',
			'message'     => isset( $args['message'] ) ? (string) $args['message'] : '',
			'meta'        => isset( $args['meta'] ) ? $args['meta'] : '',
		);

		/**
		 * Filter the full row before it is written.
		 *
		 * @param array  $data       Row data.
		 * @param string $event_type Event key.
		 */
		$data = apply_filters( 'lh_al_pre_insert', $data, $event_type );

		$id = LH_AL_DB::instance()->insert( $data );

		/**
		 * Fires after an event has been logged.
		 *
		 * @param int|false $id   Inserted id.
		 * @param array     $data Row data.
		 */
		do_action( 'lh_al_logged', $id, $data );

		return $id;
	}

	/**
	 * Resolve the acting user (id, login, role label).
	 *
	 * @param int|null $forced_user_id Force a specific user id.
	 * @return array{id:int,login:string,role:string}
	 */
	private static function current_user_context( $forced_user_id = null ) {
		$user_id = null !== $forced_user_id ? (int) $forced_user_id : get_current_user_id();

		if ( ! $user_id ) {
			return array(
				'id'    => 0,
				'login' => self::is_cron() ? 'cron' : 'system/guest',
				'role'  => '',
			);
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array(
				'id'    => $user_id,
				'login' => '#' . $user_id,
				'role'  => '',
			);
		}

		return array(
			'id'    => $user_id,
			'login' => $user->user_login,
			'role'  => is_array( $user->roles ) && $user->roles ? implode( ', ', $user->roles ) : '',
		);
	}

	/**
	 * Best-effort client IP address.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
		foreach ( $keys as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$value = wp_unslash( $_SERVER[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			// X-Forwarded-For may be a comma list; take the first.
			$value = trim( explode( ',', $value )[0] );
			$ip    = filter_var( $value, FILTER_VALIDATE_IP );
			if ( $ip ) {
				return $ip;
			}
		}
		return '';
	}

	/**
	 * Whether we are in a cron context.
	 *
	 * @return bool
	 */
	private static function is_cron() {
		return ( defined( 'DOING_CRON' ) && DOING_CRON );
	}

	/**
	 * Truncate a string to a maximum length.
	 *
	 * @param string $text Text.
	 * @param int    $len  Max length.
	 * @return string
	 */
	private static function truncate( $text, $len ) {
		$text = (string) $text;
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text ) > $len ? mb_substr( $text, 0, $len ) : $text;
		}
		return strlen( $text ) > $len ? substr( $text, 0, $len ) : $text;
	}
}
