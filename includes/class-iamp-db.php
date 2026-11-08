<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IAMP_DB {

	const VERSION = '1.1.0';

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'iamp_' . $name;
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$p       = self::table( 'packages' );
		$m       = self::table( 'memberships' );
		$c       = self::table( 'codes' );
		$l       = self::table( 'log' );

		dbDelta( "CREATE TABLE $p (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL,
			courses TEXT NULL,
			groups_ids TEXT NULL,
			duration_days INT UNSIGNED NOT NULL DEFAULT 0,
			commission_pct DECIMAL(5,2) NOT NULL DEFAULT 15.00,
			price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			product_ids TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id)
		) $charset;" );

		dbDelta( "CREATE TABLE $m (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			package_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			courses TEXT NULL,
			groups_ids TEXT NULL,
			source VARCHAR(30) NOT NULL DEFAULT 'manual',
			external_ref VARCHAR(190) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			starts_at DATETIME NOT NULL,
			expires_at DATETIME NULL,
			reminded TINYINT(1) NOT NULL DEFAULT 0,
			note TEXT NULL,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY status (status),
			KEY expires_at (expires_at),
			KEY external_ref (external_ref)
		) $charset;" );

		dbDelta( "CREATE TABLE $c (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code VARCHAR(64) NOT NULL,
			package_id BIGINT UNSIGNED NOT NULL,
			max_uses INT UNSIGNED NOT NULL DEFAULT 1,
			uses INT UNSIGNED NOT NULL DEFAULT 0,
			valid_until DATETIME NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			note VARCHAR(190) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code)
		) $charset;" );

		dbDelta( "CREATE TABLE $l (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			action VARCHAR(60) NOT NULL,
			details TEXT NULL,
			ip VARCHAR(64) NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY action (action)
		) $charset;" );

		$s  = self::table( 'sales' );
		$po = self::table( 'payouts' );
		dbDelta( "CREATE TABLE $s (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			trainer_id BIGINT UNSIGNED NOT NULL,
			client_name VARCHAR(190) NOT NULL,
			client_email VARCHAR(190) NOT NULL,
			client_phone VARCHAR(60) NULL,
			package_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			extra_courses TEXT NULL,
			amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			currency VARCHAR(10) NOT NULL DEFAULT 'EUR',
			payment_method VARCHAR(60) NULL,
			payment_ref VARCHAR(190) NULL,
			payment_date DATE NULL,
			proof_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			collected_by_trainer TINYINT(1) NOT NULL DEFAULT 0,
			commission_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00,
			commission DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			membership_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			note TEXT NULL,
			admin_note TEXT NULL,
			decided_at DATETIME NULL,
			decided_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY trainer_id (trainer_id),
			KEY status (status)
		) $charset;" );

		dbDelta( "CREATE TABLE $po (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			trainer_id BIGINT UNSIGNED NOT NULL,
			amount DECIMAL(10,2) NOT NULL,
			paid_on DATE NOT NULL,
			method VARCHAR(60) NULL,
			note TEXT NULL,
			kind VARCHAR(20) NOT NULL DEFAULT 'payout',
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY trainer_id (trainer_id)
		) $charset;" );

		update_option( 'iamp_db_version', self::VERSION );
	}

	/* ---------- Ndihmës ---------- */

	public static function now() {
		return current_time( 'mysql' );
	}

	public static function ids_to_string( $ids ) {
		$ids = array_filter( array_map( 'absint', (array) $ids ) );
		return implode( ',', array_unique( $ids ) );
	}

	public static function string_to_ids( $str ) {
		if ( '' === (string) $str ) {
			return array();
		}
		return array_values( array_unique( array_filter( array_map( 'absint', explode( ',', (string) $str ) ) ) ) );
	}

	/* ---------- Paketat ---------- */

	public static function get_packages( $only_active = false ) {
		global $wpdb;
		$t   = self::table( 'packages' );
		$sql = "SELECT * FROM $t";
		if ( $only_active ) {
			$sql .= " WHERE status = 'active'";
		}
		$sql .= ' ORDER BY name ASC';
		return $wpdb->get_results( $sql ); // phpcs:ignore
	}

	public static function get_package( $id ) {
		global $wpdb;
		$t = self::table( 'packages' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) ); // phpcs:ignore
	}

	public static function save_package( $data, $id = 0 ) {
		global $wpdb;
		$t   = self::table( 'packages' );
		$row = array(
			'name'          => $data['name'],
			'courses'       => self::ids_to_string( $data['courses'] ),
			'groups_ids'    => self::ids_to_string( $data['groups'] ),
			'duration_days' => absint( $data['duration_days'] ),
			'commission_pct'=> isset( $data['commission_pct'] ) ? (float) $data['commission_pct'] : 15,
			'price'         => isset( $data['price'] ) ? (float) $data['price'] : 0,
			'product_ids'   => $data['product_ids'],
			'status'        => $data['status'],
		);
		if ( $id ) {
			$wpdb->update( $t, $row, array( 'id' => $id ) );
			return $id;
		}
		$row['created_at'] = self::now();
		$wpdb->insert( $t, $row );
		return (int) $wpdb->insert_id;
	}

	public static function delete_package( $id ) {
		global $wpdb;
		$wpdb->delete( self::table( 'packages' ), array( 'id' => $id ) );
	}

	/* ---------- Anëtarësitë ---------- */

	public static function get_membership( $id ) {
		global $wpdb;
		$t = self::table( 'memberships' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) ); // phpcs:ignore
	}

	public static function get_user_memberships( $user_id, $status = '' ) {
		global $wpdb;
		$t = self::table( 'memberships' );
		if ( $status ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE user_id = %d AND status = %s ORDER BY id DESC", $user_id, $status ) ); // phpcs:ignore
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE user_id = %d ORDER BY id DESC", $user_id ) ); // phpcs:ignore
	}

	public static function find_by_ref( $ref ) {
		global $wpdb;
		$t = self::table( 'memberships' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE external_ref = %s", $ref ) ); // phpcs:ignore
	}

	public static function insert_membership( $row ) {
		global $wpdb;
		$row['created_at'] = self::now();
		$row['updated_at'] = self::now();
		$wpdb->insert( self::table( 'memberships' ), $row );
		return (int) $wpdb->insert_id;
	}

	public static function update_membership( $id, $row ) {
		global $wpdb;
		$row['updated_at'] = self::now();
		$wpdb->update( self::table( 'memberships' ), $row, array( 'id' => $id ) );
	}

	/**
	 * Lista e anëtarësive për panelin, me kërkim dhe faqosje.
	 */
	public static function query_memberships( $args ) {
		global $wpdb;
		$m     = self::table( 'memberships' );
		$u     = $wpdb->users;
		$where = array( '1=1' );
		$vals  = array();

		if ( ! empty( $args['status'] ) ) {
			$where[] = 'm.status = %s';
			$vals[]  = $args['status'];
		}
		if ( ! empty( $args['package_id'] ) ) {
			$where[] = 'm.package_id = %d';
			$vals[]  = (int) $args['package_id'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(u.user_email LIKE %s OR u.display_name LIKE %s)';
			$vals[]  = $like;
			$vals[]  = $like;
		}
		if ( ! empty( $args['expiring_days'] ) ) {
			$where[] = "m.status = 'active' AND m.expires_at IS NOT NULL AND m.expires_at <= %s";
			$vals[]  = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + DAY_IN_SECONDS * (int) $args['expiring_days'] );
		}

		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 50;
		$page     = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
		$offset   = ( $page - 1 ) * $per_page;

		$where_sql = implode( ' AND ', $where );
		$base      = "FROM $m m LEFT JOIN $u u ON u.ID = m.user_id WHERE $where_sql";

		$count_sql = "SELECT COUNT(*) $base";
		$list_sql  = "SELECT m.*, u.user_email, u.display_name $base ORDER BY m.id DESC";
		if ( $per_page > 0 ) {
			$list_sql .= " LIMIT $per_page OFFSET $offset";
		}

		if ( $vals ) {
			$count_sql = $wpdb->prepare( $count_sql, $vals ); // phpcs:ignore
			$list_sql  = $wpdb->prepare( $list_sql, $vals ); // phpcs:ignore
		}

		return array(
			'total' => (int) $wpdb->get_var( $count_sql ), // phpcs:ignore
			'rows'  => $wpdb->get_results( $list_sql ), // phpcs:ignore
		);
	}

	public static function stats() {
		global $wpdb;
		$m    = self::table( 'memberships' );
		$soon = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + DAY_IN_SECONDS * 7 );
		$week = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - DAY_IN_SECONDS * 7 );
		return array(
			'active'   => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM $m WHERE status = 'active'" ), // phpcs:ignore
			'expiring' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $m WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at <= %s", $soon ) ), // phpcs:ignore
			'new_week' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $m WHERE created_at >= %s", $week ) ), // phpcs:ignore
			'expired'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $m WHERE status = 'expired'" ), // phpcs:ignore
		);
	}

	/* ---------- Kodet ---------- */

	public static function get_code( $code ) {
		global $wpdb;
		$t = self::table( 'codes' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE code = %s", $code ) ); // phpcs:ignore
	}

	public static function get_codes( $limit = 300 ) {
		global $wpdb;
		$t = self::table( 'codes' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t ORDER BY id DESC LIMIT %d", $limit ) ); // phpcs:ignore
	}

	public static function insert_code( $row ) {
		global $wpdb;
		$row['created_at'] = self::now();
		return $wpdb->insert( self::table( 'codes' ), $row );
	}

	/**
	 * Rrit përdorimin e kodit në mënyrë atomike (mbron nga përdorimi i dyfishtë).
	 */
	public static function consume_code( $id ) {
		global $wpdb;
		$t = self::table( 'codes' );
		$n = $wpdb->query( $wpdb->prepare( "UPDATE $t SET uses = uses + 1 WHERE id = %d AND status = 'active' AND uses < max_uses", $id ) ); // phpcs:ignore
		return $n > 0;
	}

	public static function release_code( $id ) {
		global $wpdb;
		$t = self::table( 'codes' );
		$wpdb->query( $wpdb->prepare( "UPDATE $t SET uses = GREATEST(uses - 1, 0) WHERE id = %d", $id ) ); // phpcs:ignore
	}

	public static function set_code_status( $id, $status ) {
		global $wpdb;
		$wpdb->update( self::table( 'codes' ), array( 'status' => $status ), array( 'id' => $id ) );
	}
}
