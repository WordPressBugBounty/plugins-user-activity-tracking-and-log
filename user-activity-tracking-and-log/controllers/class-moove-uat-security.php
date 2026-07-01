<?php
/**
 * Moove UAT Security helpers.
 *
 * Provides defences for the public (nopriv) tracking AJAX endpoints
 * WITHOUT relying on WordPress nonces. Page-cache hosts (WP Engine,
 * Kinsta, W3TC, LiteSpeed, Cloudflare APO) serve the same cached HTML
 * to every anonymous visitor — so a nonce baked into the page via
 * wp_localize_script() goes stale within hours and silently breaks
 * tracking for cached visitors.
 *
 * Instead this class combines three cache-safe defences:
 *
 *   1. Origin / Referer validation — the request must come from a page
 *      on this site. Spoofable from curl but blocks browser-based CSRF.
 *   2. Per-IP rate limit via transients — caps how many tracking writes
 *      a single visitor can perform per minute. Stops DB flooding.
 *   3. Per-log session token — bound to the inserted row in a transient,
 *      returned to JS in the AJAX response, and required on the unload
 *      update. Fixes the IDOR on `update_log_unload(log_id)`.
 *
 * @package user-activity-tracking-and-log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Moove_UAT_Security' ) ) :

	/**
	 * Cache-safe security helpers for the nopriv tracking endpoints.
	 */
	class Moove_UAT_Security {

		/**
		 * Per-IP rate limit (writes per minute). Filterable.
		 */
		const RATE_LIMIT_PER_MINUTE = 60;

		/**
		 * Session-token TTL. Long enough to cover a normal page read +
		 * unload, short enough that brute-forcing the token space is
		 * pointless.
		 */
		const TOKEN_TTL = HOUR_IN_SECONDS;

		/**
		 * Validate that the request's Origin / Referer header matches the
		 * site's home URL. Cache-safe — the headers are sent by the browser
		 * on every request, never cached.
		 *
		 * @return bool True when allowed.
		 */
		public static function is_same_origin_request() {
			$origin = '';
			if ( ! empty( $_SERVER['HTTP_ORIGIN'] ) ) {
				$origin = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) );
			} elseif ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
				$origin = sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
			}

			if ( '' === $origin ) {
				// Some browsers strip Referer for privacy reasons. We can't
				// hard-block on that or we'd lose real traffic — defer to
				// the rate limiter as the next line of defence.
				return (bool) apply_filters( 'uat_allow_empty_origin', true );
			}

			$origin_host = wp_parse_url( $origin, PHP_URL_HOST );
			$site_host   = wp_parse_url( home_url(), PHP_URL_HOST );

			if ( ! $origin_host || ! $site_host ) {
				return false;
			}

			$allowed_hosts = apply_filters(
				'uat_allowed_origin_hosts',
				array( strtolower( $site_host ) )
			);

			return in_array( strtolower( $origin_host ), array_map( 'strtolower', (array) $allowed_hosts ), true );
		}

		/**
		 * Throttle the caller by IP. Returns false when the rate limit has
		 * been exceeded for the current minute. Uses transients so it
		 * works on persistent object caches (Redis/Memcached) without
		 * special configuration.
		 *
		 * @param string $ip Visitor IP (already sanitised / validated).
		 * @return bool True when the caller is within budget.
		 */
		public static function check_rate_limit( $ip ) {
			$limit = (int) apply_filters( 'uat_tracking_rate_limit_per_minute', self::RATE_LIMIT_PER_MINUTE );
			if ( $limit <= 0 ) {
				return true;
			}

			$ip  = is_string( $ip ) ? $ip : '';
			$key = 'uat_rl_' . md5( $ip . ':' . gmdate( 'YmdHi' ) );

			$count = (int) get_transient( $key );
			if ( $count >= $limit ) {
				return false;
			}

			set_transient( $key, $count + 1, MINUTE_IN_SECONDS + 5 );
			return true;
		}

		/**
		 * Generate a per-log session token, persist it as a transient
		 * keyed by the log ID and return it for delivery to the client.
		 *
		 * @param int $log_id Inserted log row ID.
		 * @return string Token (empty string on failure).
		 */
		public static function issue_token_for_log( $log_id ) {
			$log_id = (int) $log_id;
			if ( $log_id <= 0 ) {
				return '';
			}

			try {
				$token = bin2hex( random_bytes( 16 ) );
			} catch ( Exception $e ) {
				// Fallback for environments without /dev/urandom.
				$token = wp_generate_password( 32, false, false );
			}

			set_transient( 'uat_tok_' . $log_id, $token, self::TOKEN_TTL );
			return $token;
		}

		/**
		 * Verify a token supplied by the client against the stored value
		 * for a log row. Returns true only when both are present and equal.
		 * Uses hash_equals() to avoid timing side channels.
		 *
		 * @param int    $log_id Log row ID.
		 * @param string $token  Token presented by the client.
		 * @return bool
		 */
		public static function verify_token_for_log( $log_id, $token ) {
			$log_id = (int) $log_id;
			$token  = is_string( $token ) ? $token : '';
			if ( $log_id <= 0 || '' === $token ) {
				return false;
			}

			$stored = get_transient( 'uat_tok_' . $log_id );
			if ( ! is_string( $stored ) || '' === $stored ) {
				return false;
			}

			return hash_equals( $stored, $token );
		}

		/**
		 * Discard a token once it has been consumed (e.g. after a unload
		 * update). Limits replay if the same log_id is brute-forced.
		 *
		 * @param int $log_id Log row ID.
		 */
		public static function consume_token( $log_id ) {
			$log_id = (int) $log_id;
			if ( $log_id > 0 ) {
				delete_transient( 'uat_tok_' . $log_id );
			}
		}
	}

endif;
