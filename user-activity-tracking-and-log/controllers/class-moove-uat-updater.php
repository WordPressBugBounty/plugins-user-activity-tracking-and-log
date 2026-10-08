<?php
/**
 * Moove_UAT_Updater File Doc Comment
 *
 * @category    Moove_UAT_Updater
 * @package   user-activity-tracking-and-log
 * @author    Moove Agency
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'Moove_UAT_Updater' ) ) {
	/**
	 * Moove_UAT_Updater Class Doc Comment
	 *
	 * @category Class
	 * @package  Moove_UAT_Updater
	 * @author   Moove Agency
	 */
	class Moove_UAT_Updater {
		/**
		 * Update data
		 *
		 * @var array
		 */
		public $update_data = array();

		/**
		 * Construct
		 */
		public function __construct() {
			if ( function_exists( 'uat_cookie_compliance_addon_load_libs' ) ) :
				// Registered on every request, including cron and auto-updates: WordPress rebuilds the
				// update list from wordpress.org there too, and the add-on has to be part of it.
				add_filter( 'site_transient_update_plugins', array( &$this, 'set_update_data' ) );
				add_filter( 'upgrader_package_options', array( &$this, 'refresh_package' ) );
				add_filter( 'upgrader_source_selection', array( &$this, 'upgrader_source_selection' ), 10, 4 );
				add_action( 'wp_update_plugins', array( &$this, 'uat_check_for_updates' ) );

				add_action( 'uat_plugin_updater_notice', array( &$this, 'uat_plugin_updater_notice' ) );
				global $pagenow;
				$allowed_pages = array( 'update-core.php', 'plugins.php' );
				$plugin_slug   = false;
				$lm            = new Moove_UAT_License_Manager();
				$plugin_slug   = $lm->get_add_on_plugin_slug();
				// Bypass the throttle only when the user clicks "Check again" on Dashboard > Updates.
				if ( 'update-core.php' === $pagenow && isset( $_GET['force-check'] ) ) : // phpcs:ignore
					self::uat_check_for_updates( true );
				elseif ( in_array( $pagenow, $allowed_pages, true ) || ( 'admin.php' === $pagenow && isset( $_GET['page'] ) && 'moove-activity' === sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) )  : // phpcs:ignore
					self::uat_check_for_updates();
				endif;
				add_filter( 'plugins_api', array( &$this, 'plugins_api' ), 10, 3 );
				if ( $plugin_slug ) :
					add_action( "in_plugin_update_message-{$plugin_slug}", array( &$this, 'uat_update_message_content' ), 10, 2 );
				endif;
			endif;
		}

		/**
		 * Update message content
		 *
		 * @param array $plugin_data Plugin data.
		 * @param array $response Response.
		 */
		public function uat_update_message_content( $plugin_data, $response ) {
			if ( isset( $plugin_data['package'] ) && ! $plugin_data['package'] ) :
				$uat_default_content = new Moove_Activity_Content();
				$option_key          = $uat_default_content->moove_uat_get_key_name();
				$uat_key             = $uat_default_content->uat_get_activation_key( $option_key );
				$license_key         = isset( $uat_key['key'] ) ? sanitize_text_field( $uat_key['key'] ) : false;
				$renew_link          = MOOVE_SHOP_URL . '?renew=' . $license_key;
				$license_manager     = admin_url( 'admin.php' ) . '?page=moove-activity-log&amp;tab=licence';
				$purchase_link       = 'https://www.mooveagency.com/wordpress-plugins/user-activity-tracking-and-log/';
				if ( $license_key && isset( $uat_key['activation'] ) ) :
					// Expired.
					echo ' Update is not available until you <a href="' . esc_url( $renew_link ) . '" target="_blank">renew your licence</a>. You can also update your licence key in the <a href="' . esc_url( $license_manager ) . '" target="_blank">Licence Manager</a>.';
				elseif ( $license_key && isset( $uat_key['deactivation'] ) ) :
					// Deactivated.
					echo ' Update is not available until you <a href="' . esc_url( $purchase_link ) . '" target="_blank">purchase a licence</a>. You can also update your licence key in the <a href="' . esc_url( $license_manager ) . '" target="_blank">Licence Manager</a>.';
				elseif ( ! $license_key ) :
					// No license key installed.
					echo ' Update is not available until you <a href="' . esc_url( $purchase_link ) . '" target="_blank">purchase a licence</a>. You can also update your licence key in the <a href="' . esc_url( $license_manager ) . '" target="_blank">Licence Manager</a>.';
				endif;
			endif;
			return array();
		}

		/**
		 * Admin notice
		 */
		public function uat_plugin_updater_notice() {
			update_option( 'uat_last_checked', strtotime( 'yesterday' ) );
			delete_site_transient( 'update_plugins' );
		}

		/**
		 * Check for updates
		 */
		public function uat_check_for_updates( $transient_delete = false ) {
			$this->update_data = get_option( 'uat_update_data' );
			$last_checked      = get_option( 'uat_last_checked' );
			if ( $transient_delete ) :
				$last_checked = strtotime( 'yesterday' );
			endif;
			$now               = strtotime( 'now' );
			$check_interval    = 12 * HOUR_IN_SECONDS;

			// The stored data is merged into the update list by set_update_data() on every read.
			if ( ( $now - $last_checked ) > $check_interval ) :
				$this->update_data = $this->get_addon_updates();
				update_option( 'uat_update_data', $this->update_data );
				update_option( 'uat_last_checked', $now );
			endif;
		}


		/**
		 * Fetch the latest GitHub tags and build the plugin data array
		 */
		public function get_addon_updates() {
			global $wp_version;
			$plugin_data         = array();
			$uat_default_content = new Moove_Activity_Content();
			$option_key          = $uat_default_content->moove_uat_get_key_name();
			$uat_key             = $uat_default_content->uat_get_activation_key( $option_key );
			$license_key         = isset( $uat_key['key'] ) ? sanitize_text_field( $uat_key['key'] ) : false;
			if ( $license_key ) :
				if ( ! function_exists( 'get_plugins' ) ) :
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				endif;
				$plugins = get_plugins();
				foreach ( $plugins as $slug => $info ) :
					if ( isset( $info['TextDomain'] ) && 'user-activity-tracking-and-log-addon' === $info['TextDomain'] ) :
						$license_manager  = new Moove_UAT_License_Manager();
						$is_valid_license = $license_manager->get_premium_add_on( $license_key, 'update' );

						$temp = array(
							'plugin'      => $slug,
							'slug'        => trim( dirname( $slug ), '/' ),
							'name'        => $info['Name'],
							'description' => $info['Description'],
							'new_version' => false,
							'tested'      => '',
							'package'     => false,
							'icons'       => array(
								'1x' => 'https://shop.mooveagency.com/wp-content/uploads/plugin-icons/uat/icon-128x128.jpg'
							)
						);

						if ( $is_valid_license && isset( $is_valid_license['valid'] ) ) :

							$plugin_token   = isset( $is_valid_license['data'] ) && isset( $is_valid_license['data']['download_token'] ) && $is_valid_license['data']['download_token'] ? $is_valid_license['data']['download_token'] : false;
							$plugin_version = isset( $is_valid_license['data'] ) && isset( $is_valid_license['data']['version'] ) && $is_valid_license['data']['version'] ? $is_valid_license['data']['version'] : 0;

							$temp['new_version'] = $plugin_version;
							$temp['package']     = ! isset( $uat_key['deactivation'] ) ? $plugin_token : '';
							if ( isset( $is_valid_license['data'] ) && isset( $is_valid_license['data']['tested'] ) && $is_valid_license['data']['tested'] ) :
								$temp['tested'] = $is_valid_license['data']['tested'];
							endif;
						endif;
						$plugin_data[ $slug ] = $temp;
					endif;
				endforeach;
			endif;
			return $plugin_data;
		}


		/**
		 * Get plugin info for the "View Details" popup
		 *
		 * @param bool   $default Default.
		 * @param string $action Action.
		 * @param array  $args Args.
		 */
		public function plugins_api( $default = false, $action = '', $args = array() ) {
			if ( 'plugin_information' === $action ) {
				$plugin_data       = array();
				$this->update_data = get_option( 'uat_update_data' );
				if ( is_array( $this->update_data ) && ! empty( $this->update_data ) ) :
					foreach ( $this->update_data as $slug => $data ) :
						if ( $data['slug'] === $args->slug ) :
							if ( class_exists( 'Moove_Activity_Controller' ) ) :
								$uat_controller = new Moove_Activity_Controller();
								$plugin_details = $uat_controller->get_plugin_details( 'user-activity-tracking-and-log' );
								unset( $plugin_details->sections['screenshot'] );
								unset( $plugin_details->sections['changelog'] );
								unset( $plugin_details->sections['installation'] );
								$plugin_details->name         = $data['name'];
								$plugin_details->slug         = $data['plugin'];
								$plugin_details->version      = $data['new_version'];
								$plugin_details->last_updated = '';
								$plugin_details->banners      = array(
									'high' => 'https://ps.w.org/user-activity-tracking-and-log/assets/banner-772x250.jpg'
								);
								return (object) $plugin_details;
							endif;
						endif;
					endforeach;
				endif;
			}
			return $default;
		}

		/**
		 * Merge the add-on into the update list whenever WordPress reads it.
		 *
		 * WordPress rebuilds the list from wordpress.org (cron, admin pages) without the add-on,
		 * so it is added back on read from the stored update data.
		 *
		 * @param object $transient Update plugins transient.
		 */
		public function set_update_data( $transient ) {
			$update_data = get_option( 'uat_update_data' );
			if ( ! is_object( $transient ) || ! is_array( $update_data ) ) {
				return $transient;
			}
			foreach ( $update_data as $plugin => $info ) {
				if ( ! file_exists( WP_PLUGIN_DIR . '/' . $plugin ) ) {
					continue;
				}
				if ( ! function_exists( 'get_plugin_data' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false );

				if ( ! empty( $info['new_version'] ) && version_compare( $plugin_data['Version'], $info['new_version'], '<' ) ) {
					$transient->response[ $plugin ] = (object) $info;
				} else {
					unset( $transient->response[ $plugin ] );
				}
			}
			return $transient;
		}

		/**
		 * Swap in a fresh download link right before the add-on is updated.
		 *
		 * The link in the stored update data is a short-lived token, so it may have expired.
		 *
		 * @param array $options Upgrader package options.
		 */
		public function refresh_package( $options ) {
			$plugin      = isset( $options['hook_extra']['plugin'] ) ? $options['hook_extra']['plugin'] : false;
			$update_data = get_option( 'uat_update_data' );
			if ( ! $plugin || ! is_array( $update_data ) || ! isset( $update_data[ $plugin ] ) ) {
				return $options;
			}
			$uat_default_content = new Moove_Activity_Content();
			$uat_key             = $uat_default_content->uat_get_activation_key( $uat_default_content->moove_uat_get_key_name() );
			if ( empty( $uat_key['key'] ) || isset( $uat_key['deactivation'] ) ) {
				return $options;
			}
			$license_manager = new Moove_UAT_License_Manager();
			$response        = $license_manager->validate_license( sanitize_text_field( $uat_key['key'] ), 'uat', 'update' );
			if ( ! empty( $response['valid'] ) && ! empty( $response['data']['download_token'] ) ) {
				$options['package'] = $response['data']['download_token'];
			}
			return $options;
		}

		/**
		 * Rename the plugin folder
		 *
		 * @param string $source Source.
		 * @param string $remote_source Remote source.
		 * @param string $upgrader Upgrader.
		 * @param string $hook_extra Extra hook.
		 */
		public function upgrader_source_selection( $source, $remote_source, $upgrader, $hook_extra = null ) {
			global $wp_filesystem;
			$plugin      = isset( $hook_extra['plugin'] ) ? $hook_extra['plugin'] : false;
			$update_data = get_option( 'uat_update_data' );
			if ( $plugin && is_array( $update_data ) && isset( $update_data[ $plugin ] ) ) :
				$lm          = new Moove_UAT_License_Manager();
				$plugin_slug = $lm->get_add_on_plugin_slug();
				$temp_slug   = basename( trailingslashit( $source ) );
				$plugin_slug = explode( '/', $plugin_slug );
				$plugin_slug = isset( $plugin_slug[0] ) && $plugin_slug[0] ? $plugin_slug[0] : 'user-activity-tracking-and-log-addon';

				if ( $temp_slug !== $plugin_slug ) :
					$new_source = trailingslashit( $remote_source );
					$new_source = str_replace( $temp_slug, $plugin_slug, $new_source );
					$wp_filesystem->move( $source, $new_source );
					return trailingslashit( $new_source );
				endif;
			endif;
			return $source;
		}
	}
}
new Moove_UAT_Updater();
