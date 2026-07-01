<?php
/**
 * Activity_DT_Manager File Doc Comment
 *
 * @category  Activity_DT_Manager
 * @package   user-activity-tracking-and-log
 * @author    Moove Agency
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Activity_DT_Manager Class Doc Comment
 *
 * @category Class
 * @package  Activity_DT_Manager
 * @author   Moove Agency
 */
class Activity_DT_Manager {
	/**
	 * Create the data output array for the DataTables rows.
	 *
	 *  @param  array $columns Column information array.
	 *  @param  array $data    Data from the SQL get.
	 *  @return array          Formatted data in a row based format.
	 */
	public static function data_output( $columns, $data ) {
		$out = array();

		for ( $i = 0, $ien = count( $data ); $i < $ien; $i++ ) {
			$row = array();

			for ( $j = 0, $jen = count( $columns ); $j < $jen; $j++ ) {
				$column = $columns[ $j ];

				// Is there a formatter?
				if ( isset( $column['formatter'] ) ) {
					if ( empty( $column['db'] ) ) {
						$row[ $column['dt'] ] = $column['formatter']( $data[ $i ] );
					} else {
						$row[ $column['dt'] ] = $column['formatter']( $data[ $i ][ $column['db'] ], $data[ $i ] );
					}
				} else {
					if ( ! empty( $column['db'] ) ) {
						$row[ $column['dt'] ] = $data[ $i ][ $columns[ $j ]['db'] ];
					} else {
						$row[ $column['dt'] ] = '';
					}
				}
			}

			$out[] = $row;
		}

		return $out;
	}

