(function($){
	var log_id = 0;

	$(document).ready(function() {
		if (
			'undefined' === typeof moove_frontend_activity_scripts ||
			'undefined' === typeof moove_frontend_activity_scripts.log_enabled ||
			'undefined' === typeof moove_frontend_activity_scripts.log_enabled.post_id
		) {
			return;
		}

		var post_id      = moove_frontend_activity_scripts.post_id;
		var request_url  = window.location.href;

		// Skip if this post was already tracked in this browser session (prevents
		// double-fires from duplicate script execution or fast SPA-style navigation).
		var dedupe_key = 'uat_pv_' + post_id + '_' + window.location.pathname;
		try {
			if ( sessionStorage.getItem( dedupe_key ) ) {
				return;
			}
			sessionStorage.setItem( dedupe_key, '1' );
		} catch (e) {
			// sessionStorage unavailable — continue without dedupe.
		}

		// Check whether time-spent tracking is enabled for this visitor.
		var ts_enabled = false;
		try {
			if ( 'undefined' !== typeof moove_frontend_activity_scripts.extras ) {
				var extras_obj = JSON.parse( moove_frontend_activity_scripts.extras );
				ts_enabled = 'undefined' !== typeof extras_obj.ts_status && extras_obj.ts_status === '1';
			}
		} catch (e) {}

		var payload = {
			action:        'moove_activity_track_pageview',
			post_id:       post_id,
			is_single:     moove_frontend_activity_scripts.is_single,
			is_page:       moove_frontend_activity_scripts.is_page,
			user_id:       moove_frontend_activity_scripts.current_user,
			referrer:      moove_frontend_activity_scripts.referrer,
			extras:        moove_frontend_activity_scripts.extras,
			is_archive:    moove_frontend_activity_scripts.is_archive,
			is_front_page: moove_frontend_activity_scripts.is_front_page,
			is_home:       moove_frontend_activity_scripts.is_home,
			archive_title: moove_frontend_activity_scripts.archive_title,
			request_url:   request_url,
		};

		if ( ! ts_enabled && 'function' === typeof navigator.sendBeacon ) {
			// Fire-and-forget: no response needed when time-spent is disabled.
			// sendBeacon is non-blocking and survives page navigation/unload.
			var beacon_data = new FormData();
			Object.keys( payload ).forEach( function( key ) {
				beacon_data.append( key, payload[ key ] );
			} );
			navigator.sendBeacon( moove_frontend_activity_scripts.ajaxurl, beacon_data );
		} else {
			// Time-spent tracking requires the log_id from the response.
			$.post(
				moove_frontend_activity_scripts.ajaxurl,
				payload,
				function( msg ) {
					try {
						var response = msg ? JSON.parse( msg ) : false;
						// Support both plain integer responses and {id: N} JSON objects.
						if ( response && typeof response === 'object' && response.id ) {
							log_id = response.id;
						} else if ( response && isFinite( response ) && parseInt( response, 10 ) > 0 ) {
							log_id = parseInt( response, 10 );
						}

						if ( log_id && ts_enabled ) {
							window.addEventListener( 'beforeunload', function() {
								if ( 'function' === typeof navigator.sendBeacon ) {
									var log_data = new FormData();
									log_data.append( 'action', 'moove_activity_track_unload' );
									log_data.append( 'log_id', log_id );
									navigator.sendBeacon( moove_frontend_activity_scripts.ajaxurl, log_data );
								} else {
									$.post(
										moove_frontend_activity_scripts.ajaxurl,
										{
											action: 'moove_activity_track_unload',
											log_id: log_id,
										}
									);
								}
							} );
						}
					} catch (e) {}
				}
			);
		}
	});

})(jQuery);

