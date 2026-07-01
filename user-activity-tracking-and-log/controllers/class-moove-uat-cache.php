<?php
/**
 * Moove UAT Cache helper.
 *
 * Namespaced facade around the WordPress object cache. Every key written
 * through this helper is automatically prefixed with the current namespace
 * version. Calling Moove_UAT_Cache::bump() increments that version which
 * logically invalidates every entry the plugin has ever cached — in O(1),
 * without touching any other plugin's cache (unlike wp_cache_flush(), which
 * wipes the entire site-wide object cache on Redis/Memcached).
 *
 * Old entries are not actively deleted; they become unreachable and are
 * reclaimed by the cache backend via LRU/TTL.
 *
 * The version itself is stored in wp_options (autoloaded) so it survives
 * full object-cache restarts and stays consistent across PHP workers.
 *
 * @package user-activity-tracking-and-log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Moove_UAT_Cache' ) ) :

	/**
	 * Namespaced cache facade.
	 */
	class Moove_UAT_Cache {

		/**
		 * Cache group used by every key.
		 */
		const GROUP = 'user-activity-tracking-and-log';

		/**
		 * Option storing the current namespace version. Autoloaded so that
		 * version() costs nothing extra per request after WordPress boot.
		 */
		const VERSION_OPTION = 'uat_cache_version';

		/**
		 * In-process version cache so repeated lookups during one request
		 * skip even the object-cache hit.
		 *
		 * @var int|null
		 */
		private static $version = null;

		/**
		 * Return the current namespace version. Falls back to the option
		 * (durable across cache restarts) when the object cache is cold.
		 *
		 * @return int
		 */
		public static function version() {
			if ( null !== self::$version ) {
				return self::$version;
			}

			$version = wp_cache_get( self::VERSION_OPTION, self::GROUP );
			if ( false === $version ) {
				$version = (int) get_option( self::VERSION_OPTION, 1 );
				if ( $version < 1 ) {
					$version = 1;
				}
				wp_cache_set( self::VERSION_OPTION, $version, self::GROUP );
			}

			self::$version = (int) $version;
			return self::$version;
		}

		/**
		 * Build the actual cache key (prefixed with the current namespace
		 * version).
		 *
		 * @param string $key Raw cache key.
		 * @return string
		 */
		public static function key( $key ) {
			return 'v' . self::version() . '_' . $key;
		}

		/**
		 * Invalidate every cache entry stored via this helper. Constant time;
		 * does not touch any other plugin's cache.
		 *
		 * @return int New namespace version.
		 */
		public static function bump() {
			$new_version = self::version() + 1;
			update_option( self::VERSION_OPTION, $new_version, true );
			wp_cache_set( self::VERSION_OPTION, $new_version, self::GROUP );
			self::$version = $new_version;
			return $new_version;
		}

		/**
		 * Namespaced wp_cache_get().
		 *
		 * @param string $key Raw cache key.
		 * @return mixed|false
		 */
		public static function get( $key ) {
			return wp_cache_get( self::key( $key ), self::GROUP );
		}

		/**
		 * Namespaced wp_cache_set().
		 *
		 * @param string $key    Raw cache key.
		 * @param mixed  $value  Value to cache.
		 * @param int    $expire TTL in seconds. 0 = persistent (until evicted/bumped).
		 * @return bool
		 */
		public static function set( $key, $value, $expire = 0 ) {
			return wp_cache_set( self::key( $key ), $value, self::GROUP, (int) $expire );
		}

		/**
		 * Namespaced wp_cache_delete().
		 *
		 * @param string $key Raw cache key.
		 * @return bool
		 */
		public static function delete( $key ) {
			return wp_cache_delete( self::key( $key ), self::GROUP );
		}
	}

endif;
