<?php
/*
Plugin Name: WP Redis User Session Storage
Plugin URI: https://ethitter.com/plugins/wp-redis-user-session-storage/
Description: Store WordPress session tokens in Redis rather than the usermeta table. Requires the Redis PECL extension.
Version: 0.1
Author: Erick Hitter
Author URI: https://ethitter.com/

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/**
 * Redis-based user sessions token manager.
 *
 * @since 0.1
 */
class WP_Redis_User_Session_Storage extends WP_Session_Tokens {
	/**
	 * Holds the Redis client.
	 *
	 * @var
	 */
	private $redis;

	/**
	 * Track if Redis is available
	 *
	 * @var bool
	 */
	private $redis_connected = false;

	/**
	 * Prefix used to namespace keys
	 *
	 * @var string
	 */
	public $prefix = 'wpruss';

	/**
	 * Create Redis connection using the Redis PECL extension
	 */
	public function __construct( $user_id ) {
		// General Redis settings
		$redis = array(
			'host'       => '127.0.0.1',
			'port'       => 6379,
			'serializer' => Redis::SERIALIZER_PHP,
		);

		if ( defined( 'WP_REDIS_USER_SESSION_HOST' ) && WP_REDIS_USER_SESSION_HOST ) {
			$redis['host'] = WP_REDIS_USER_SESSION_HOST;
		}
		if ( defined( 'WP_REDIS_USER_SESSION_PORT' ) && WP_REDIS_USER_SESSION_PORT ) {
			$redis['port'] = WP_REDIS_USER_SESSION_PORT;
		}
		if ( defined( 'WP_REDIS_USER_SESSION_AUTH' ) && WP_REDIS_USER_SESSION_AUTH ) {
			$redis['auth'] = WP_REDIS_USER_SESSION_AUTH;
		}
		if ( defined( 'WP_REDIS_USER_SESSION_DB' ) && WP_REDIS_USER_SESSION_DB ) {
			$redis['database'] = WP_REDIS_USER_SESSION_DB;
		}
		if ( defined( 'WP_REDIS_USER_SESSION_SERIALIZER' ) && WP_REDIS_USER_SESSION_SERIALIZER ) {
			$redis['serializer'] =  WP_REDIS_USER_SESSION_SERIALIZER;
		}

		// Use Redis PECL library.
		try {
			$this->redis = new Redis();
			$this->redis->connect( $redis['host'], $redis['port'] );
			$this->redis->setOption( Redis::OPT_SERIALIZER, $redis['serializer'] );

			if ( isset( $redis['auth'] ) ) {
				$this->redis->auth( $redis['auth'] );
			}

			if ( isset( $redis['database'] ) ) {
				$this->redis->select( $redis['database'] );
			}

			$this->redis_connected = true;
		} catch ( RedisException $e ) {
			$this->redis_connected = false;
		}

		// Ensure Core's session constructor fires
		parent::__construct( $user_id );
	}

	/**
	 * Get all sessions of a user.
	 *
	 * @since 0.1
	 * @access protected
	 *
	 * @return array Sessions of a user.
	 */
	protected function get_sessions() {
		if ( ! $this->redis_connected ) {
			return array();
		}

		$key = $this->get_key();

		if ( ! $this->redis->exists( $key ) ) {
			return array();
		}

		$sessions = $this->redis->get( $key );
		if ( ! is_array( $sessions ) ) {
			return array();
		}

		$sessions = array_map( array( $this, 'prepare_session' ), $sessions );
		return array_filter( $sessions, array( $this, 'is_still_valid' ) );
	}

	/**
	 * Converts an expiration to an array of session information.
	 *
	 * @param mixed $session Session or expiration.
	 * @return array Session.
	 */
	protected function prepare_session( $session ) {
		if ( is_int( $session ) ) {
			return array( 'expiration' => $session );
		}

		return $session;
	}

	/**
	 * Retrieve a session by its verifier (token hash).
	 *
	 * @since 0.1
	 * @access protected
	 *
	 * @param string $verifier Verifier of the session to retrieve.
	 * @return array|null The session, or null if it does not exist
	 */
	protected function get_session( $verifier ) {
		$sessions = $this->get_sessions();

		if ( isset( $sessions[ $verifier ] ) ) {
			return $sessions[ $verifier ];
		}

		return null;
	}

	/**
	 * Update a session by its verifier.
	 *
	 * @since 0.1
	 * @access protected
	 *
	 * @param string $verifier Verifier of the session to update.
	 * @param array  $session  Optional. Session. Omitting this argument destroys the session.
	 */
	protected function update_session( $verifier, $session = null ) {
		$sessions = $this->get_sessions();

		if ( $session ) {
			$sessions[ $verifier ] = $session;
		} else {
			unset( $sessions[ $verifier ] );
		}

		$this->update_sessions( $sessions );
	}

	/**
	 * Update a user's sessions in Redis.
	 *
	 * @since 0.1
	 * @access protected
	 *
	 * @param array $sessions Sessions.
	 */
	protected function update_sessions( $sessions ) {
		if ( ! $this->redis_connected ) {
			return;
		}

		if ( ! has_filter( 'attach_session_information' ) ) {
			$sessions = wp_list_pluck( $sessions, 'expiration' );
		}

		$key = $this->get_key();

		if ( $sessions ) {
			$this->redis->set( $key, $sessions );
		} elseif ( $this->redis->exists( $key ) ) {
			$this->redis->del( $key );
		}
	}

	/**
	 * Destroy all session tokens for a user, except a single session passed.
	 *
	 * @since 0.1
	 * @access protected
	 *
	 * @param string $verifier Verifier of the session to keep.
	 */
	protected function destroy_other_sessions( $verifier ) {
		$session = $this->get_session( $verifier );
		$this->update_sessions( array( $verifier => $session ) );
	}

	/**
	 * Destroy all session tokens for a user.
	 *
	 * @since 0.1
	 * @access protected
	 */
	protected function destroy_all_sessions() {
		$this->update_sessions( array() );
	}

	/**
	 * Destroy all session tokens for all users.
	 *
	 * @since 0.1
	 * @access public
	 * @static
	 */
	public static function drop_sessions() {
		return false;
	}

	/**
	 * Build key for current user
	 *
	 * @since 0.1
	 * @access protected
	 *
	 * @return string
	 */
	protected function get_key() {
		return $this->prefix . ':' . $this->user_id;
	}
}

/**
 * Override Core's default usermeta-based token storage
 *
 * @filter session_token_manager
 * @return string
 */
function wp_redis_user_session_storage() {
	return 'WP_Redis_User_Session_Storage';
}
add_filter( 'session_token_manager', 'wp_redis_user_session_storage' );
