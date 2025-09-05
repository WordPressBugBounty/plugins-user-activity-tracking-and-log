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
		$remote = false;
		
		// Get real visitor IP behind CloudFlare network.
		if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) :
			$remote = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) : false;
			$client = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) : false;
		else :
			$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : false;
			$client = isset( $_SERVER['HTTP_CLIENT_IP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) ) : false;
		endif;

		$forward = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) : false;

		if ( filter_var( $client, FILTER_VALIDATE_IP ) ) {
			$ip = $client;
		} elseif ( filter_var( $forward, FILTER_VALIDATE_IP ) && apply_filters( 'uat_allow_xfwdip', false ) ) {
			$ip = $forward;
		} else {
			$ip = $remote;
		}

		$ip = ( strpos( $ip, ',') > 0 ) ? trim( explode( ',', $ip )[0] ) : $ip;

		return $filter ? apply_filters( 'moove_activity_tracking_ip_filter', $ip ) : $ip;
	}

	/**
	 * Location details by IP address.
	 *
	 * @param string $ip IP Address.
	 */
	public function get_location_details( $ip = false ) {
		$response = false;

		if ( $ip ) :
			$transient_key = 'uat_locdata_' . md5( $ip );
			$details       = get_transient( $transient_key );
			if ( ! $details ) :
				try {
					$response = wp_remote_get( 
						'https://ipapi.co/'.$ip.'/json',
						array(
							'timeout'     => 30,
							'httpversion' => '1.1',
						)
					);

					try {
				    $data = json_decode( $response['body'], true );
				  } catch ( Exception $ex ) {
				    $data = null;
				  }

				  $loc = maybe_unserialize( $data );

					$details  = array();
					if ( $loc && is_array( $loc ) && isset( $loc['ip'] ) ) :
						$details = array(
							'ip'     => isset( $loc['ip'] ) ? strval( $loc['ip'] ) : $ip,
							'city'   => isset( $loc['city'] ) ? strval( $loc['city'] ) : '',
							'region' => isset( $loc['region'] ) ? strval( $loc['region'] ) : ''
						);
					endif;

					if ( $details && is_array( $details ) && ! empty( $details ) ) :
						$_details = wp_json_encode( $details );
						set_transient( $transient_key, $_details, 30 * DAY_IN_SECONDS );
						$details = json_decode( json_encode( $details ) );
					else :
						$details = false;
					endif;
				} catch ( Exception $e ) {
					$details = false;
				}
			else :
				$details = json_decode( $details );
			endif;
		endif;
		return $details;
	}
}
new Moove_Activity_Shortcodes();