	/**
	 * Create the data output array for the DataTables rows.
	 *
	 *  @param  array $columns Column information array.
	 *  @param  array $data    Data from the SQL get.
	 *  @return array          Formatted data in a row based format.
	 */
	public static function export_data_output( $columns, $data ) {
		$out = array();
		for ( $i = 0, $ien = count( $data ); $i < $ien; $i++ ) {
			$row = array();

			for ( $j = 0, $jen = count( $columns ); $j < $jen; $j++ ) {
				$column = $columns[ $j ];

				// Is there a formatter?
				if ( isset( $column['formatter'] ) ) :
					if ( empty( $column['db'] ) ) {
						$row[ $column['dt'] ] = $column['formatter']( $data[ $i ] );
					} else {
						$row[ $column['dt'] ] = $column['formatter']( $data[ $i ][ $column['db'] ], $data[ $i ] );
					}
				elseif ( isset( $column['hook'] ) ) :
					$row[ $column['dt'] ] = apply_filters( 'uat_csv_row_' . $column['hook'], '', $data[ $i ] );
				else :
					if ( ! empty( $column['db'] ) ) {
						$row[ $column['dt'] ] = $data[ $i ][ $columns[ $j ]['db'] ];
					} else {
						$row[ $column['dt'] ] = '';
					}
				endif;

				// CSV-injection defence: any cell whose first character
				// is interpreted as a formula by Excel / Sheets /
				// LibreOffice gets a leading apostrophe so the opening
				// software treats it as text. Tab and CR are included
				// because they are alternate formula prefixes.
				$cell = $row[ $column['dt'] ];
				if ( is_string( $cell ) && '' !== $cell && in_array( $cell[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
					$row[ $column['dt'] ] = "'" . $cell;
				}
			}

			$out[] = $row;
		}

		return $out;
	}

	/**
	 * Paging
	 *
	 * Construct the LIMIT clause for server-side processing SQL query.
	 *
	 *  @param  array $request Data sent to server by DataTables.
	 *  @param  array $columns Column information array.
	 *  @return string SQL limit clause.
	 */
	public static function limit( $request, $columns ) {
		$limit = '';

		if ( isset( $request['start'] ) && 1 !== $request['length'] ) {
			$limit = 'LIMIT ' . intval( $request['start'] ) . ', ' . intval( $request['length'] );
		}

		return $limit;
	}


	/**
	 * Ordering
	 *
	 * Construct the ORDER BY clause for server-side processing SQL query.
	 *
	 *  @param  array $request Data sent to server by DataTables.
	 *  @param  array $columns Column information array.
	 *  @return string SQL order by clause.
	 */
	public static function order( $request, $columns ) {
		$order = '';

		if ( isset( $request['order'] ) && count( $request['order'] ) ) {
			$order_by   = array();
			$dt_columns = self::pluck( $columns, 'dt' );

			for ( $i = 0, $ien = count( $request['order'] ); $i < $ien; $i++ ) {
				// Convert the column index into the column data property.
				$column_id_x    = intval( $request['order'][ $i ]['column'] );
				$request_column = $request['columns'][ $column_id_x ];

				$column_id_x = array_search( $request_column['data'], $dt_columns ); // phpcs:ignore
				$column      = $columns[ $column_id_x ];

				if ( 'true' === $request_column['orderable'] ) {
					$dir = 'asc' === $request['order'][ $i ]['dir'] ?
					'ASC' :
					'DESC';

					$order_by[] = '`' . $column['db'] . '` ' . $dir;
				}
			}

			if ( count( $order_by ) ) {
				$order = 'ORDER BY ' . implode( ', ', $order_by );
			}
		}

		return $order;
	}


	/**
	 * Searching / Filtering
	 *
	 * Construct the WHERE clause for server-side processing SQL query.
	 *
	 * NOTE this does not match the built-in DataTables filtering which does it
	 * word by word on any field. It's possible to do here performance on large
	 * databases would be very poor.
	 *
	 *  @param  array $request Data sent to server by DataTables.
	 *  @param  array $columns Column information array.
	 *  @param  array $bindings Array of values for PDO bindings, used in the
	 *    sql_exec() function.
	 *  @return string SQL where clause.
	 */
	public static function filter( $request, $columns, &$bindings ) {
		global $wpdb;
		$global_search = array();
		$column_search = array();
		$dt_columns    = self::pluck( $columns, 'dt' );
		$has_post_type_f = false;

		if ( isset( $request['search'] ) && '' !== $request['search']['value'] ) {
			$str = $request['search']['value'];

			for ( $i = 0, $ien = count( $request['columns'] ); $i < $ien; $i++ ) {
				$request_column = $request['columns'][ $i ];
				$column_id_x    = array_search( $request_column['data'], $dt_columns ); // phpcs:ignore
				$column         = $columns[ $column_id_x ];

				if ( 'true' === $request_column['searchable'] ) {
					if ( ! empty( $column['db'] ) && 'visit_date' !== $column['db'] ) {
						$binding         = sanitize_text_field( wp_unslash( $str ) );
						$safe_col        = preg_replace( '/[^a-zA-Z0-9_]/', '', $column['db'] );
						if ( '' === $safe_col ) {
							continue;
						}
						$global_search[] = $wpdb->prepare( "`{$safe_col}` LIKE %s", '%' . $wpdb->esc_like( $binding ) . '%' ); // phpcs:ignore -- column whitelisted above.
					}
				}
			}
		}

		// Individual column filtering.
		if ( isset( $request['columns'] ) ) {
			for ( $i = 0, $ien = count( $request['columns'] ); $i < $ien; $i++ ) {
				$request_column = $request['columns'][ $i ];
				$column_id_x    = array_search( $request_column['data'], $dt_columns ); // phpcs:ignore
				$column         = $columns[ $column_id_x ];

				$str = $request_column['search']['value'];

				if ( 'true' === $request_column['searchable'] &&
					'' !== $str ) {
					if ( ! empty( $column['db'] ) && 'visit_date' !== $column['db'] ) {
						$binding         = sanitize_text_field( wp_unslash( $str ) );
						$safe_col        = preg_replace( '/[^a-zA-Z0-9_]/', '', $column['db'] );
						if ( '' === $safe_col ) {
							continue;
						}
						$column_search[] = $wpdb->prepare( "`{$safe_col}` LIKE %s", '%' . $wpdb->esc_like( $binding ) . '%' ); // phpcs:ignore -- column whitelisted above.
					}
				}
			}
		}

		if ( isset( $request['top_filters'] ) && ! empty( $request['top_filters'] ) ) :
			foreach ( $request['top_filters'] as $filter_name => $filter_value ) :
				$filter_value = sanitize_text_field( wp_unslash( $filter_value ) );
				switch ( $filter_name ) :
					case 'dt-date-filter':
						$column_search[] = $wpdb->prepare( 'CONCAT(YEAR(`visit_date`), DATE_FORMAT( `visit_date` ,"%%m" ) ) = %s', $filter_value );
						break;
					case 'dt-post_type-filter':
						$column_search[] = $wpdb->prepare( '`post_type` = %s', $filter_value );
						$has_post_type_f = true;
						break;
					case 'dt-user-filter':
						$column_search[] = $wpdb->prepare( '`user_id` = %d', $filter_value );
						break;
					case 'dt-user_role-filter':
						$user_ids = array_map( 'intval', explode( ',', $filter_value ) );
						$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
						$column_search[] = $wpdb->prepare( "`user_id` IN ($placeholders)", ...$user_ids );
						break;
					case 'dt-cpt_post_id':
						$column_search[] = $wpdb->prepare( '`post_id` = %d', $filter_value );
						break;
					case 'dt-archive_filter':
						$column_search[] = '`post_id` < 0';
						break;
					case 'dt-ip-address':
						$column_search[] = $wpdb->prepare( '`user_ip` = %s', $filter_value );
						break;
					default:
						// code...
						break;
				endswitch;
			endforeach;
		endif;

		if ( ! $has_post_type_f ) :
			$post_types = uat_get_enabled_post_types();
			$post_types = $post_types ? $post_types : array();
			$post_types = apply_filters('uat_before_db_post_types', $post_types);

			$column_search[] = '`post_type` IN ("' . implode( '","', $post_types ) . '")';
		endif;

		// Combine the filters into a single string.
		$where = '';

		if ( count( $global_search ) ) {
			$where = '(' . implode( ' OR ', $global_search ) . ')';
		}

		if ( count( $column_search ) ) {
			$where = '' === $where ?
			implode( ' AND ', $column_search ) :
			$where . ' AND ' . implode( ' AND ', $column_search );
		}

		if ( '' !== $where ) :
			$where = 'WHERE ' . $where;
		endif;

		return $where;
	}

	/**
	 * Perform the SQL queries needed for an server-side processing requested,
	 * utilising the helper functions of this class, limit(), order() and
	 * filter() among others. The returned array is ready to be encoded as JSON
	 * in response to an SSP request, or can be modified if needed before
	 * sending back to the client.
	 *
	 *  @param  array  $request Data sent to server by DataTables.
	 *  @param  array  $columns Column information array.
	 *  @param string $type Type.
	 *  @return array     Server-side processing response array.
	 */
	public static function export( $request, $columns, $type = 'all' ) {
		global $wpdb;
		$bindings    = array();
		$primary_key = 'id';
		$table       = "{$wpdb->prefix}moove_activity_log";
		$order       = 'ORDER BY `visit_date`';
		$where       = 'all' === $type ? '' : self::filter( $request, $columns, $bindings );

		if ( 'cpt' === $type && isset( $request['log_id'] ) && intval( $request['log_id'] ) ) :
			$where = 'WHERE `post_id` = ' . intval( $request['log_id'] );
			try {
				$uat_controller = new Moove_Activity_Controller();
				$uat_controller->moove_remove_old_logs( $request['log_id'] );
			} catch ( Exception $e ) {
				unset( $e );
			}

		endif;
		// Main query to actually get the data.

		$where = str_replace( '`post_type`', 'uat_log.post_type', $where );

		$cache_key = 'aut_et_dt_cache_' . md5( $order . $where );
		$data      = Moove_UAT_Cache::get( $cache_key );
		if ( ! $data ) :
			$_post_types           = get_post_types( array( 'public' => true ) );
			/**
			 * Where clause filters
			 */
			$where = str_replace( '`post_type`', 'uat_log.post_type', $where );
			$where = str_replace( '`display_name`', 'uat_log.display_name', $where );
			$where = str_replace( '`user_email`', 'users_tbl.user_email', $where );
			$where = str_replace( '`user_login`', 'users_tbl.user_login', $where );
			$where = str_replace( '`permalink`', 'posts_tbl.guid', $where );

			$sql = "
				SELECT 
					`post_id`, 
					uat_log.display_name, 
					`user_ip`, 
					`status`,
					`referer`, 
					`visit_date`,
					`city`,					 
					`user_id`,
					posts_tbl.guid as `permalink`, 
					`event`, 
					`type`, 
					`time_spent`, 
					`extras`, 
					users_tbl.user_email,
					users_tbl.user_login,
					uat_log.post_type, 
					posts_tbl.post_title as `post_title`, 
					`request_url`,
					`archive_title`,
					`campaign_id` 
				FROM {$wpdb->prefix}moove_activity_log uat_log 
					LEFT JOIN {$wpdb->prefix}posts posts_tbl	
						ON uat_log.post_id = posts_tbl.id
					LEFT JOIN {$wpdb->base_prefix}users users_tbl	
						ON uat_log.user_id = users_tbl.id
				$where
				$order
			";

			$sql_count = "
				SELECT 
					COUNT(`visit_date`) as '0' 
				FROM {$wpdb->prefix}moove_activity_log uat_log 
					LEFT JOIN {$wpdb->prefix}posts posts_tbl	
						ON uat_log.post_id = posts_tbl.id
					LEFT JOIN {$wpdb->base_prefix}users users_tbl	
						ON uat_log.user_id = users_tbl.id
				$where
			";

			$data = $wpdb->get_results(
				$sql, // phpcs:ignore
				ARRAY_A
			); // db call ok; no-cache ok.

			Moove_UAT_Cache::set( $cache_key, $data );
		endif;

		$res_filter_length = $wpdb->get_results( $sql_count, ARRAY_A ); // phpcs:ignore
		$records_filtered  = isset( $res_filter_length[0] ) && isset( $res_filter_length[0][0] ) ? $res_filter_length[0][0] : 0;

		/*
		 * Output.
		 */

		$headers 		= array( 'Date / Time', 'Post Title', 'Post Type', 'User Email', 'Username', 'Display Name', 'Visit Duration', 'User Role', 'Location', 'IP Address', 'Referrer', 'Permalink', 'Full URL' );
		$headers_f 	= array_values( apply_filters('uat_csv_dt_header', array() ) );
		$headers 		= array( array_merge( $headers, $headers_f ) );
		
		return array(
			'limit'   => '',
			'headers' => $headers,
			'data'    => self::export_data_output( $columns, $data )
		);
	}

	/**
	 * Paginated/chunked export to avoid memory exhaustion on large datasets.
	 *
	 * Uses **keyset pagination** (`WHERE id > $last_id`) instead of
	 * LIMIT/OFFSET so chunk N has the same cost as chunk 1 on tables with
	 * millions of rows. Bulk-primes post and user caches before the row
	 * formatters run, so per-row `get_permalink()` / `get_userdata()` calls
	 * hit the in-memory cache instead of the database.
	 *
	 * @param  array  $request Data sent to server by DataTables.
	 * @param  array  $columns Column information array.
	 * @param  string $type    Export type: 'all', 'filtered', 'cpt'.
	 * @param  int    $last_id Cursor — only rows with `id` greater than this
	 *                         are returned. Pass 0 for the first chunk.
	 * @param  int    $limit   Maximum number of rows to return in this chunk.
	 * @return array           Chunk payload with `data`, `last_id`,
	 *                         `recordsTotal` (first chunk), `headers`
	 *                         (first chunk), `count`, `limit` and `has_more`.
	 */
	public static function export_paginated( $request, $columns, $type = 'all', $last_id = 0, $limit = 5000 ) {
		global $wpdb;

		$bindings = array();
		$last_id  = max( 0, intval( $last_id ) );
		$limit    = max( 1, min( 20000, intval( $limit ) ) );
		$where    = 'all' === $type ? '' : self::filter( $request, $columns, $bindings );

		if ( 'cpt' === $type && isset( $request['log_id'] ) && intval( $request['log_id'] ) ) :
			$where = 'WHERE `post_id` = ' . intval( $request['log_id'] );
		endif;

		/**
		 * Where clause filters — same column rewrites used by export().
		 */
		$where = str_replace( '`post_type`', 'uat_log.post_type', $where );
		$where = str_replace( '`display_name`', 'uat_log.display_name', $where );
		$where = str_replace( '`user_email`', 'users_tbl.user_email', $where );
		$where = str_replace( '`user_login`', 'users_tbl.user_login', $where );
		$where = str_replace( '`permalink`', 'posts_tbl.guid', $where );

		// Keyset cursor — append (or build) the WHERE so MySQL can do a
		// primary-key range scan starting at the cursor.
		$where_for_data = $where;
		if ( $last_id > 0 ) {
			$keyset         = 'uat_log.id > ' . intval( $last_id );
			$where_for_data = $where_for_data ? $where_for_data . ' AND ' . $keyset : 'WHERE ' . $keyset;
		}

		$base_from_count = "
			FROM {$wpdb->prefix}moove_activity_log uat_log
				LEFT JOIN {$wpdb->prefix}posts posts_tbl
					ON uat_log.post_id = posts_tbl.id
				LEFT JOIN {$wpdb->base_prefix}users users_tbl
					ON uat_log.user_id = users_tbl.id
			$where
		";

		$base_from_data = "
			FROM {$wpdb->prefix}moove_activity_log uat_log
				LEFT JOIN {$wpdb->prefix}posts posts_tbl
					ON uat_log.post_id = posts_tbl.id
				LEFT JOIN {$wpdb->base_prefix}users users_tbl
					ON uat_log.user_id = users_tbl.id
			$where_for_data
		";

		$sql = "
			SELECT
				uat_log.id,
				`post_id`,
				uat_log.display_name,
				`user_ip`,
				`status`,
				`referer`,
				`visit_date`,
				`city`,
				`user_id`,
				posts_tbl.guid as `permalink`,
				`event`,
				`type`,
				`time_spent`,
				`extras`,
				users_tbl.user_email,
				users_tbl.user_login,
				uat_log.post_type,
				posts_tbl.post_title as `post_title`,
				`request_url`,
				`archive_title`,
				`campaign_id`
			$base_from_data
			ORDER BY uat_log.`id` ASC
			LIMIT " . intval( $limit );

		$data = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore -- prepared values are integers above; identifiers come from filter() rewriting.

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		// Bulk-prime WordPress caches once per chunk so per-row formatters
		// (get_permalink / get_userdata / role lookups) hit memory, not the DB.
		$post_ids = array();
		$user_ids = array();
		foreach ( $data as $row ) {
			if ( ! empty( $row['post_id'] ) ) {
				$post_ids[ (int) $row['post_id'] ] = true;
			}
			if ( ! empty( $row['user_id'] ) ) {
				$user_ids[ (int) $row['user_id'] ] = true;
			}
		}
		if ( $post_ids && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_keys( $post_ids ), false, false );
		}
		if ( $user_ids && function_exists( 'cache_users' ) ) {
			cache_users( array_keys( $user_ids ) );
		}

		// Cursor for the next chunk = max id in this chunk.
		$row_count    = count( $data );
		$next_last_id = $last_id;
		if ( $row_count > 0 ) {
			$last_row     = end( $data );
			$next_last_id = isset( $last_row['id'] ) ? (int) $last_row['id'] : $last_id;
			reset( $data );
		}

		$response = array(
			'last_id'  => $next_last_id,
			'limit'    => $limit,
			'count'    => $row_count,
			'has_more' => $row_count === $limit,
			'data'     => self::export_data_output( $columns, $data ),
		);

		// Free per-chunk memory before encoding.
		unset( $data );

		// Compute total + headers only on the first chunk.
		if ( 0 === $last_id ) {
			if ( 'all' === $type ) {
				// Cheap unfiltered count — no joins.
				$sql_count = "SELECT COUNT(*) FROM {$wpdb->prefix}moove_activity_log";
			} else {
				$sql_count = "SELECT COUNT(uat_log.`id`) $base_from_count";
			}
			$total = $wpdb->get_var( $sql_count ); // phpcs:ignore

			$headers   = array( 'Date / Time', 'Post Title', 'Post Type', 'User Email', 'Username', 'Display Name', 'Visit Duration', 'User Role', 'Location', 'IP Address', 'Referrer', 'Permalink', 'Full URL' );
			$headers_f = array_values( apply_filters( 'uat_csv_dt_header', array() ) );
			$headers   = array( array_merge( $headers, $headers_f ) );

			$response['headers']      = $headers;
			$response['recordsTotal'] = (int) $total;
		}

		return $response;
	}

	/**
	 * Perform the SQL queries needed for an server-side processing requested,
	 * utilising the helper functions of this class, limit(), order() and
	 * filter() among others. The returned array is ready to be encoded as JSON
	 * in response to an SSP request, or can be modified if needed before
	 * sending back to the client.
	 *
	 *  @param  array  $request Data sent to server by DataTables.
	 *  @param  array  $columns Column information array.
	 *  @param string $type Type.
	 *  @return array     Server-side processing response array.
	 */
	public static function delete( $request, $columns ) {
		global $wpdb;
		$bindings    = array();
		$primary_key = 'id';
		$table       = "{$wpdb->prefix}moove_activity_log";
		$limit       = '';
		$order       = '';
		$where       = self::filter( $request, $columns, $bindings );

		$_post_types           = get_post_types( array( 'public' => true ) );

		/**
		 * Where clause filters
		 */
		$where = str_replace( '`post_type`', 'uat_log.post_type', $where );
		$where = str_replace( '`display_name`', 'uat_log.display_name', $where );
		$where = str_replace( '`user_email`', 'users_tbl.user_email', $where );
		$where = str_replace( '`user_login`', 'users_tbl.user_login', $where );
		$where = str_replace( '`permalink`', 'posts_tbl.guid', $where );

		$sql = "
			DELETE 
				uat_log
			FROM {$wpdb->prefix}moove_activity_log uat_log 
				LEFT JOIN {$wpdb->prefix}posts posts_tbl	
					ON uat_log.post_id = posts_tbl.id
				LEFT JOIN {$wpdb->base_prefix}users users_tbl	
					ON uat_log.user_id = users_tbl.id
			$where
			$order
			$limit
		";

		$data = $wpdb->get_results(
			$sql, // phpcs:ignore
			ARRAY_A
		); // db call ok; no-cache ok.

		return $data;
	}


	/**
	 * Perform the SQL queries needed for an server-side processing requested,
	 * utilising the helper functions of this class, limit(), order() and
	 * filter() among others. The returned array is ready to be encoded as JSON
	 * in response to an SSP request, or can be modified if needed before
	 * sending back to the client.
	 *
	 *  @param  array $request Data sent to server by DataTables.
	 *  @param  array $columns Column information array.
	 *  @return array     Server-side processing response array.
	 */
	public static function simple( $request, $columns ) {
		global $wpdb;
		$bindings    = array();
		$primary_key = 'id';
		$table       = "{$wpdb->prefix}moove_activity_log";

		// Build the SQL query string from the request.
		$limit = self::limit( $request, $columns );
		$order = self::order( $request, $columns );
		$where = self::filter( $request, $columns, $bindings );
		$where = str_replace( '`post_type`', 'uat_log.post_type', $where );

		// Main query to actually get the data.

		$cache_key   = 'aut_et_dt_cache_' . md5( $limit . $order . $where );
		$count_key   = 'aut_et_dt_count_' . md5( $where );
		$cached      = Moove_UAT_Cache::get( $cache_key );

		// `$cached` may be either the legacy raw rows array or the new
		// envelope `array( 'data' => …, 'records_filtered' => … )`. Normalise.
		$data              = false;
		$records_filtered  = false;
		if ( is_array( $cached ) && isset( $cached['data'] ) && array_key_exists( 'records_filtered', $cached ) ) {
			$data             = $cached['data'];
			$records_filtered = $cached['records_filtered'];
		} elseif ( is_array( $cached ) && ! empty( $cached ) && isset( $cached[0]['visit_date'] ) ) {
			$data = $cached;
		}

		if ( false === $data ) :
			/**
			 * Where clause filters
			 */
			$where = str_replace( '`post_type`', 'uat_log.post_type', $where );
			$where = str_replace( '`display_name`', 'uat_log.display_name', $where );
			$where = str_replace( '`user_email`', 'users_tbl.user_email', $where );
			$where = str_replace( '`user_login`', 'users_tbl.user_login', $where );
			$where = str_replace( '`permalink`', 'posts_tbl.guid', $where );

			// Detect whether the WHERE / ORDER clauses actually need the
			// joined wp_posts / wp_users tables. When they don't (the common
			// case — default view sorted by visit_date) we can use the
			// "deferred join" pattern: select the matching primary keys from
			// the base table first (so MySQL can use the visit_date /
			// post_type indexes and stop after LIMIT rows), then look up the
			// joined data only for those ~25 rows. This collapses queries
			// that used to file-sort millions of rows.
			$needs_posts_join = ( false !== strpos( $where, 'posts_tbl' ) || false !== strpos( $order, 'posts_tbl' ) );
			$needs_users_join = ( false !== strpos( $where, 'users_tbl' ) || false !== strpos( $order, 'users_tbl' ) );

			if ( ! $needs_posts_join && ! $needs_users_join && '' !== $limit ) {
				// Fast path — index-only scan of uat_log for the page of ids.
				$ids_sql = "
					SELECT uat_log.id
					FROM {$wpdb->prefix}moove_activity_log uat_log
					$where
					$order
					$limit
				";

				$ids_rows = $wpdb->get_col( $ids_sql ); // phpcs:ignore -- identifiers / integers only.
				$ids      = array_map( 'intval', (array) $ids_rows );

				if ( empty( $ids ) ) {
					$data = array();
				} else {
					$ids_in = implode( ',', $ids );

					$sql = "
						SELECT
							`post_id`,
							`visit_date`,
							uat_log.display_name,
							`user_ip`,
							`status`,
							`referer`,
							`city`,
							`user_id`,
							posts_tbl.guid as `permalink`,
							`event`,
							`type`,
							`time_spent`,
							`extras`,
							users_tbl.user_email,
							users_tbl.user_login,
							uat_log.post_type,
							`request_url`,
							`archive_title`,
							posts_tbl.post_title as `post_title`,
							`campaign_id`
						FROM {$wpdb->prefix}moove_activity_log uat_log
							LEFT JOIN {$wpdb->prefix}posts posts_tbl
								ON uat_log.post_id = posts_tbl.id
							LEFT JOIN {$wpdb->base_prefix}users users_tbl
								ON uat_log.user_id = users_tbl.id
						WHERE uat_log.id IN ($ids_in)
						$order
					";

					$data = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore -- ids are intval()'d.
				}
			} else {
				// Slow path — search/order needs the joined tables, so we
				// have to do the full join. Same query as before.
				$sql = "
					SELECT
						`post_id`,
						`visit_date`,
						uat_log.display_name,
						`user_ip`,
						`status`,
						`referer`,
						`city`,
						`user_id`,
						posts_tbl.guid as `permalink`,
						`event`,
						`type`,
						`time_spent`,
						`extras`,
						users_tbl.user_email,
						users_tbl.user_login,
						uat_log.post_type,
						`request_url`,
						`archive_title`,
						posts_tbl.post_title as `post_title`,
						`campaign_id`
					FROM {$wpdb->prefix}moove_activity_log uat_log
						LEFT JOIN {$wpdb->prefix}posts posts_tbl
							ON uat_log.post_id = posts_tbl.id
						LEFT JOIN {$wpdb->base_prefix}users users_tbl
							ON uat_log.user_id = users_tbl.id
					$where
					$order
					$limit
				";

				$data = $wpdb->get_results(
					$sql, // phpcs:ignore
					ARRAY_A
				); // db call ok; no-cache ok.
			}

			if ( ! is_array( $data ) ) {
				$data = array();
			}

			Moove_UAT_Cache::set( $cache_key, array( 'data' => $data, 'records_filtered' => false ), MINUTE_IN_SECONDS * 5 );
		endif;

		// Filtered count — share across page navigations of the same WHERE.
		if ( false === $records_filtered ) {
			$records_filtered = Moove_UAT_Cache::get( $count_key );
		}
		if ( false === $records_filtered ) {
			if ( '' === trim( (string) $where ) ) {
				$records_filtered = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}moove_activity_log" ); // phpcs:ignore
			} else {
				// Same heuristic as the data query — skip the joins when the
				// WHERE only touches the base table. COUNT(*) on
				// wp_moove_activity_log with a single-column index is orders
				// of magnitude cheaper than counting through two LEFT JOINs.
				$count_needs_posts = ( false !== strpos( $where, 'posts_tbl' ) );
				$count_needs_users = ( false !== strpos( $where, 'users_tbl' ) );

				if ( ! $count_needs_posts && ! $count_needs_users ) {
					$sql_count = "
						SELECT COUNT(*)
						FROM {$wpdb->prefix}moove_activity_log uat_log
						$where
					";
				} else {
					$sql_count = "
						SELECT COUNT(uat_log.`id`)
						FROM {$wpdb->prefix}moove_activity_log uat_log
							LEFT JOIN {$wpdb->prefix}posts posts_tbl
								ON uat_log.post_id = posts_tbl.id
							LEFT JOIN {$wpdb->base_prefix}users users_tbl
								ON uat_log.user_id = users_tbl.id
						$where
					";
				}
				$records_filtered = (int) $wpdb->get_var( $sql_count ); // phpcs:ignore
			}
			Moove_UAT_Cache::set( $count_key, $records_filtered, MINUTE_IN_SECONDS * 5 );
			Moove_UAT_Cache::set( $cache_key, array( 'data' => $data, 'records_filtered' => $records_filtered ), MINUTE_IN_SECONDS * 5 );
		}

		// Cheap unfiltered total — `recordsTotal` should reflect the whole
		// table, not the WHERE-filtered subset.
		$records_total = Moove_UAT_Cache::get( 'aut_et_dt_total' );
		if ( false === $records_total ) {
			$records_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}moove_activity_log" ); // phpcs:ignore
			Moove_UAT_Cache::set( 'aut_et_dt_total', $records_total, MINUTE_IN_SECONDS * 5 );
		}

