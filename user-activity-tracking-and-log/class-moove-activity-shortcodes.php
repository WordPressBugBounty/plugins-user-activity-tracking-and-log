<?php
/**
 * Moove_Activity_Shortcodes File Doc Comment
 *
 * @category    Moove_Activity_Shortcodes
 * @package   moove-activity-tracking
 * @author    Moove Agency
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Moove_Activity_Shortcodes Class Doc Comment
 *
 * @category Class
 * @package  Moove_Activity_Shortcodes
 * @author   Moove Agency
 */
class Moove_Activity_Shortcodes {
	/**
	 * Construct function
	 */
	public function __construct() {
		$this->moove_activity_register_shortcodes();
	}
	/**
	 * Register shortcodes
	 *
	 * @return void
	 */
	public function moove_activity_register_shortcodes() {
		add_shortcode( 'show_ip', array( &$this, 'moove_get_the_user_ip' ) );
	}

	/**
	 * User IP address
	 *
	 * @param bool $filter Conditional parameter to apply GDPR filter or not.
	 *
	 * @return string IP Address.
	 */
	public function moove_get_the_user_ip( $filter = true ) {
		$client = false;
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : false;

		// Real-IP forwarding headers are trivially spoofable, so they
		// must be opt-in (per host topology). Default-off for X-Forwarded-For,
		// default-off for Cloudflare too — sites behind CF must enable
		// the `uat_trust_cloudflare_headers` filter to honour CF-Connecting-IP.
		if ( apply_filters( 'uat_trust_cloudflare_headers', false ) && isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$cf_ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			if ( filter_var( $cf_ip, FILTER_VALIDATE_IP ) ) {
				$remote = $cf_ip;
				$client = $cf_ip;
			}
		} elseif ( isset( $_SERVER['HTTP_CLIENT_IP'] ) ) {
			$client = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
		}

		$forward = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) : false;

		if ( $client && filter_var( $client, FILTER_VALIDATE_IP ) ) {
			$ip = $client;
		} elseif ( $forward && apply_filters( 'uat_allow_xfwdip', false ) ) {
			// X-Forwarded-For can be a comma-separated chain — take the
			// left-most token (closest to the original client) and validate.
			$first = trim( explode( ',', $forward )[0] );
			$ip    = filter_var( $first, FILTER_VALIDATE_IP ) ? $first : $remote;
		} else {
			$ip = $remote;
		}

		if ( ! $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$ip = '';
		}

		return $filter ? apply_filters( 'moove_activity_tracking_ip_filter', $ip ) : $ip;
	}

	/**
	 * Location details by IP address.
	 *
	 * Synchronously hitting an external API on every page-view is the
	 * single biggest latency hit in this plugin. The default behaviour is:
	 *  - return the cached transient if we have one
	 *  - on miss, return false (the caller writes an empty `city` and the
	 *    cron handler `uat_resolve_geo_async` backfills it).
	 * Pass `$sync=true` to force a synchronous lookup (used by the cron
	 * worker itself).
	 *
	 * @param string $ip   IP Address.
	 * @param bool   $sync When true, fetch synchronously on cache miss.
	 * @return object|false stdClass with city/region/ip, or false.
	 */
	public function get_location_details( $ip = false, $sync = false ) {
		if ( ! $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		$transient_key = 'uat_locdata_' . md5( $ip );
		$cached        = get_transient( $transient_key );
		if ( $cached ) {
			return json_decode( $cached );
		}

		if ( ! $sync ) {
			return false;
		}

		// Synchronous path — cron worker only. Tight timeout so a slow
		// upstream cannot stall the worker pool.
		$response = wp_remote_get(
			'https://ipapi.co/' . rawurlencode( $ip ) . '/json',
			array(
				'timeout'     => 2,
				'httpversion' => '1.1',
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$loc  = json_decode( $body, true );
		if ( ! is_array( $loc ) || ! isset( $loc['ip'] ) ) {
			return false;
		}

		$details = array(
			'ip'     => (string) $loc['ip'],
			'city'   => isset( $loc['city'] ) ? (string) $loc['city'] : '',
			'region' => isset( $loc['region'] ) ? (string) $loc['region'] : '',
		);

		set_transient( $transient_key, wp_json_encode( $details ), 30 * DAY_IN_SECONDS );
		return json_decode( wp_json_encode( $details ) );
	}
}
new Moove_Activity_Shortcodes();
