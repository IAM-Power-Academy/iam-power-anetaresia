<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bërthama: krijimi i përdoruesve, dhënia dhe heqja e qasjes në LearnDash.
 */
class IAMP_Access {

	/**
	 * Gjen përdoruesin sipas email-it ose e krijon.
	 *
	 * @return array|WP_Error array( user_id, created )
	 */
	public static function get_or_create_user( $email, $first_name = '', $last_name = '' ) {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'iamp_email', 'Email-i nuk është i vlefshëm: ' . $email );
		}

		$user = get_user_by( 'email', $email );
		if ( $user ) {
			// Plotëso emrin nëse mungon.
			if ( $first_name && ! $user->first_name ) {
				wp_update_user( array( 'ID' => $user->ID, 'first_name' => $first_name, 'last_name' => $last_name ) );
			}
			return array( 'user_id' => (int) $user->ID, 'created' => false );
		}

		$base  = sanitize_user( current( explode( '@', $email ) ), true );
		$base  = $base ? $base : 'anetar';
		$login = $base;
		$i     = 1;
		while ( username_exists( $login ) ) {
			$login = $base . $i;
			$i++;
		}

		$display = trim( $first_name . ' ' . $last_name );
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 20, true, true ),
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'display_name' => $display ? $display : $login,
				'role'         => get_option( 'default_role', 'subscriber' ),
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		update_user_meta( $user_id, 'iamp_created_by_plugin', 1 );
		IAMP_Log::add( 'user_created', $user_id, array( 'email' => $email ) );
		return array( 'user_id' => (int) $user_id, 'created' => true );
	}

	/**
	 * Llogarit datën e skadimit.
	 *
	 * @param int         $days       0 = pa afat.
	 * @param string|null $from_mysql Data nga e cila nis numërimi.
	 */
	public static function compute_expiry( $days, $from_mysql = null ) {
		$days = (int) $days;
		if ( $days <= 0 ) {
			return null;
		}
		$now  = current_time( 'timestamp' );
		$from = $from_mysql ? max( $now, strtotime( $from_mysql ) ) : $now;
		return gmdate( 'Y-m-d 23:59:59', $from + $days * DAY_IN_SECONDS );
	}

	/**
	 * Jep qasje.
	 *
	 * $args:
	 *  - email, first_name, last_name  (ose user_id)
	 *  - package_id
	 *  - courses[], groups[]           (shtesë ose në vend të paketës)
	 *  - duration_days                 (null = sipas paketës)
	 *  - expires_at                    (Y-m-d, ka përparësi ndaj duration_days)
	 *  - source                        manual | bulk | code | webhook
	 *  - external_ref, note
	 *  - send_email                    bool
	 *
	 * @return array|WP_Error
	 */
	public static function grant( $args ) {
		if ( ! iamp_learndash_active() ) {
			return new WP_Error( 'iamp_ld', 'LearnDash nuk është aktiv.' );
		}

		$args = wp_parse_args(
			$args,
			array(
				'user_id'       => 0,
				'email'         => '',
				'first_name'    => '',
				'last_name'     => '',
				'package_id'    => 0,
				'courses'       => array(),
				'groups'        => array(),
				'duration_days' => null,
				'expires_at'    => '',
				'source'        => 'manual',
				'external_ref'  => '',
				'note'          => '',
				'send_email'    => true,
			)
		);

		// Mos përpuno dy herë të njëjtën porosi (webhook i përsëritur).
		if ( $args['external_ref'] ) {
			$existing = IAMP_DB::find_by_ref( $args['external_ref'] );
			foreach ( $existing as $row ) {
				if ( 'active' === $row->status && (int) $row->package_id === (int) $args['package_id'] ) {
					return array( 'membership_id' => (int) $row->id, 'user_id' => (int) $row->user_id, 'duplicate' => true, 'created' => false );
				}
			}
		}

		// Përdoruesi.
		$created = false;
		if ( $args['user_id'] ) {
			$user_id = (int) $args['user_id'];
			if ( ! get_userdata( $user_id ) ) {
				return new WP_Error( 'iamp_user', 'Përdoruesi nuk u gjet.' );
			}
		} else {
			$res = self::get_or_create_user( $args['email'], $args['first_name'], $args['last_name'] );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$user_id = $res['user_id'];
			$created = $res['created'];
		}

		// Kurset dhe grupet.
		$courses  = array_map( 'absint', (array) $args['courses'] );
		$groups   = array_map( 'absint', (array) $args['groups'] );
		$duration = $args['duration_days'];
		$package  = null;

		if ( $args['package_id'] ) {
			$package = IAMP_DB::get_package( $args['package_id'] );
			if ( ! $package ) {
				return new WP_Error( 'iamp_pkg', 'Paketa nuk ekziston.' );
			}
			$courses = array_merge( $courses, IAMP_DB::string_to_ids( $package->courses ) );
			$groups  = array_merge( $groups, IAMP_DB::string_to_ids( $package->groups_ids ) );
			if ( null === $duration || '' === $duration ) {
				$duration = (int) $package->duration_days;
			}
		}
		$courses = array_values( array_unique( array_filter( $courses ) ) );
		$groups  = array_values( array_unique( array_filter( $groups ) ) );

		if ( ! $courses && ! $groups ) {
			return new WP_Error( 'iamp_empty', 'Zgjidh të paktën një kurs, grup ose paketë.' );
		}

		// Skadimi.
		$expires = null;
		if ( $args['expires_at'] ) {
			$ts = strtotime( $args['expires_at'] );
			if ( $ts ) {
				$expires = gmdate( 'Y-m-d 23:59:59', $ts );
			}
		}

		// Nëse ka tashmë anëtarësi aktive me të njëjtën paketë: zgjate në vend që ta dyfishosh.
		if ( $package ) {
			foreach ( IAMP_DB::get_user_memberships( $user_id, 'active' ) as $row ) {
				if ( (int) $row->package_id !== (int) $package->id ) {
					continue;
				}
				if ( null === $row->expires_at ) {
					// Ka qasje të përhershme — vetëm sigurohu që kurset janë të hapura.
					self::apply_learndash( $user_id, $courses, $groups, false );
					return array( 'membership_id' => (int) $row->id, 'user_id' => $user_id, 'extended' => false, 'created' => $created, 'already' => true );
				}
				$new_exp = $expires ? $expires : self::compute_expiry( $duration, $row->expires_at );
				IAMP_DB::update_membership(
					$row->id,
					array(
						'expires_at' => $new_exp,
						'reminded'   => 0,
						'courses'    => IAMP_DB::ids_to_string( array_merge( IAMP_DB::string_to_ids( $row->courses ), $courses ) ),
						'groups_ids' => IAMP_DB::ids_to_string( array_merge( IAMP_DB::string_to_ids( $row->groups_ids ), $groups ) ),
					)
				);
				self::apply_learndash( $user_id, $courses, $groups, false );
				IAMP_Log::add( 'extend', $user_id, array( 'anetaresia' => (int) $row->id, 'skadimi' => $new_exp, 'burimi' => $args['source'] ) );
				$fresh = IAMP_DB::get_membership( $row->id );
				if ( $args['send_email'] && IAMP_Settings::get( 'send_welcome' ) ) {
					IAMP_Emails::send( 'welcome', $user_id, $fresh, array( 'new_user' => false ) );
				}
				return array( 'membership_id' => (int) $row->id, 'user_id' => $user_id, 'extended' => true, 'created' => $created );
			}
		}

		if ( ! $expires ) {
			$expires = self::compute_expiry( $duration );
		}

		$membership_id = IAMP_DB::insert_membership(
			array(
				'user_id'      => $user_id,
				'package_id'   => $package ? (int) $package->id : 0,
				'courses'      => IAMP_DB::ids_to_string( $courses ),
				'groups_ids'   => IAMP_DB::ids_to_string( $groups ),
				'source'       => sanitize_key( $args['source'] ),
				'external_ref' => $args['external_ref'] ? substr( $args['external_ref'], 0, 190 ) : null,
				'status'       => 'active',
				'starts_at'    => IAMP_DB::now(),
				'expires_at'   => $expires,
				'note'         => $args['note'],
				'created_by'   => get_current_user_id(),
			)
		);

		self::apply_learndash( $user_id, $courses, $groups, false );

		IAMP_Log::add(
			'activate',
			$user_id,
			array(
				'anetaresia' => $membership_id,
				'paketa'     => $package ? $package->name : '',
				'kurset'     => $courses,
				'grupet'     => $groups,
				'skadimi'    => $expires ? $expires : 'pa afat',
				'burimi'     => $args['source'],
			)
		);

		if ( $args['send_email'] && IAMP_Settings::get( 'send_welcome' ) ) {
			IAMP_Emails::send( 'welcome', $user_id, IAMP_DB::get_membership( $membership_id ), array( 'new_user' => $created ) );
		}

		do_action( 'iamp_membership_activated', $membership_id, $user_id );

		return array( 'membership_id' => $membership_id, 'user_id' => $user_id, 'created' => $created );
	}

	/**
	 * Heq qasjen e një anëtarësie — por ruan kurset që mbulohen nga anëtarësi të tjera aktive.
	 *
	 * @param string $new_status revoked | expired
	 */
	public static function revoke( $membership_id, $new_status = 'revoked', $send_email = false ) {
		$m = IAMP_DB::get_membership( $membership_id );
		if ( ! $m || 'active' !== $m->status ) {
			return false;
		}

		IAMP_DB::update_membership( $m->id, array( 'status' => $new_status ) );

		$keep_courses = array();
		$keep_groups  = array();
		foreach ( IAMP_DB::get_user_memberships( $m->user_id, 'active' ) as $other ) {
			$keep_courses = array_merge( $keep_courses, IAMP_DB::string_to_ids( $other->courses ) );
			$keep_groups  = array_merge( $keep_groups, IAMP_DB::string_to_ids( $other->groups_ids ) );
		}

		$remove_courses = array_diff( IAMP_DB::string_to_ids( $m->courses ), $keep_courses );
		$remove_groups  = array_diff( IAMP_DB::string_to_ids( $m->groups_ids ), $keep_groups );

		/**
		 * Lejon përjashtimin e kurseve nga heqja (p.sh. kurse falas).
		 */
		$remove_courses = apply_filters( 'iamp_courses_to_remove', $remove_courses, $m );

		self::apply_learndash( $m->user_id, $remove_courses, $remove_groups, true );

		IAMP_Log::add(
			'expired' === $new_status ? 'expire' : 'revoke',
			$m->user_id,
			array( 'anetaresia' => (int) $m->id, 'kurset_e_hequra' => array_values( $remove_courses ), 'grupet_e_hequra' => array_values( $remove_groups ) )
		);

		if ( $send_email && IAMP_Settings::get( 'send_expired' ) ) {
			IAMP_Emails::send( 'expired', $m->user_id, $m );
		}

		do_action( 'iamp_membership_revoked', $m->id, $m->user_id, $new_status );
		return true;
	}

	/**
	 * Zgjat ose riaktivizon një anëtarësi.
	 *
	 * @param int    $days     ditë shtesë (përdoret nëse $new_date bosh)
	 * @param string $new_date Y-m-d ose 'never' për pa afat
	 */
	public static function extend( $membership_id, $days = 0, $new_date = '' ) {
		$m = IAMP_DB::get_membership( $membership_id );
		if ( ! $m ) {
			return new WP_Error( 'iamp_m', 'Anëtarësia nuk u gjet.' );
		}

		if ( 'never' === $new_date ) {
			$exp = null;
		} elseif ( $new_date && strtotime( $new_date ) ) {
			$exp = gmdate( 'Y-m-d 23:59:59', strtotime( $new_date ) );
		} else {
			$from = ( 'active' === $m->status ) ? $m->expires_at : null;
			$exp  = self::compute_expiry( max( 1, (int) $days ), $from );
		}

		$was_active = ( 'active' === $m->status );
		IAMP_DB::update_membership( $m->id, array( 'expires_at' => $exp, 'status' => 'active', 'reminded' => 0 ) );

		if ( ! $was_active ) {
			self::apply_learndash( $m->user_id, IAMP_DB::string_to_ids( $m->courses ), IAMP_DB::string_to_ids( $m->groups_ids ), false );
		}

		IAMP_Log::add( $was_active ? 'extend' : 'reactivate', $m->user_id, array( 'anetaresia' => (int) $m->id, 'skadimi' => $exp ? $exp : 'pa afat' ) );
		return true;
	}

	/**
	 * Thirrja e vetme drejt LearnDash.
	 */
	public static function apply_learndash( $user_id, $courses, $groups, $remove ) {
		if ( ! iamp_learndash_active() ) {
			return;
		}
		foreach ( (array) $courses as $cid ) {
			if ( $cid && 'sfwd-courses' === get_post_type( $cid ) ) {
				ld_update_course_access( (int) $user_id, (int) $cid, (bool) $remove );
			}
		}
		if ( function_exists( 'ld_update_group_access' ) ) {
			foreach ( (array) $groups as $gid ) {
				if ( $gid && 'groups' === get_post_type( $gid ) ) {
					ld_update_group_access( (int) $user_id, (int) $gid, (bool) $remove );
				}
			}
		}
	}

	/**
	 * Lista e kurseve LearnDash.
	 */
	public static function courses() {
		return get_posts( array( 'post_type' => 'sfwd-courses', 'post_status' => array( 'publish', 'private', 'draft' ), 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
	}

	public static function groups() {
		return get_posts( array( 'post_type' => 'groups', 'post_status' => array( 'publish', 'private', 'draft' ), 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
	}
}