		// Bulk-prime WordPress object caches for this page so per-row
		// formatters (get_permalink / get_userdata / get_post_type_object)
		// hit memory instead of issuing one query per row.
		$post_ids = array();
		$user_ids = array();
		foreach ( $data as $row ) {
			if ( ! empty( $row['post_id'] ) ) {
				$post_ids[ (int) $row['post_id'] ] = true;
			}
			if ( ! empty( $row['user_id'] ) ) {
				$user_ids[ (int) $row['user_id'] ] = true;
			}
		}
		if ( $post_ids && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_keys( $post_ids ), false, false );
		}
		if ( $user_ids && function_exists( 'cache_users' ) ) {
			cache_users( array_keys( $user_ids ) );
		}

		$date_filter       = array();
		$user_filter       = array();
		$users_role_filter = array();
		/**
		 * We should fill the filters only on init.
		 *
		 * The DISTINCT scans on `wp_moove_activity_log` are expensive on
		 * large tables — cache the result for 5 minutes so repeated table
		 * inits within that window are free.
		 */
		if ( isset( $request['draw'] ) && intval( $request['draw'] ) === 1 ) {
			$filters_key    = 'aut_et_dt_filters';
			$cached_filters = Moove_UAT_Cache::get( $filters_key );
			if ( is_array( $cached_filters ) && isset( $cached_filters['date_filter'], $cached_filters['users_filter'], $cached_filters['users_role_filter'] ) ) {
				$date_filter       = $cached_filters['date_filter'];
				$user_filter       = $cached_filters['users_filter'];
				$users_role_filter = $cached_filters['users_role_filter'];
			} else {
				$sql_month_year = "
					SELECT DISTINCT
					CONCAT(YEAR(uat_log.visit_date), DATE_FORMAT( uat_log.visit_date ,'%m' ) ) as `ym`
					FROM {$wpdb->prefix}moove_activity_log uat_log 
					ORDER BY `ym` DESC";

				$sql_users = "
					SELECT DISTINCT
						uat_log.`user_id`,
						users_tbl.user_login as `username`,
						users_tbl.display_name as `display_name`
					FROM {$wpdb->prefix}moove_activity_log uat_log 
						LEFT JOIN {$wpdb->base_prefix}users users_tbl	
							ON uat_log.user_id = users_tbl.id
					WHERE uat_log.user_id > 0
					ORDER BY users_tbl.user_login ASC
				";

				$date_filter = $wpdb->get_results( $sql_month_year, ARRAY_A ); // phpcs:ignore
				$user_filter = $wpdb->get_results( $sql_users, ARRAY_A ); // phpcs:ignore

				if ( $user_filter && ! empty( $user_filter ) ) :					
					$filter_user_ids = array();
					foreach ( $user_filter as $_user_data ) {
						$uid = (int) $_user_data['user_id'];
						if ( $uid > 0 ) {
							$filter_user_ids[ $uid ] = true;
						}
					}

					if ( $filter_user_ids ) {
						$ids_in  = implode( ',', array_map( 'intval', array_keys( $filter_user_ids ) ) );
						$cap_key = $wpdb->get_blog_prefix() . 'capabilities';
						$cap_rows = $wpdb->get_results(
							$wpdb->prepare(
								// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ids are intval()'d above.
								"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND user_id IN ($ids_in)",
								$cap_key
							),
							ARRAY_A
						); // phpcs:ignore -- direct query is intentional; caching is handled by the outer $filters_key transient.

						if ( is_array( $cap_rows ) ) {
							foreach ( $cap_rows as $cap_row ) {
								$caps = maybe_unserialize( $cap_row['meta_value'] );
								if ( ! is_array( $caps ) || empty( $caps ) ) {
									continue;
								}
								// First truthy key = primary role slug.
								foreach ( $caps as $role_slug => $enabled ) {
									if ( $enabled && is_string( $role_slug ) && '' !== $role_slug ) {
										$users_role_filter[ $role_slug ][] = (int) $cap_row['user_id'];
										break;
									}
								}
							}
						}
					}
				endif;

				Moove_UAT_Cache::set(
					$filters_key,
					array(
						'date_filter'       => $date_filter,
						'users_filter'      => $user_filter,
						'users_role_filter' => $users_role_filter,
					),
					MINUTE_IN_SECONDS * 5
				);
			}
		}

		/*
		 * Output.
		 */
		return array(
			'draw'              => isset( $request['draw'] ) ?
			intval( $request['draw'] ) :
			0,
			'recordsTotal'      => intval( $records_total ),
			'recordsFiltered'   => intval( $records_filtered ),
			'date_filter'       => $date_filter,
			'users_filter'      => $user_filter,
			'users_role_filter' => $users_role_filter,
			'data'              => self::data_output( $columns, $data )
		);
	}

	/*
	 * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
	 * Internal methods.
	 */

	/**
	 * Throw a fatal error.
	 *
	 * This writes out an error message in a JSON string which DataTables will
	 * see and show to the user in the browser.
	 *
	 * @param  string $msg Message to send to the client.
	 */
	public static function fatal( $msg ) {
		echo wp_json_encode(
			array(
				'error' => $msg,
			)
		);
		exit( 0 );
	}

	/**
	 * Pull a particular property from each assoc. array in a numeric array,
	 * returning and array of the property values from each item.
	 *
	 *  @param  array  $a    Array to get data from.
	 *  @param  string $prop Property to read.
	 *  @return array        Array of property values.
	 */
	public static function pluck( $a, $prop ) {
		$out = array();

		for ( $i = 0, $len = count( $a ); $i < $len; $i++ ) {
			if ( empty( $a[ $i ][ $prop ] ) && 0 !== $a[ $i ][ $prop ] ) {
				continue;
			}

			// removing the $out array index confuses the filter method in doing proper binding,
			// adding it ensures that the array data are mapped correctly.
			$out[ $i ] = $a[ $i ][ $prop ];
		}

		return $out;
	}
}
