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

			// Fill in locations we already know from another visit by the
			// same IP. Purely local — no external lookups.
			self::repair_missing_locations();
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
			$city    = ( $details && isset( $details->city ) ) ? (string) $details->city : '';

			if ( '' === $city ) {
				return;
			}

			if ( $row > 0 ) {
				global $wpdb;
				$wpdb->update( // phpcs:ignore
					$wpdb->prefix . 'moove_activity_log',
					array( 'city' => $city ),
					array( 'id' => (int) $row ),
					array( '%s' ),
					array( '%d' )
				);
			}

			// The row that triggered this lookup was written before the
			// answer existed, so warming the transient alone leaves it at
			// N/A forever — the visible symptom being a log where the same
			// IP alternates between a city and N/A. Push the result onto
			// every location-less row for this IP instead of just $row:
			// the callers dedupe the cron event per IP (row 0), and the
			// save_post and user-session trackers never pass a row at all.
			//
			// The log stores the *filtered* IP — md5() once the GDPR
			// anonymisation option is on — so match on that, not on the raw
			// address the lookup used.
			$stored_ip = (string) apply_filters( 'moove_activity_tracking_ip_filter', $ip );
			self::apply_city_to_ip( $stored_ip, $city );
		}

		/**
		 * Tables carrying an IP + city pair that the backfill may repair.
		 *
		 * The core log is the only one this plugin owns; the add-on appends
		 * its event-tracking and user-session logs. Names are validated
		 * before they reach a query because the list is filterable.
		 *
		 * @return array Fully-prefixed, validated table names.
		 */
		protected static function geo_tables() {
			global $wpdb;

			static $resolved = null;
			if ( null !== $resolved ) {
				return $resolved;
			}

			$tables = (array) apply_filters(
				'uat_geo_backfill_tables',
				array( $wpdb->prefix . 'moove_activity_log' )
			);

			$resolved = array();
			foreach ( $tables as $table ) {
				if ( ! is_string( $table ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
					continue;
				}
				if ( isset( $resolved[ $table ] ) ) {
					continue;
				}
				// A registered table is not necessarily a created one: the
				// add-on advertises its event and session logs whether or
				// not those modules have ever run.
				$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore
				if ( $found ) {
					$resolved[ $table ] = $table;
				}
			}

			$resolved = array_values( $resolved );
			return $resolved;
		}

		/**
		 * The values a `city` column holds when the location is unknown.
		 *
		 * Not one value but four: the core tracker writes the literal
		 * 'N/A' string, the event and session trackers write '', legacy
		 * rows can be NULL, and a translated install writes the localised
		 * 'N/A'. All of them render as N/A and all of them are repairable.
		 *
		 * @return array
		 */
		protected static function missing_city_values() {
			$values = array( '', 'N/A', __( 'N/A', 'user-activity-tracking-and-log' ) );
			$values = (array) apply_filters( 'uat_geo_missing_city_values', $values );
			$values = array_values( array_unique( array_filter( $values, 'is_string' ) ) );

			// A filter that empties the list would build an `IN ()` and
			// take the query down with it.
			return $values ? $values : array( '' );
		}

		/**
		 * SQL fragment matching rows with no usable location, plus the
		 * values to bind to it.
		 *
		 * @param bool $negate Match rows that DO have a location instead.
		 * @return array { @type string $sql, @type array $values }
		 */
		protected static function missing_city_clause( $negate = false ) {
			$values       = self::missing_city_values();
			$placeholders = implode( ',', array_fill( 0, count( $values ), '%s' ) );

			$sql = $negate
				? "( `city` IS NOT NULL AND `city` NOT IN ( {$placeholders} ) )"
				: "( `city` IS NULL OR `city` IN ( {$placeholders} ) )";

			return array(
				'sql'    => $sql,
				'values' => $values,
			);
		}

		/**
		 * The most recent known city for a stored IP, looked up across
		 * every registered log table.
		 *
		 * Cross-table on purpose: an IP resolved by a page view can repair
		 * an event-tracking row, which is what the combined log shows side
		 * by side.
		 *
		 * @param string $stored_ip IP as written to the log (already filtered).
		 * @return string City, or '' when nothing is on record.
		 */
		public static function known_city_for_ip( $stored_ip ) {
			global $wpdb;

			$stored_ip = is_string( $stored_ip ) ? trim( $stored_ip ) : '';
			if ( '' === $stored_ip ) {
				return '';
			}

			$known = self::missing_city_clause( true );

			foreach ( self::geo_tables() as $table ) {
				$args = array_merge( array( $stored_ip ), $known['values'] );
				$city = $wpdb->get_var( // phpcs:ignore
					$wpdb->prepare(
						"SELECT `city` FROM `{$table}` WHERE `user_ip` = %s AND {$known['sql']} ORDER BY `visit_date` DESC LIMIT 1", // phpcs:ignore
						...$args
					)
				);
				if ( $city ) {
					return (string) $city;
				}
			}

			return '';
		}

		/**
		 * Write a city onto every location-less row for one IP.
		 *
		 * @param string $stored_ip IP as written to the log (already filtered).
		 * @param string $city      City to write.
		 * @param bool   $flush     Invalidate the log cache after writing.
		 *                          Pass false when batching and flush once.
		 * @return int Rows updated.
		 */
		public static function apply_city_to_ip( $stored_ip, $city, $flush = true ) {
			global $wpdb;

			$stored_ip = is_string( $stored_ip ) ? trim( $stored_ip ) : '';
			$city      = is_string( $city ) ? trim( $city ) : '';
			if ( '' === $stored_ip || '' === $city ) {
				return 0;
			}

			$missing = self::missing_city_clause();
			$row_cap = (int) apply_filters( 'uat_geo_backfill_row_cap', 5000 );
			$updated = 0;

			foreach ( self::geo_tables() as $table ) {
				$args     = array_merge( array( $city, $stored_ip ), $missing['values'], array( $row_cap ) );
				$updated += (int) $wpdb->query( // phpcs:ignore
					$wpdb->prepare(
						"UPDATE `{$table}` SET `city` = %s WHERE `user_ip` = %s AND {$missing['sql']} LIMIT %d", // phpcs:ignore
						...$args
					)
				);
			}

			if ( $flush && $updated > 0 && class_exists( 'Moove_UAT_Cache' ) ) {
				Moove_UAT_Cache::bump();
			}

			return $updated;
		}

		/**
		 * Replace N/A locations with a city already on record for the same
		 * IP address.
		 *
		 * Geolocation is resolved off the request path, so the row that
		 * triggers a lookup is always written before the answer arrives.
		 * Rows written on a cold cache therefore keep N/A while later
		 * visits from the identical IP show the city. This walks the
		 * location-less rows and copies across what another row already
		 * knows — no external API calls, so it is safe to run on demand and
		 * costs nothing in provider quota.
		 *
		 * Bounded per run: it processes a capped number of distinct IPs,
		 * and the daily job picks up where the traffic left off. Rows whose
		 * IP has no location anywhere are skipped — only a provider lookup
		 * can resolve those.
		 *
		 * @param int $max_ips Distinct IPs to process. 0 = filtered default.
		 * @return int Rows repaired.
		 */
		public static function repair_missing_locations( $max_ips = 0 ) {
			global $wpdb;

			$max_ips = $max_ips > 0
				? (int) $max_ips
				: (int) apply_filters( 'uat_geo_backfill_ip_batch', 200 );

			$missing  = self::missing_city_clause();
			$repaired = 0;

			// Collect the work queue across every table first. apply_city_to_ip()
			// already writes to all of them, so an IP that is location-less in
			// two tables must still only be handled once.
			$candidates = array();
			foreach ( self::geo_tables() as $table ) {
				$args = array_merge( $missing['values'], array( $max_ips ) );
				$ips  = $wpdb->get_col( // phpcs:ignore
					$wpdb->prepare(
						"SELECT DISTINCT `user_ip` FROM `{$table}` WHERE `user_ip` IS NOT NULL AND `user_ip` <> '' AND {$missing['sql']} LIMIT %d", // phpcs:ignore
						...$args
					)
				);

				if ( ! empty( $ips ) ) {
					foreach ( $ips as $ip ) {
						$candidates[ $ip ] = true;
					}
				}
			}

			foreach ( array_keys( $candidates ) as $ip ) {
				$city = self::known_city_for_ip( $ip );
				if ( '' === $city ) {
					// Never resolved anywhere — only a provider lookup can
					// fill this one in.
					continue;
				}
				$repaired += self::apply_city_to_ip( $ip, $city, false );
			}

			// One namespace bump for the whole run rather than one per IP —
			// each bump is an autoloaded option write.
			if ( $repaired > 0 && class_exists( 'Moove_UAT_Cache' ) ) {
				Moove_UAT_Cache::bump();
			}

			return $repaired;
		}
	}

endif;
