<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shitjet e Skuadrës, bonuset (provizionet) dhe pagesat e bonuseve.
 *
 * Bonusi = (shuma / (1 + TVSH)) × përqindja e paketës.
 * Shitjet ruhen vetëm te fleta Google "Shitjet e Skuadrës 2026"; aty Mimoza i konfirmon.
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

	/* ---------------- Shitjet (ruhen vetëm në Google Sheet) ---------------- */

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'iamp_daily_check', array( __CLASS__, 'flush_queue' ) );
	}

	public static function vat() {
		$v = IAMP_Settings::get( 'vat_pct' );
		return is_numeric( $v ) ? (float) $v : 18.0;
	}

	public static function commission( $amount, $pct ) {
		return round( ( (float) $amount / ( 1 + self::vat() / 100 ) ) * ( (float) $pct / 100 ), 2 );
	}

	/** A është lidhur fleta "Shitjet e Skuadrës 2026"? */
	public static function sheet_ready() {
		return (bool) IAMP_Settings::get( 'sheet_url' );
	}

	/** Numri i radhës i shitjes (vetëm numëruesi ruhet në WordPress). */
	private static function next_id() {
		global $wpdb;
		$n = (int) get_option( 'iamp_sale_seq', 0 );
		if ( ! $n ) {
			$t = IAMP_DB::table( 'sales' );
			$n = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM $t" ); // phpcs:ignore
		}
		$n++;
		update_option( 'iamp_sale_seq', $n, false );
		return $n;
	}

	/**
	 * Regjistron shitjen: dërgohet direkt te Google Sheet. Asgjë nga shitja nuk ruhet në WordPress.
	 *
	 * @return int|WP_Error
	 */
	public static function create( $data ) {
		$pkg = IAMP_DB::get_package( (int) $data['package_id'] );
		if ( ! $pkg ) {
			return new WP_Error( 'iamp_sale', 'Zgjidh kursin ose ofertën.' );
		}
		if ( ! is_email( $data['client_email'] ) ) {
			return new WP_Error( 'iamp_sale', 'Email-i i klientit nuk është i saktë.' );
		}
		if ( ! self::sheet_ready() ) {
			return new WP_Error( 'iamp_sale', 'Regjistrimi i shitjeve nuk është lidhur ende. Njofto Mimozën.' );
		}
		$pct    = (float) $pkg->commission_pct;
		$extras = array();
		$ids    = array_filter( array_map( 'absint', (array) $data['extra_courses'] ) );
		foreach ( $ids as $cid ) {
			$extras[] = get_the_title( $cid );
		}
		$id   = self::next_id();
		$body = array(
			'action'        => 'new',
			'id'            => $id,
			'data'          => current_time( 'd.m.Y H:i' ),
			'trajneri'      => self::trainer_name( $data['trainer_id'] ),
			'trajneri_id'   => (int) $data['trainer_id'],
			'klienti'       => $data['client_name'],
			'email'         => strtolower( $data['client_email'] ),
			'telefoni'      => $data['client_phone'],
			'kursi'         => $pkg->name,
			'paketa_id'     => (int) $pkg->id,
			'bonuset'       => implode( ', ', $extras ),
			'kurset_id'     => implode( ',', $ids ),
			'shuma'         => (float) $data['amount'],
			'valuta'        => $data['currency'] ? $data['currency'] : 'EUR',
			'pagesa'        => $data['payment_method'],
			'referenca'     => $data['payment_ref'],
			'data_pageses'  => $data['payment_date'] ? mysql2date( 'd.m.Y', $data['payment_date'] ) : '',
			'mori_trajneri' => $data['collected_by_trainer'] ? 'Po' : 'Jo',
			'bonus_pct'     => $pct,
			'tvsh'          => self::vat(),
			'statusi'       => 'Pret konfirmim',
			'shenime'       => $data['note'],
			'deshmia'       => '',
		);
		if ( ! empty( $data['proof']['b64'] ) ) {
			$body['deshmia_emri'] = $data['proof']['emri'];
			$body['deshmia_tipi'] = $data['proof']['tipi'];
			$body['deshmia_b64']  = $data['proof']['b64'];
		}
		$res = self::send( $body );
		unset( $body['deshmia_b64'] );
		if ( is_wp_error( $res ) ) {
			if ( ! empty( $data['proof']['b64'] ) && strlen( $data['proof']['b64'] ) < 3 * MB_IN_BYTES ) {
				$body['deshmia_b64'] = $data['proof']['b64'];
			}
			// Fleta nuk u arrit: provohet sërish vetë çdo orë, që shitja të mos humbasë.
			$q   = (array) get_option( 'iamp_sheet_queue', array() );
			$q[] = $body;
			update_option( 'iamp_sheet_queue', $q, false );
			IAMP_Log::add( 'sheet_error', 0, 'Shitja #' . $id . ' pret dërgimin te fleta: ' . $res->get_error_message() );
		}
		self::notify_admin( $body );
		self::flush_summary();
		return $id;
	}

	/**
	 * Dërgon një kërkesë te Apps Script i fletës.
	 *
	 * @return array|WP_Error Përgjigjja JSON.
	 */
	public static function send( $body ) {
		$url = IAMP_Settings::get( 'sheet_url' );
		if ( ! $url ) {
			return new WP_Error( 'iamp_sheet', 'Fleta nuk është lidhur.' );
		}
		$body['secret'] = IAMP_Settings::get( 'sheet_secret' );
		$r = wp_remote_post(
			$url,
			array(
				'timeout'     => 25,
				'redirection' => 5,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$json = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( ! is_array( $json ) || empty( $json['ok'] ) ) {
			$msg = is_array( $json ) && ! empty( $json['error'] ) ? $json['error'] : 'Përgjigje e papritur (' . wp_remote_retrieve_response_code( $r ) . ').';
			return new WP_Error( 'iamp_sheet', $msg );
		}
		return $json;
	}

	/** Riprovon shitjet që nuk arritën te fleta. */
	public static function flush_queue() {
		$q = (array) get_option( 'iamp_sheet_queue', array() );
		if ( ! $q ) {
			return;
		}
		$left = array();
		foreach ( $q as $body ) {
			if ( is_wp_error( self::send( $body ) ) ) {
				$left[] = $body;
			}
		}
		update_option( 'iamp_sheet_queue', $left, false );
		self::flush_summary();
	}

	/* ---------------- Lidhja fleta → webfaqja ---------------- */

	public static function routes() {
		register_rest_route(
			'iampower/v1',
			'/fleta',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'from_sheet' ),
				'permission_callback' => '__return_true', // Kontrolli bëhet me çelësin sekret.
			)
		);
	}

	/**
	 * Thirret nga fleta kur Mimoza zgjedh "Konfirmo" (aktivizon klientin) ose kur ndryshon diçka.
	 */
	public static function from_sheet( WP_REST_Request $request ) {
		$d      = (array) $request->get_json_params();
		$secret = isset( $d['secret'] ) ? (string) $d['secret'] : '';
		if ( ! $secret || ! hash_equals( (string) IAMP_Settings::get( 'sheet_secret' ), $secret ) ) {
			IAMP_Log::add( 'sheet_error', 0, 'Çelës i pasaktë nga fleta, IP ' . IAMP_Log::ip() );
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'FORBIDDEN' ), 403 );
		}
		$action = isset( $d['action'] ) ? $d['action'] : '';
		if ( 'refresh' === $action || 'ping' === $action ) {
			self::flush_summary();
			return new WP_REST_Response( array( 'ok' => true ) );
		}
		if ( 'approve' !== $action ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'Veprim i panjohur.' ), 400 );
		}
		$email = sanitize_email( isset( $d['email'] ) ? $d['email'] : '' );
		if ( ! is_email( $email ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'Email-i i klientit nuk është i saktë.' ) );
		}
		$pkg = IAMP_DB::get_package( isset( $d['paketa_id'] ) ? (int) $d['paketa_id'] : 0 );
		if ( ! $pkg ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'Paketa nuk u gjet në webfaqe.' ) );
		}
		$name    = trim( sanitize_text_field( isset( $d['klienti'] ) ? $d['klienti'] : '' ) );
		$parts   = explode( ' ', $name, 2 );
		$courses = array_filter( array_map( 'absint', explode( ',', isset( $d['kurset_id'] ) ? (string) $d['kurset_id'] : '' ) ) );
		$res     = IAMP_Access::grant(
			array(
				'email'        => $email,
				'first_name'   => $parts[0],
				'last_name'    => isset( $parts[1] ) ? $parts[1] : '',
				'package_id'   => (int) $pkg->id,
				'courses'      => $courses,
				'source'       => 'shitje',
				'external_ref' => 'shitje:' . absint( isset( $d['id'] ) ? $d['id'] : 0 ),
				'note'         => 'Trajneri: ' . sanitize_text_field( isset( $d['trajneri'] ) ? $d['trajneri'] : '' ),
				'send_email'   => true,
			)
		);
		self::flush_summary();
		if ( is_wp_error( $res ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => $res->get_error_message() ) );
		}
		return new WP_REST_Response( array( 'ok' => true, 'membership_id' => (int) $res['membership_id'], 'duplicate' => ! empty( $res['duplicate'] ) ) );
	}

	/* ---------------- Përmbledhjet nga fleta ---------------- */

	public static function flush_summary() {
		delete_transient( 'iamp_sheet_sum' );
	}

	/**
	 * Totalet e lexuara nga fleta (ruhen 10 minuta). Nuk përmbajnë emra klientësh.
	 *
	 * @return array{trainers:array,months:array,pending:int,ok:bool}
	 */
	public static function sheet_stats() {
		$c = get_transient( 'iamp_sheet_sum' );
		if ( is_array( $c ) ) {
			return $c;
		}
		$empty = array( 'trainers' => array(), 'months' => array(), 'days' => array(), 'pending' => 0, 'ok' => false );
		if ( ! self::sheet_ready() ) {
			return $empty;
		}
		$r = self::send( array( 'action' => 'summary' ) );
		if ( is_wp_error( $r ) ) {
			$last       = get_option( 'iamp_sheet_sum_last' );
			$out        = is_array( $last ) ? $last : $empty;
			$out['ok']  = false;
			$out['err'] = $r->get_error_message();
			set_transient( 'iamp_sheet_sum', $out, 2 * MINUTE_IN_SECONDS );
			return $out;
		}
		$out = array(
			'trainers' => isset( $r['trainers'] ) ? (array) $r['trainers'] : array(),
			'months'   => isset( $r['months'] ) ? (array) $r['months'] : array(),
			'pending'  => isset( $r['pending'] ) ? (int) $r['pending'] : 0,
			'days'     => isset( $r['days'] ) ? (array) $r['days'] : array(),
			'ok'       => true,
		);
		set_transient( 'iamp_sheet_sum', $out, 10 * MINUTE_IN_SECONDS );
		update_option( 'iamp_sheet_sum_last', $out, false );
		return $out;
	}

	public static function pending_count() {
		$s = self::sheet_stats();
		return (int) $s['pending'];
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
	 *  earned / kept / shitjet  = nga fleta "Shitjet e Skuadrës 2026"
	 *  paid / opening          = pagesat e bonuseve që Mimoza regjistron te Skuadra & Bonuset
	 *  balance                 = opening + earned − kept − paid
	 */
	public static function summary( $trainer_id ) {
		global $wpdb;
		$p       = IAMP_DB::table( 'payouts' );
		$id      = (int) $trainer_id;
		$stats   = self::sheet_stats();
		$t       = isset( $stats['trainers'][ $id ] ) ? (array) $stats['trainers'][ $id ] : array();
		$g       = function ( $k ) use ( $t ) {
			return isset( $t[ $k ] ) ? (float) $t[ $k ] : 0.0;
		};
		$paid    = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM $p WHERE trainer_id = %d AND kind = 'payout'", $id ) ); // phpcs:ignore
		$opening = -1 * (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM $p WHERE trainer_id = %d AND kind = 'opening'", $id ) ); // phpcs:ignore
		$earned  = $g( 'earned' );
		$kept    = $g( 'kept' );
		return array(
			'count'      => (int) $g( 'count' ),
			'sold'       => $g( 'sold' ),
			'earned'     => $earned,
			'kept'       => $kept,
			'paid'       => $paid,
			'opening'    => $opening,
			'balance'    => round( $opening + $earned - $kept - $paid, 2 ),
			'pending_n'  => (int) $g( 'pending_n' ),
			'pending_c'  => $g( 'pending_c' ),
			'month_n'    => (int) $g( 'month_n' ),
			'month_sold' => $g( 'month_sold' ),
			'month_c'    => $g( 'month_c' ),
			'sheet_ok'   => ! empty( $stats['ok'] ),
		);
	}

	/* ---------------- Njoftimi ---------------- */

	private static function notify_admin( $b ) {
		$to   = IAMP_Settings::get( 'notify_email' );
		$to   = $to ? $to : get_option( 'admin_email' );
		$link = IAMP_Settings::get( 'sheet_link' );
		$msg  = 'Shitje e re nga ' . $b['trajneri'] . ":\n\n"
			. 'Klienti: ' . $b['klienti'] . ' (' . $b['email'] . ")\n"
			. 'Kursi: ' . $b['kursi'] . "\n"
			. 'Paguar: ' . number_format( (float) $b['shuma'], 2 ) . ' ' . $b['valuta'] . ' · ' . $b['pagesa'] . "\n\n"
			. 'Konfirmo te fleta "Shitjet e Skuadrës 2026"' . ( $link ? ': ' . $link : ' në Google Drive (CLAUDE SKUADRA).' );
		wp_mail( $to, 'IAM Power: shitje e re pret konfirmim (' . $b['klienti'] . ')', $msg );
	}

	/**
	 * Shitjet e vjetra që u regjistruan në WordPress para versionit 1.3.0 — kalohen te fleta një herë.
	 *
	 * @return int|WP_Error Sa u dërguan.
	 */
	public static function import_old() {
		global $wpdb;
		$t    = IAMP_DB::table( 'sales' );
		$rows = $wpdb->get_results( "SELECT * FROM $t ORDER BY id ASC" ); // phpcs:ignore
		if ( ! $rows ) {
			return 0;
		}
		$labels = array( 'pending' => 'Pret konfirmim', 'approved' => 'Konfirmuar dhe aktivizuar', 'rejected' => 'Refuzuar' );
		$out    = array();
		foreach ( $rows as $s ) {
			$pkg    = IAMP_DB::get_package( $s->package_id );
			$extras = array();
			foreach ( IAMP_DB::string_to_ids( $s->extra_courses ) as $cid ) {
				$extras[] = get_the_title( $cid );
			}
			$out[] = array(
				'id'            => (int) $s->id,
				'data'          => mysql2date( 'd.m.Y H:i', $s->created_at ),
				'trajneri'      => self::trainer_name( $s->trainer_id ),
				'trajneri_id'   => (int) $s->trainer_id,
				'klienti'       => $s->client_name,
				'email'         => $s->client_email,
				'telefoni'      => $s->client_phone,
				'kursi'         => $pkg ? $pkg->name : '',
				'paketa_id'     => (int) $s->package_id,
				'bonuset'       => implode( ', ', $extras ),
				'kurset_id'     => $s->extra_courses,
				'shuma'         => (float) $s->amount,
				'valuta'        => $s->currency,
				'pagesa'        => $s->payment_method,
				'referenca'     => $s->payment_ref,
				'data_pageses'  => $s->payment_date ? mysql2date( 'd.m.Y', $s->payment_date ) : '',
				'mori_trajneri' => $s->collected_by_trainer ? 'Po' : 'Jo',
				'bonus_pct'     => (float) $s->commission_pct,
				'tvsh'          => self::vat(),
				'statusi'       => isset( $labels[ $s->status ] ) ? $labels[ $s->status ] : $s->status,
				'shenime'       => trim( $s->note . ( $s->admin_note ? ' · ' . $s->admin_note : '' ) ),
				'deshmia'       => $s->proof_id ? wp_get_attachment_url( $s->proof_id ) : '',
				'aktivizuar'    => 'approved' === $s->status && $s->decided_at ? mysql2date( 'd.m.Y H:i', $s->decided_at ) : '',
			);
		}
		$r = self::send( array( 'action' => 'import', 'rows' => $out ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		update_option( 'iamp_sales_imported', count( $out ), false );
		self::flush_summary();
		return count( $out );
	}

	/** Sa shitje të vjetra kanë mbetur në tabelën e WordPress-it. */
	public static function old_count() {
		global $wpdb;
		$t = IAMP_DB::table( 'sales' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ); // phpcs:ignore
	}
}
