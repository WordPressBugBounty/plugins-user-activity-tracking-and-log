<?php
/**
 * Moove UAT background work.
 *
 * Moves long-running maintenance (legacy-log import, old-log cleanup,
 * orphan removal, geo lookups) off the request path and onto WP-Cron so
 * admin page loads don't time out on large sites.
 *
 * @package user-activity-tracking-and-log
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Moove_UAT_Cron' ) ) :

	/**
	 * Background-work coordinator.
	 */
	class Moove_UAT_Cron {

		const HOOK_DAILY_MAINTENANCE = 'uat_daily_maintenance';
		const HOOK_RESOLVE_GEO       = 'uat_resolve_geo_async';
		const HOOK_IMPORT_LEGACY     = 'uat_import_legacy_logs';

		/**
		 * Register cron hooks. Called from the main bootstrap.
		 */
		public static function register() {
			add_action( self::HOOK_DAILY_MAINTENANCE, array( __CLASS__, 'run_daily_maintenance' ) );
			add_action( self::HOOK_RESOLVE_GEO, array( __CLASS__, 'resolve_geo_async' ), 10, 2 );
			add_action( self::HOOK_IMPORT_LEGACY, array( __CLASS__, 'import_legacy_batch' ), 10, 1 );

			// Self-heal scheduling: if for some reason the daily event
			// disappeared (manual flush, migration), re-schedule it.
			if ( ! wp_next_scheduled( self::HOOK_DAILY_MAINTENANCE ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK_DAILY_MAINTENANCE );
			}
		}

		/**
		 * Clear every scheduled event the plugin owns. Called on
		 * deactivation.
		 */
		public static function unregister() {
			foreach ( array( self::HOOK_DAILY_MAINTENANCE, self::HOOK_RESOLVE_GEO, self::HOOK_IMPORT_LEGACY ) as $hook ) {
				$timestamp = wp_next_scheduled( $hook );
				while ( $timestamp ) {
					wp_unschedule_event( $timestamp, $hook );
					$timestamp = wp_next_scheduled( $hook );
				}
				wp_clear_scheduled_hook( $hook );
			}
		}

		/**
		 * Daily housekeeping.
		 *  - Drop orphan rows whose post no longer exists.
		 *  - Trim per-post-type and archive logs to the configured
		 *    retention window, in batches.
		 */
		public static function run_daily_maintenance() {
			if ( class_exists( 'Moove_Activity_Database_Model' ) ) {
				// Existing helper that already self-throttles to once/day.
				Moove_Activity_Database_Model::delete_abandoned_logs();
			}

			// Apply per-post-type retention. Batched so a multi-million
			// row table doesn't hold table locks for minutes.
			self::trim_retention_batched();
		}

		/**
		 * Iterate the saved per-post-type retention settings and delete
		 * any rows older than the configured days. Hard-capped at 50k
		 * rows per cron tick so a single daily run never holds the table
		 * for longer than it takes MySQL to chew through that batch.
		 */
		protected static function trim_retention_batched() {
			global $wpdb;
			$settings = get_option( 'moove_post_act' );
			if ( ! is_array( $settings ) || empty( $settings ) ) {
				return;
			}

			$batch_cap = (int) apply_filters( 'uat_retention_batch_cap', 50000 );
			$deleted   = 0;
			$table     = $wpdb->prefix . 'moove_activity_log';

			foreach ( $settings as $key => $value ) {
				if ( substr( $key, -10 ) !== '_transient' ) {
					continue;
				}
				$days = (int) $value;
				if ( $days <= 0 ) {
					continue;
				}
				$post_type = substr( $key, 0, -10 );
				$cutoff    = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

				// Delete in 1000-row batches until we drain or hit the cap.
				do {
					$rows = (int) $wpdb->query( $wpdb->prepare( // phpcs:ignore
						"DELETE FROM {$table} WHERE `post_type` = %s AND `visit_date` <= %s LIMIT 1000",
						$post_type,
						$cutoff
					) );
					$deleted += $rows;
					if ( $deleted >= $batch_cap ) {
						return;
					}
				} while ( $rows > 0 );
			}
		}

		/**
		 * Run a single chunk of the post-meta -> DB importer. Schedules
		 * itself again until there is nothing left to migrate.
		 *
		 * @param int $offset WP_Query offset to start from.
		 */
		public static function import_legacy_batch( $offset = 0 ) {
			if ( get_option( 'moove_importer_has_database' ) ) {
				return;
			}
			if ( ! class_exists( 'Moove_Activity_Database_Model' ) ) {
				return;
			}

			$batch_size = (int) apply_filters( 'uat_legacy_import_batch_size', 200 );
			$offset     = max( 0, (int) $offset );

			$post_types = get_post_types( array( 'public' => true ) );
			unset( $post_types['attachment'] );

			$query = new WP_Query( array(
				'post_type'      => array_values( $post_types ),
				'post_status'    => 'publish',
				'posts_per_page' => $batch_size,
				'offset'         => $offset,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore
					array(
						'key'     => 'ma_data',
						'value'   => null,
						'compare' => '!=',
					),
				),
			) );

			$ids = $query->posts;
			if ( empty( $ids ) ) {
				update_option( 'moove_importer_has_database', true );
				return;
			}

			$db = new Moove_Activity_Database_Model();
			$helper_exists = method_exists( 'Moove_Activity_Content', 'decode_ma_data' );

			foreach ( $ids as $post_id ) {
				$raw = get_post_meta( $post_id, 'ma_data', true );
				if ( '' === $raw || null === $raw ) {
					continue;
				}
				$ma_data = $helper_exists
					? Moove_Activity_Content::decode_ma_data( $raw )
					: maybe_unserialize( $raw ); // phpcs:ignore

				if ( empty( $ma_data['log'] ) || ! is_array( $ma_data['log'] ) ) {
					continue;
				}
				foreach ( $ma_data['log'] as $log ) {
					$ts             = isset( $log['time'] ) ? (int) $log['time'] : time();
					$data_to_insert = array(
						'post_id'      => $post_id,
						'user_id'      => isset( $log['uid'] ) ? (int) $log['uid'] : 0,
						'status'       => isset( $log['response_status'] ) ? (string) $log['response_status'] : '',
						'user_ip'      => isset( $log['show_ip'] ) ? (string) $log['show_ip'] : '',
						'city'         => isset( $log['city'] ) ? (string) $log['city'] : '',
						'display_name' => isset( $log['display_name'] ) ? (string) $log['display_name'] : '',
						'post_type'    => get_post_type( $post_id ),
						'referer'      => isset( $log['referer'] ) ? (string) $log['referer'] : '',
						'month_year'   => gmdate( 'm', $ts ) . gmdate( 'Y', $ts ),
						'visit_date'   => gmdate( 'Y-m-d H:i:s', $ts ),
						'campaign_id'  => isset( $ma_data['campaign_id'] ) ? (string) $ma_data['campaign_id'] : '',
					);
					$filtered = apply_filters( 'moove_uat_filter_data', $data_to_insert );
					if ( $filtered ) {
						$db->insert( $filtered );
					}
				}
			}

			// More to process — schedule the next batch.
			if ( count( $ids ) === $batch_size ) {
				wp_schedule_single_event( time() + 30, self::HOOK_IMPORT_LEGACY, array( $offset + $batch_size ) );
			} else {
				update_option( 'moove_importer_has_database', true );
			}
		}

		/**
		 * Resolve a visitor's geolocation in the background. Called from
		 * wp_schedule_single_event() so the front-end tracking AJAX
		 * never blocks on the external HTTP call.
		 *
		 * @param string $ip    IP to look up.
		 * @param int    $row   Optional row ID to backfill the city on.
		 */
		public static function resolve_geo_async( $ip, $row = 0 ) {
			if ( ! class_exists( 'Moove_Activity_Shortcodes' ) ) {
				return;
			}
			$ip = is_string( $ip ) ? trim( $ip ) : '';
			if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return;
			}

			$sc      = new Moove_Activity_Shortcodes();
			$details = $sc->get_location_details( $ip, true );

			if ( $row > 0 && $details && isset( $details->city ) && $details->city ) {
				global $wpdb;
				$wpdb->update( // phpcs:ignore
					$wpdb->prefix . 'moove_activity_log',
					array( 'city' => (string) $details->city ),
					array( 'id' => (int) $row ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}
	}

endif;
