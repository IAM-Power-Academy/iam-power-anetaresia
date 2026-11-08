<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shitjet e Skuadrës, bonuset (provizionet) dhe pagesat e bonuseve.
 *
 * Bonusi = (shuma / (1 + TVSH)) × përqindja e paketës.
 * Asnjë shitje nuk aktivizohet pa konfirmimin e Mimozës.
 */
class IAMP_Sales {

	const META_NAME = 'iamp_team_name';

	/* ---------------- Skuadra ---------------- */

	public static function is_team( $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		return $user_id && (bool) get_user_meta( $user_id, self::META_NAME, true );
	}

	public static function team() {
		$users = get_users( array( 'meta_key' => self::META_NAME, 'orderby' => 'display_name' ) ); // phpcs:ignore
		$out   = array();
		foreach ( $users as $u ) {
			$out[ $u->ID ] = get_user_meta( $u->ID, self::META_NAME, true );
		}
		asort( $out );
		return $out;
	}

	public static function trainer_name( $user_id ) {
		$n = get_user_meta( $user_id, self::META_NAME, true );
		if ( $n ) {
			return $n;
		}
		$u = get_userdata( $user_id );
		return $u ? $u->display_name : '#' . $user_id;
	}

	public static function add_member( $login_or_email, $name, $opening = 0 ) {
		$user = is_email( $login_or_email ) ? get_user_by( 'email', $login_or_email ) : get_user_by( 'login', $login_or_email );
		if ( ! $user ) {
			return new WP_Error( 'iamp_team', 'Përdoruesi nuk u gjet në iampower.al: ' . $login_or_email );
		}
		update_user_meta( $user->ID, self::META_NAME, sanitize_text_field( $name ) );
		if ( $opening ) {
			self::add_payout( $user->ID, -1 * (float) $opening, current_time( 'Y-m-d' ), '', 'Saldo e mbetur nga llogaritë e mëparshme', 'opening' );
		}
		$course = (int) get_option( 'iamp_team_course' );
		if ( $course && function_exists( 'ld_update_course_access' ) ) {
			ld_update_course_access( $user->ID, $course, false );
		}
		IAMP_Log::add( 'team', $user->ID, 'U shtua në Skuadër si ' . $name );
		return $user->ID;
	}

	public static function remove_member( $user_id ) {
		delete_user_meta( $user_id, self::META_NAME );
		$course = (int) get_option( 'iamp_team_course' );
		if ( $course && function_exists( 'ld_update_course_access' ) ) {
			ld_update_course_access( $user_id, $course, true );
		}
		IAMP_Log::add( 'team', $user_id, 'U hoq nga Skuadra' );
	}

	/* ---------------- Shitjet ---------------- */

	public static function vat() {
		$v = IAMP_Settings::get( 'vat_pct' );
		return is_numeric( $v ) ? (float) $v : 18.0;
	}

	public static function commission( $amount, $pct ) {
		return round( ( (float) $amount / ( 1 + self::vat() / 100 ) ) * ( (float) $pct / 100 ), 2 );
	}

