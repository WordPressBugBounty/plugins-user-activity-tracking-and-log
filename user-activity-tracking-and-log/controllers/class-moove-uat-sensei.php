<?php
/**
 * Sensei LMS compatibility.
 *
 * This is the *hygiene* half of Sensei support and deliberately lives in
 * the free plugin, because none of it is a feature — it is the plugin
 * declining to record things it should never have recorded:
 *
 *   1. `sensei_message` is the private student <-> teacher conversation
 *      post type. It is registered public, so the tracker would log it
 *      along with each message's subject line. A GDPR-adjacent plugin
 *      should not do that by default.
 *
 *   2. Teachers previewing their own course are switched into a real
 *      `preview_student` account. Logging it does not merely add noise,
 *      it falsifies the site owner's reports — their own staff appear
 *      as students.
 *
 *   3. Sensei's throw-away guest accounts are deleted after use. The
 *      page-view log denormalises `display_name` into every row and its
 *      cleanup only prunes rows whose *post* has gone, so a deleted
 *      guest would otherwise leave rows reading "Guest Student 007"
 *      for ever — a name Sensei's counter later reuses for somebody
 *      else.
 *
 * The premium add-on builds on this: it adds Sensei student-progress
 * event tracking and the option to exclude guests altogether, by
 * filtering the role lists exposed below.
 *
 * @category  Moove_UAT_Sensei
 * @package   user-activity-tracking-and-log
 * @author    Moove Agency
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'Moove_UAT_Sensei' ) ) :

	/**
	 * Sensei LMS compatibility layer.
	 */
	class Moove_UAT_Sensei {

		/**
		 * Role Sensei gives anonymous learners on an open course.
		 */
		const GUEST_ROLE = 'guest_student';

		/**
		 * Role Sensei switches a teacher into when they preview a course.
		 */
		const PREVIEW_ROLE = 'preview_student';

		/**
		 * Display name written over a guest's log rows once Sensei has
		 * deleted the underlying account.
		 */
		const GUEST_LABEL = 'Guest Student';

		/**
		 * Post types excluded from tracking by default.
		 *
		 * @var array
		 */
		private static $excluded_post_types = array( 'sensei_message' );

		/**
		 * Wire up the integration. No-op when Sensei is not present.
		 */
		public static function register() {
			if ( ! self::is_sensei_active() ) {
				return;
			}

			add_filter( 'moove_uat_filter_data', array( __CLASS__, 'filter_tracking_data' ), 10, 1 );
			add_filter( 'uat_new_post_type_default_status', array( __CLASS__, 'post_type_default_status' ), 10, 2 );
			add_action( 'delete_user', array( __CLASS__, 'handle_user_deletion' ), 10, 1 );
		}

		/**
		 * Is Sensei LMS active?
		 *
		 * @return bool
		 */
		public static function is_sensei_active() {
			return defined( 'SENSEI_LMS_VERSION' ) || class_exists( 'Sensei_Main', false );
		}

		/**
		 * Sensei's guest role, read from Sensei when available.
		 *
		 * @return string
		 */
		public static function guest_role() {
			return defined( 'Sensei_Guest_User::ROLE' ) ? constant( 'Sensei_Guest_User::ROLE' ) : self::GUEST_ROLE;
		}

		/**
		 * Sensei's teacher-preview role.
		 *
		 * @return string
		 */
		public static function preview_role() {
			return defined( 'Sensei_Preview_User::ROLE' ) ? constant( 'Sensei_Preview_User::ROLE' ) : self::PREVIEW_ROLE;
		}

		/**
		 * Roles whose activity must never reach the log.
		 *
		 * Teacher previews only, by default. Guests are genuine (if
		 * anonymous) learners, so their activity is kept; the premium
		 * add-on filters this list when guest tracking is switched off.
		 *
		 * @return array
		 */
		public static function excluded_roles() {
			/**
			 * Filter the roles excluded from tracking entirely.
			 *
			 * @param array $roles Role slugs.
			 */
			$roles = apply_filters( 'uat_sensei_excluded_roles', array( self::preview_role() ) );

			return is_array( $roles ) ? array_values( array_unique( $roles ) ) : array();
		}

		/**
		 * Roles whose log rows are kept but stripped of identity when the
		 * account is deleted.
		 *
		 * @return array
		 */
		public static function anonymised_roles() {
			/**
			 * Filter the roles whose logs are anonymised rather than purged.
			 *
			 * @param array $roles Role slugs.
			 */
			$roles = apply_filters( 'uat_sensei_anonymised_roles', array( self::guest_role() ) );

			return is_array( $roles ) ? array_values( array_unique( $roles ) ) : array();
		}

		/**
		 * Every throw-away role, whatever its handling.
		 *
		 * @return array
		 */
		public static function temporary_roles() {
			return apply_filters(
				'uat_sensei_temporary_roles',
				array_values( array_unique( array_merge( self::excluded_roles(), self::anonymised_roles() ) ) )
			);
		}

		/**
		 * Post types excluded from tracking.
		 *
		 * @return array
		 */
		public static function excluded_post_types() {
			/**
			 * Filter the Sensei post types excluded from tracking.
			 *
			 * @param array $post_types Post type slugs.
			 */
			$post_types = apply_filters( 'uat_sensei_excluded_post_types', self::$excluded_post_types );

			return is_array( $post_types ) ? $post_types : array();
		}

		/**
		 * Does the user hold any of the given roles?
		 *
		 * @param int   $user_id User ID.
		 * @param array $roles   Role slugs to test against.
		 * @return bool
		 */
		public static function user_has_role( $user_id, $roles ) {
			$user_id = intval( $user_id );
			if ( $user_id <= 0 || empty( $roles ) ) {
				return false;
			}

			// get_userdata() is served from the WP object cache, so this
			// costs nothing on a warmed request.
			$user = get_userdata( $user_id );
			if ( ! $user || empty( $user->roles ) ) {
				return false;
			}

			return (bool) array_intersect( (array) $user->roles, (array) $roles );
		}

		/**
		 * Should this user's activity be dropped entirely?
		 *
		 * @param int $user_id User ID.
		 * @return bool
		 */
		public static function is_excluded_user( $user_id ) {
			return self::user_has_role( $user_id, self::excluded_roles() );
		}

		/**
		 * Is this a guest whose rows should be kept but anonymised?
		 *
		 * @param int $user_id User ID.
		 * @return bool
		 */
		public static function is_anonymised_user( $user_id ) {
			return self::user_has_role( $user_id, self::anonymised_roles() );
		}

		/**
		 * Is this user one of Sensei's throw-away accounts, either kind?
		 *
		 * @param int $user_id User ID.
		 * @return bool
		 */
		public static function is_temporary_user( $user_id ) {
			return self::user_has_role( $user_id, self::temporary_roles() );
		}

		/**
		 * Abort the insert for excluded content and excluded accounts.
		 *
		 * Runs on `moove_uat_filter_data`, the single choke point every
		 * write passes through — both the page-view log and the premium
		 * event-tracking log. Returning a falsy value cancels the write.
		 *
		 * @param array $data Row about to be inserted.
		 * @return array|false
		 */
		public static function filter_tracking_data( $data ) {
			if ( ! is_array( $data ) ) {
				return $data;
			}

			$excluded = self::excluded_post_types();

			// The page-view log carries post_type directly.
			if ( isset( $data['post_type'] ) && in_array( $data['post_type'], $excluded, true ) ) {
				return false;
			}

			// The event-tracking log has no post_type column — resolve it.
			if ( ! isset( $data['post_type'] ) && isset( $data['post_id'] ) && intval( $data['post_id'] ) > 0 ) {
				$post_type = get_post_type( intval( $data['post_id'] ) );
				if ( $post_type && in_array( $post_type, $excluded, true ) ) {
					return false;
				}
			}

			if ( isset( $data['user_id'] ) && self::is_excluded_user( $data['user_id'] ) ) {
				return false;
			}

			return $data;
		}

		/**
		 * Leave excluded post types switched off when they are first
		 * detected, rather than defaulting them on like everything else.
		 *
		 * @param int    $status    Proposed default status.
		 * @param string $post_type Post type slug.
		 * @return int
		 */
		public static function post_type_default_status( $status, $post_type ) {
			if ( in_array( $post_type, self::excluded_post_types(), true ) ) {
				return 0;
			}
			return $status;
		}

		/**
		 * Handle a Sensei throw-away account being deleted.
		 *
		 * Two outcomes, deliberately:
		 *
		 *   Guests   — the learning activity is real and worth keeping,
		 *              so the rows survive but lose their identity. The
		 *              user_id is retained so one guest's journey through
		 *              a course can still be followed.
		 *
		 *   Previews — a teacher checking their own course is not student
		 *              activity at all, so those rows go.
		 *
		 * Scoped to these roles on purpose: purging logs for every
		 * deleted user would silently destroy audit history clients
		 * rely on.
		 *
		 * @param int $user_id User about to be deleted.
		 */
		public static function handle_user_deletion( $user_id ) {
			$user_id = intval( $user_id );

			if ( self::is_anonymised_user( $user_id ) ) {
				self::anonymise_user_logs( $user_id );
				return;
			}

			if ( ! self::is_excluded_user( $user_id ) ) {
				return;
			}

			if ( class_exists( 'Moove_Activity_Database_Model' ) ) {
				Moove_Activity_Database_Model::delete_log( 'user_id', $user_id );
			}

			// Lets the premium add-on clear its own tables for this user.
			do_action( 'uat_delete_user_activity', $user_id );
		}

		/**
		 * Back-compat alias for the previous method name.
		 *
		 * @param int $user_id User about to be deleted.
		 */
		public static function purge_temporary_user_logs( $user_id ) {
			self::handle_user_deletion( $user_id );
		}

		/**
		 * Strip the identity from a guest's log rows, keeping the activity.
		 *
		 * Only the page-view log needs rewriting — the premium
		 * event-tracking log has no denormalised display_name, it LEFT
		 * JOINs `wp_users`, so a deleted account already renders as N/A.
		 *
		 * @param int $user_id User about to be deleted.
		 */
		public static function anonymise_user_logs( $user_id ) {
			$user_id = intval( $user_id );
			if ( $user_id <= 0 ) {
				return;
			}

			/**
			 * Filter the label written over an anonymised guest's rows.
			 *
			 * @param string $label   Replacement display name.
			 * @param int    $user_id User being anonymised.
			 */
			$label = apply_filters( 'uat_sensei_guest_display_name', self::GUEST_LABEL, $user_id );

			if ( class_exists( 'Moove_Activity_Database_Model' ) ) {
				Moove_Activity_Database_Model::update(
					array( 'display_name' => $label ),
					array( 'user_id' => $user_id )
				);
			}

			do_action( 'uat_anonymise_user_activity', $user_id, $label );
		}
	}

endif;

Moove_UAT_Sensei::register();
