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

		// Real-IP forwarding headers are trivially spoofable: on a site that
		// is not behind a proxy, anything read from them is attacker-chosen.
		// Because this value is written to an audit log, honouring one by
		// default would let a visitor forge the origin of their own entries.
		// So every header is opt-in per host topology and all default to off
		// — `uat_trust_cloudflare_headers` for CF-Connecting-IP,
		// `uat_trust_client_ip_header` for Client-IP, and `uat_allow_xfwdip`
		// for X-Forwarded-For below. Enable only the one your proxy sets and
		// overwrites (a proxy that appends rather than overwrites is still
		// forgeable).
		if ( apply_filters( 'uat_trust_cloudflare_headers', false ) && isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$cf_ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			if ( filter_var( $cf_ip, FILTER_VALIDATE_IP ) ) {
				$remote = $cf_ip;
				$client = $cf_ip;
			}
		} elseif ( apply_filters( 'uat_trust_client_ip_header', false ) && isset( $_SERVER['HTTP_CLIENT_IP'] ) ) {
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
	 * Geolocation providers, tried in order until one returns a usable
	 * result. Neither needs a key, and each free tier allows 1,000 requests
	 * a day per server IP - shared by every site on that server, and with
	 * the GDPR Cookie Compliance add-on, which uses the same two providers.
	 * ipwho.is is first because ipapi.co has been seen answering with HTTP
	 * 429 well before that limit; together they double the daily allowance,
	 * and the cooldown below stops an exhausted one being retried.
	 *
	 * @return array Provider slug => endpoint template (%s = IP).
	 */
	private function get_geo_providers() {
		return array(
			'ipwhois' => 'https://ipwho.is/%s',
			'ipapi'   => 'https://ipapi.co/%s/json',
		);
	}

	/**
	 * Stand a provider down after a quota or outage response.
	 *
	 * A 429 or 5xx is never specific to the IP being looked up — the next
	 * IP would fail identically and burn the same quota. Skipping the
	 * provider entirely for a while is what stops a busy site from
	 * rate-limiting itself.
	 *
	 * @param string $provider Provider slug.
	 * @return void
	 */
	private function start_geo_cooldown( $provider ) {
		set_transient( 'uat_geo_cooldown_' . $provider, 1, 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * Map a provider's payload onto the shape this plugin stores.
	 *
	 * Both providers can report failure in a HTTP 200 body, so the
	 * per-provider success flag is checked here rather than by status
	 * code alone.
	 *
	 * @param string $provider Provider slug.
	 * @param array  $loc      Decoded response body.
	 * @return array|false Normalised details, or false if the payload is an error.
	 */
	private function normalise_geo_response( $provider, array $loc ) {
		if ( 'ipwhois' === $provider ) {
			if ( empty( $loc['success'] ) ) {
				return false;
			}
			return array(
				'ip'      => isset( $loc['ip'] ) ? (string) $loc['ip'] : '',
				'city'    => isset( $loc['city'] ) ? (string) $loc['city'] : '',
				'region'  => isset( $loc['region'] ) ? (string) $loc['region'] : '',
				'country' => isset( $loc['country'] ) ? (string) $loc['country'] : '',
			);
		}

		if ( 'ipapi' === $provider ) {
			// `error` covers rate-limits and reserved ranges, some of
			// which are returned with a 200 status.
			if ( ! empty( $loc['error'] ) || ! isset( $loc['ip'] ) ) {
				return false;
			}
			return array(
				'ip'      => (string) $loc['ip'],
				'city'    => isset( $loc['city'] ) ? (string) $loc['city'] : '',
				'region'  => isset( $loc['region'] ) ? (string) $loc['region'] : '',
				'country' => isset( $loc['country_name'] ) ? (string) $loc['country_name'] : '',
			);
		}

		return false;
	}

	/**
	 * Query a single provider.
	 *
	 * @param string $provider Provider slug.
	 * @param string $endpoint Endpoint template (%s = IP).
	 * @param string $ip       IP Address.
	 * @return array|false Normalised details, or false on any failure.
	 */
	private function fetch_geo_from( $provider, $endpoint, $ip ) {
		$response = wp_remote_get(
			sprintf( $endpoint, rawurlencode( $ip ) ),
			array(
				'timeout'     => 2,
				'httpversion' => '1.1',
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->start_geo_cooldown( $provider );
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 429 === $code || $code >= 500 ) {
			$this->start_geo_cooldown( $provider );
			return false;
		}

		if ( 200 !== $code ) {
			return false;
		}

		$loc = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $loc ) ) {
			return false;
		}

		return $this->normalise_geo_response( $provider, $loc );
	}

	/**
	 * A location already on record for this IP, taken from the logs rather
	 * than from a provider.
	 *
	 * Sits between the transient and the network call: an IP we have seen
	 * before never needs a second lookup, which keeps first-visit latency
	 * off returning visitors and keeps the provider quota for addresses
	 * that are genuinely new.
	 *
	 * Only `city` is on record — the log has no column for region or
	 * country — so the result carries a `partial` marker. Nothing reads
	 * those two fields today; the marker is there so that a consumer which
	 * one day does can tell a log-sourced answer from a provider one.
	 *
	 * @param string $ip Raw (unfiltered) IP address.
	 * @return array|false Normalised details, or false when not on record.
	 */
	private function get_location_from_log( $ip ) {
		if ( ! class_exists( 'Moove_UAT_Cron' ) ) {
			return false;
		}
		if ( ! apply_filters( 'uat_geo_lookup_from_log', true ) ) {
			return false;
		}

		// The log stores the filtered IP — md5() once GDPR anonymisation
		// is on — so match on that, not on the raw address.
		$stored_ip = (string) apply_filters( 'moove_activity_tracking_ip_filter', $ip );
		$city      = Moove_UAT_Cron::known_city_for_ip( $stored_ip );

		if ( '' === $city ) {
			return false;
		}

		return array(
			'ip'      => $ip,
			'city'    => $city,
			'region'  => '',
			'country' => '',
			'partial' => true,
		);
	}

	/**
	 * Location details by IP address.
	 *
	 * Resolved cheapest-first, so the network is the last resort rather
	 * than the default:
	 *  1. the cached transient, if we have one;
	 *  2. a location already stored against this IP in the logs;
	 *  3. the geolocation providers, synchronously.
	 *
	 * Step 3 costs at most 2s per provider and only ever runs for an IP
	 * that is new to both the transient cache and the logs — a returning
	 * visitor never waits on it. It can be turned off entirely with the
	 * `uat_geo_sync_lookup` filter, in which case a miss returns false and
	 * the `uat_resolve_geo_async` cron handler fills the gap in afterwards.
	 *
	 * Failures are cached too. Without that, a provider outage puts the
	 * plugin in a permanent loop — every page-view misses the cache,
	 * schedules a lookup, fails, caches nothing, repeat — which is itself
	 * enough to exhaust a free-tier quota and keep it exhausted.
	 *
	 * @param string    $ip   IP Address.
	 * @param bool|null $sync Force the provider call on or off. Null uses
	 *                        the `uat_geo_sync_lookup` default (on).
	 * @return object|false stdClass with ip/city/region/country, or false.
	 */
	public function get_location_details( $ip = false, $sync = null ) {
		if ( ! $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		// Loopback, LAN and link-local addresses are not resolvable by any
		// geolocation service — they always come back as "Reserved range".
		// This is the normal case on a local development install, where
		// REMOTE_ADDR is 127.0.0.1 rather than the developer's public IP,
		// so Location is legitimately N/A there. Bail before spending a
		// request (and a needless provider cooldown) to learn that.
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}

		$transient_key = 'uat_locdata_' . md5( $ip );
		$cached        = get_transient( $transient_key );
		if ( $cached ) {
			$decoded = json_decode( $cached );
			// Negative entry: a recent lookup for this IP failed. Report
			// it as a miss, but without going back out over the network.
			if ( ! is_object( $decoded ) || isset( $decoded->failed ) ) {
				return false;
			}
			return $decoded;
		}

		// Seen before? Then we already know where this address is and no
		// provider needs asking.
		$from_log = $this->get_location_from_log( $ip );
		if ( $from_log ) {
			set_transient( $transient_key, wp_json_encode( $from_log ), 30 * DAY_IN_SECONDS );
			return json_decode( wp_json_encode( $from_log ) );
		}

		if ( null === $sync ) {
			$sync = (bool) apply_filters( 'uat_geo_sync_lookup', true );
		}

		if ( ! $sync ) {
			return false;
		}

		// Tight per-provider timeout so a slow upstream cannot stall the
		// request (or the cron worker pool).
		$attempted = false;
		foreach ( $this->get_geo_providers() as $provider => $endpoint ) {
			if ( get_transient( 'uat_geo_cooldown_' . $provider ) ) {
				continue;
			}

			$attempted = true;
			$details   = $this->fetch_geo_from( $provider, $endpoint, $ip );
			if ( $details ) {
				set_transient( $transient_key, wp_json_encode( $details ), 30 * DAY_IN_SECONDS );
				return json_decode( wp_json_encode( $details ) );
			}
		}

		// Only record a failure against this IP if a provider actually
		// turned it down. When every provider is in cooldown the address
		// was never in question, and blacklisting it for an hour would
		// turn a 15-minute outage into an hour of N/A for every visitor
		// seen during it.
		if ( $attempted ) {
			// Short TTL: long enough to break the retry loop, short enough
			// that the location appears once the provider recovers.
			set_transient( $transient_key, wp_json_encode( array( 'failed' => true ) ), HOUR_IN_SECONDS );
		}

		return false;
	}
}
new Moove_Activity_Shortcodes();