	public static function get( $id ) {
		global $wpdb;
		$t = IAMP_DB::table( 'sales' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) ); // phpcs:ignore
	}

	/**
	 * @return int|WP_Error
	 */
	public static function create( $data ) {
		global $wpdb;
		$pkg = IAMP_DB::get_package( (int) $data['package_id'] );
		if ( ! $pkg ) {
			return new WP_Error( 'iamp_sale', 'Zgjidh kursin ose ofertën.' );
		}
		if ( ! is_email( $data['client_email'] ) ) {
			return new WP_Error( 'iamp_sale', 'Email-i i klientit nuk është i saktë.' );
		}
		$pct = (float) $pkg->commission_pct;
		$row = array(
			'created_at'           => IAMP_DB::now(),
			'trainer_id'           => (int) $data['trainer_id'],
			'client_name'          => $data['client_name'],
			'client_email'         => strtolower( $data['client_email'] ),
			'client_phone'         => $data['client_phone'],
			'package_id'           => (int) $pkg->id,
			'extra_courses'        => IAMP_DB::ids_to_string( $data['extra_courses'] ),
			'amount'               => (float) $data['amount'],
			'currency'             => $data['currency'] ? $data['currency'] : 'EUR',
			'payment_method'       => $data['payment_method'],
			'payment_ref'          => $data['payment_ref'],
			'payment_date'         => $data['payment_date'] ? $data['payment_date'] : null,
			'proof_id'             => (int) $data['proof_id'],
			'collected_by_trainer' => $data['collected_by_trainer'] ? 1 : 0,
			'commission_pct'       => $pct,
			'commission'           => self::commission( $data['amount'], $pct ),
			'status'               => 'pending',
			'note'                 => $data['note'],
		);
		$wpdb->insert( IAMP_DB::table( 'sales' ), $row );
		$id = (int) $wpdb->insert_id;
		IAMP_Log::add( 'sale_new', 0, array( 'shitja' => $id, 'trajneri' => self::trainer_name( $row['trainer_id'] ), 'klienti' => $row['client_email'], 'paketa' => $pkg->name, 'shuma' => $row['amount'] ) );
		self::notify_admin( $id );
		self::sync( $id );
		return $id;
	}

	public static function update( $id, $row ) {
		global $wpdb;
		$wpdb->update( IAMP_DB::table( 'sales' ), $row, array( 'id' => (int) $id ) );
	}

	public static function set_pct( $id, $pct ) {
		$s = self::get( $id );
		if ( ! $s ) {
			return;
		}
		self::update( $id, array( 'commission_pct' => (float) $pct, 'commission' => self::commission( $s->amount, $pct ) ) );
		self::sync( $id );
	}

	/**
	 * Konfirmo: aktivizon klientin (paketa + bonuset shtesë) dhe e kalon shitjen te "Konfirmuar".
	 *
	 * @return true|WP_Error
	 */
	public static function approve( $id, $admin_note = '' ) {
		$s = self::get( $id );
		if ( ! $s || 'pending' !== $s->status ) {
			return new WP_Error( 'iamp_sale', 'Shitja nuk pret konfirmim.' );
		}
		$parts = explode( ' ', trim( $s->client_name ), 2 );
		$res   = IAMP_Access::grant(
			array(
				'email'        => $s->client_email,
				'first_name'   => $parts[0],
				'last_name'    => isset( $parts[1] ) ? $parts[1] : '',
				'package_id'   => (int) $s->package_id,
				'courses'      => IAMP_DB::string_to_ids( $s->extra_courses ),
				'source'       => 'shitje',
				'external_ref' => 'shitje:' . $s->id,
				'note'         => 'Trajneri: ' . self::trainer_name( $s->trainer_id ) . ( $s->note ? ' · ' . $s->note : '' ),
				'send_email'   => true,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		self::update(
			$id,
			array(
				'status'        => 'approved',
				'membership_id' => (int) $res['membership_id'],
				'decided_at'    => IAMP_DB::now(),
				'decided_by'    => get_current_user_id(),
				'admin_note'    => $admin_note,
			)
		);
		IAMP_Log::add( 'sale_ok', $res['user_id'], array( 'shitja' => (int) $id, 'trajneri' => self::trainer_name( $s->trainer_id ) ) );
		self::sync( $id );
		return true;
	}

	public static function reject( $id, $admin_note = '' ) {
		$s = self::get( $id );
		if ( ! $s || 'pending' !== $s->status ) {
			return false;
		}
		self::update( $id, array( 'status' => 'rejected', 'decided_at' => IAMP_DB::now(), 'decided_by' => get_current_user_id(), 'admin_note' => $admin_note ) );
		IAMP_Log::add( 'sale_no', 0, array( 'shitja' => (int) $id, 'arsyeja' => $admin_note ) );
		self::sync( $id );
		return true;
	}

	public static function query( $args = array() ) {
		global $wpdb;
		$t     = IAMP_DB::table( 'sales' );
		$where = array( '1=1' );
		$vals  = array();
		if ( ! empty( $args['trainer_id'] ) ) {
			$where[] = 'trainer_id = %d';
			$vals[]  = (int) $args['trainer_id'];
		}
		if ( ! empty( $args['status'] ) ) {
			$where[] = 'status = %s';
			$vals[]  = $args['status'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(client_name LIKE %s OR client_email LIKE %s)';
			$vals[]  = $like;
			$vals[]  = $like;
		}
		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 200;
		$sql   = "SELECT * FROM $t WHERE " . implode( ' AND ', $where ) . " ORDER BY (status = 'pending') DESC, id DESC LIMIT $limit";
		return $vals ? $wpdb->get_results( $wpdb->prepare( $sql, $vals ) ) : $wpdb->get_results( $sql ); // phpcs:ignore
	}

	public static function pending_count() {
		global $wpdb;
		$t = IAMP_DB::table( 'sales' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE status = 'pending'" ); // phpcs:ignore
	}

	/* ---------------- Bonuset ---------------- */

	public static function add_payout( $trainer_id, $amount, $date, $method = '', $note = '', $kind = 'payout' ) {
		global $wpdb;
		$wpdb->insert(
			IAMP_DB::table( 'payouts' ),
			array(
				'trainer_id' => (int) $trainer_id,
				'amount'     => (float) $amount,
				'paid_on'    => $date ? $date : current_time( 'Y-m-d' ),
				'method'     => $method,
				'note'       => $note,
				'kind'       => $kind,
				'created_by' => get_current_user_id(),
				'created_at' => IAMP_DB::now(),
			)
		);
		IAMP_Log::add( 'payout', $trainer_id, array( 'shuma' => $amount, 'lloji' => $kind, 'shënim' => $note ) );
		return (int) $wpdb->insert_id;
	}

	public static function delete_payout( $id ) {
		global $wpdb;
		$wpdb->delete( IAMP_DB::table( 'payouts' ), array( 'id' => (int) $id ) );
	}

	public static function payouts( $trainer_id = 0 ) {
		global $wpdb;
		$t = IAMP_DB::table( 'payouts' );
		if ( $trainer_id ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE trainer_id = %d ORDER BY paid_on DESC, id DESC", $trainer_id ) ); // phpcs:ignore
		}
		return $wpdb->get_results( "SELECT * FROM $t ORDER BY paid_on DESC, id DESC LIMIT 300" ); // phpcs:ignore
	}

	/**
	 * Përmbledhja e bonuseve të një trajneri.
	 *  earned   = bonuset e shitjeve të konfirmuara
	 *  kept     = bonuset që trajneri i mbajti vetë nga pagesa që mori
	 *  paid     = bonuset që Mimoza ia ka paguar
	 *  opening  = saldo e mbetur nga llogaritë e vjetra (pozitive = i takon trajnerit)
	 *  balance  = sa i mbetet për të marrë
	 */
	public static function summary( $trainer_id, $year = 0 ) {
		global $wpdb;
		$s  = IAMP_DB::table( 'sales' );
		$p  = IAMP_DB::table( 'payouts' );
		$id = (int) $trainer_id;
		$r  = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, COALESCE(SUM(amount),0) sold, COALESCE(SUM(commission),0) earned, COALESCE(SUM(CASE WHEN collected_by_trainer=1 THEN commission ELSE 0 END),0) kept FROM $s WHERE trainer_id = %d AND status = 'approved'", $id ) ); // phpcs:ignore
		$pending = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, COALESCE(SUM(commission),0) c FROM $s WHERE trainer_id = %d AND status = 'pending'", $id ) ); // phpcs:ignore
		$paid    = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM $p WHERE trainer_id = %d AND kind = 'payout'", $id ) ); // phpcs:ignore
		$opening = -1 * (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM $p WHERE trainer_id = %d AND kind = 'opening'", $id ) ); // phpcs:ignore
		$month   = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, COALESCE(SUM(amount),0) sold, COALESCE(SUM(commission),0) c FROM $s WHERE trainer_id = %d AND status IN ('approved','pending') AND created_at >= %s", $id, current_time( 'Y-m-01 00:00:00' ) ) ); // phpcs:ignore
		$earned  = (float) $r->earned;
		$kept    = (float) $r->kept;
		return array(
			'count'         => (int) $r->n,
			'sold'          => (float) $r->sold,
			'earned'        => $earned,
			'kept'          => $kept,
			'paid'          => $paid,
			'opening'       => $opening,
			'balance'       => round( $opening + $earned - $kept - $paid, 2 ),
			'pending_n'     => (int) $pending->n,
			'pending_c'     => (float) $pending->c,
			'month_n'       => (int) $month->n,
			'month_sold'    => (float) $month->sold,
			'month_c'       => (float) $month->c,
		);
	}

	/* ---------------- Njoftime dhe Google Sheet ---------------- */

	private static function notify_admin( $id ) {
		$s   = self::get( $id );
		$pkg = IAMP_DB::get_package( $s->package_id );
		$to  = IAMP_Settings::get( 'notify_email' );
		$to  = $to ? $to : get_option( 'admin_email' );
		$msg = 'Shitje e re nga ' . self::trainer_name( $s->trainer_id ) . ":\n\n"
			. 'Klienti: ' . $s->client_name . ' (' . $s->client_email . ")\n"
			. 'Kursi: ' . ( $pkg ? $pkg->name : '' ) . "\n"
			. 'Paguar: ' . number_format( (float) $s->amount, 2 ) . ' ' . $s->currency . ' · ' . $s->payment_method . "\n\n"
			. 'Konfirmo te: ' . admin_url( 'admin.php?page=iamp-sales' );
		wp_mail( $to, 'IAM Power: shitje e re pret konfirmim (' . $s->client_name . ')', $msg );
	}

	/**
	 * Dërgon shitjen te Google Sheet përmes Apps Script (Web App). Nëse nuk është vendosur URL, nuk bën asgjë.
	 */
	public static function sync( $id ) {
		$url = IAMP_Settings::get( 'sheet_url' );
		if ( ! $url ) {
			return;
		}
		$s = self::get( $id );
		if ( ! $s ) {
			return;
		}
		$pkg    = IAMP_DB::get_package( $s->package_id );
		$extras = array();
		foreach ( IAMP_DB::string_to_ids( $s->extra_courses ) as $cid ) {
			$extras[] = get_the_title( $cid );
		}
		$labels = array( 'pending' => 'Pret konfirmim', 'approved' => 'Konfirmuar dhe aktivizuar', 'rejected' => 'Refuzuar' );
		$body   = array(
			'secret'      => IAMP_Settings::get( 'sheet_secret' ),
			'id'          => (int) $s->id,
			'data'        => mysql2date( 'd.m.Y H:i', $s->created_at ),
			'trajneri'    => self::trainer_name( $s->trainer_id ),
			'klienti'     => $s->client_name,
			'email'       => $s->client_email,
			'telefoni'    => $s->client_phone,
			'kursi'       => $pkg ? $pkg->name : '',
			'bonuset'     => implode( ', ', $extras ),
			'shuma'       => (float) $s->amount,
			'valuta'      => $s->currency,
			'pagesa'      => $s->payment_method,
			'referenca'   => $s->payment_ref,
			'data_pageses'=> $s->payment_date,
			'mori_trajneri' => $s->collected_by_trainer ? 'Po' : 'Jo',
			'bonus_pct'   => (float) $s->commission_pct,
			'bonus'       => (float) $s->commission,
			'statusi'     => isset( $labels[ $s->status ] ) ? $labels[ $s->status ] : $s->status,
			'shenime'     => $s->note,
		);
		wp_remote_post( $url, array( 'timeout' => 8, 'blocking' => false, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $body ) ) );
	}
}
