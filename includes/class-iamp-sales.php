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

	/** Vlerat e fushës "Kush e mori pagesën" që nuk janë trajnere me llogari bonusi. */
	public static function collector_extras() {
		return array(
			'company' => 'Kompania (Sun and Stars)',
			'avni'    => 'Avni Thaqi',
			'mimoza'  => 'Mimoza Thaqi',
		);
	}

	/** Zgjedhjet e plota: kompania, Avniu, Mimoza dhe çdo anëtare e Skuadrës (u:ID). */
	public static function collector_options() {
		$out   = self::collector_extras();
		$names = array_map( 'mb_strtolower', array_values( self::team() ) );
		foreach ( array( 'avni', 'mimoza' ) as $k ) {
			foreach ( $names as $n ) {
				if ( false !== mb_strpos( $n, $k ) ) { // Nëse është vetë në Skuadër, del vetëm një herë.
					unset( $out[ $k ] );
				}
			}
		}
		foreach ( self::team() as $uid => $name ) {
			$out[ 'u:' . (int) $uid ] = $name;
		}
		return $out;
	}

	/**
	 * Kush e mori paranë e klientit.
	 *
	 * @return array{label:string,id:int}|WP_Error id > 0 vetëm për anëtare të Skuadrës (paratë zbriten nga bonusi i saj).
	 */
	public static function collector( $value, $seller_id = 0 ) {
		$value = (string) $value;
		$extra = self::collector_extras();
		if ( isset( $extra[ $value ] ) ) {
			return array( 'label' => $extra[ $value ], 'id' => 0 );
		}
		if ( preg_match( '/^u:(\d+)$/', $value, $m ) && self::is_team( (int) $m[1] ) ) {
			return array( 'label' => self::trainer_name( (int) $m[1] ), 'id' => (int) $m[1] );
		}
		return new WP_Error( 'iamp_sale', 'Zgjidh kush e mori pagesën (te kompania apo te cila trajnere). Shitja nuk u regjistrua.' );
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
		if ( ! get_user_by( 'email', $data['client_email'] ) ) {
			return new WP_Error( 'iamp_sale', 'Nuk ekziston asnjë llogari në iampower.al me email-in ' . $data['client_email'] . '. Kontrollo email-in me klientin. Shitja nuk u regjistrua.' );
		}
		if ( ! self::sheet_ready() ) {
			return new WP_Error( 'iamp_sale', 'Regjistrimi i shitjeve nuk është lidhur ende. Njofto Mimozën.' );
		}
		$cb = self::collector( isset( $data['collected_by'] ) ? $data['collected_by'] : '', (int) $data['trainer_id'] );
		if ( is_wp_error( $cb ) ) {
			return $cb;
		}
		$pct    = (float) $pkg->commission_pct;
		$extras = array();
		$ids    = array_filter( array_map( 'absint', (array) $data['extra_courses'] ) );
		foreach ( $ids as $cid ) {
			$extras[] = html_entity_decode( wp_strip_all_tags( get_the_title( $cid ) ), ENT_QUOTES, 'UTF-8' );
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
			'mori'          => $cb['label'],
			'mori_id'       => $cb['id'],
			// Për kodin e vjetër të fletës: "Po" vetëm kur shitësi vetë e mori pagesën.
			'mori_trajneri' => $cb['id'] && (int) $cb['id'] === (int) $data['trainer_id'] ? 'Po' : 'Jo',
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
		// Apps Script përgjigjet me 302 drejt script.googleusercontent.com. WordPress-i e ndjek
		// ridrejtimin duke mbajtur POST-in, dhe Google kthen 400. Prandaj e ndjekim vetë me GET.
		$r = wp_remote_post(
			$url,
			array(
				'timeout'     => 25,
				'redirection' => 0,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		$hops = 0;
		while ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) && $hops < 5 ) {
			$loc = wp_remote_retrieve_header( $r, 'location' );
			if ( ! $loc ) {
				break;
			}
			$r = wp_remote_get( $loc, array( 'timeout' => 25, 'redirection' => 0 ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$code = (int) wp_remote_retrieve_response_code( $r );
			$hops++;
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
		$res = self::activate( $d );
		self::flush_summary();
		if ( is_wp_error( $res ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => $res->get_error_message() ) );
		}
		return new WP_REST_Response( array( 'ok' => true, 'membership_id' => (int) $res['membership_id'], 'duplicate' => ! empty( $res['duplicate'] ) ) );
	}

	/**
	 * Aktivizon klientin e një shitjeje (përdoret nga fleta dhe nga mësimi "Kërkesa për aktivizim").
	 * Është e sigurt të thirret dy herë: referenca "shitje:ID" parandalon dublimin.
	 *
	 * @param array $d Rreshti i shitjes (email, klienti, paketa_id, kurset_id, id, trajneri).
	 * @return array|WP_Error
	 */
	public static function activate( $d ) {
		$email = sanitize_email( isset( $d['email'] ) ? $d['email'] : '' );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'iamp_sale', 'Email-i i klientit nuk është i saktë.' );
		}
		$pkg = IAMP_DB::get_package( isset( $d['paketa_id'] ) ? (int) $d['paketa_id'] : 0 );
		if ( ! $pkg ) {
			return new WP_Error( 'iamp_sale', 'Paketa nuk u gjet në webfaqe.' );
		}
		$name    = trim( sanitize_text_field( isset( $d['klienti'] ) ? $d['klienti'] : '' ) );
		$parts   = explode( ' ', $name, 2 );
		$courses = array_filter( array_map( 'absint', explode( ',', isset( $d['kurset_id'] ) ? (string) $d['kurset_id'] : '' ) ) );
		// Kurset bonus gjenden edhe sipas titullit (kolona "Bonus falas shtesë"), sepse Google Sheets
		// ndonjëherë e kthen "12,34" në numër dhe humbet ID-të.
		$courses = array_merge( array_filter( $courses, function ( $c ) {
			return 'sfwd-courses' === get_post_type( $c );
		} ), self::courses_from_titles( isset( $d['bonuset'] ) ? (string) $d['bonuset'] : '' ) );
		$courses = array_values( array_unique( $courses ) );
		return IAMP_Access::grant(
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
	}

	/** Tekst i krahasueshëm: pa kode HTML, pa thonjëza të lakuara, shkronja të vogla. */
	private static function norm( $t ) {
		$t = html_entity_decode( wp_strip_all_tags( (string) $t ), ENT_QUOTES, 'UTF-8' );
		$t = str_replace( array( '“', '”', '„', '‘', '’' ), array( '"', '"', '"', "'", "'" ), $t );
		return trim( preg_replace( '/\s+/u', ' ', mb_strtolower( $t ) ) );
	}

	/** ID-të e kurseve që titujt e tyre shfaqen te teksti i bonuseve (titujt më të gjatë i përputh i pari). */
	public static function courses_from_titles( $text ) {
		$out  = array();
		$text = self::norm( $text );
		if ( '' === $text ) {
			return $out;
		}
		$list = array();
		foreach ( IAMP_Access::courses() as $c ) {
			$t = self::norm( $c->post_title );
			if ( '' !== $t ) {
				$list[] = array( (int) $c->ID, $t );
			}
		}
		usort( $list, function ( $x, $y ) {
			return mb_strlen( $y[1] ) - mb_strlen( $x[1] );
		} );
		foreach ( $list as $it ) {
			if ( false !== mb_strpos( $text, $it[1] ) ) {
				$out[] = $it[0];
				$text  = str_replace( $it[1], ' ', $text ); // Mos e numëro dy herë brenda një titulli më të gjatë.
			}
		}
		return $out;
	}

	/** Kërkesat që presin konfirmim, direkt nga fleta (pa cache, gjithmonë të freskëta). */
	public static function pending_requests() {
		if ( ! self::sheet_ready() ) {
			return new WP_Error( 'iamp_sheet', 'Fleta nuk është lidhur.' );
		}
		$r = self::send( array( 'action' => 'pending' ) );
		if ( is_wp_error( $r ) ) {
			if ( false !== strpos( $r->get_error_message(), 'panjohur' ) ) {
				return new WP_Error( 'iamp_sheet', 'Kodi i fletës duhet përditësuar (Shitjet.gs → Deploy → New version).' );
			}
			return $r;
		}
		return isset( $r['rows'] ) ? (array) $r['rows'] : array();
	}

	/**
	 * Konfirmon ose refuzon një kërkesë nga mësimi i administratorit.
	 *
	 * @param string $decision 'approve' ose 'reject'.
	 * @return true|WP_Error
	 */
	public static function decide( $id, $decision ) {
		$id   = absint( $id );
		$rows = self::pending_requests();
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$row = null;
		foreach ( $rows as $r ) {
			if ( (int) $r['id'] === $id ) {
				$row = (array) $r;
				break;
			}
		}
		if ( ! $row ) {
			return new WP_Error( 'iamp_sale', 'Kjo kërkesë nuk pret më konfirmim (ndoshta u trajtua).' );
		}
		if ( 'approve' === $decision ) {
			$res = self::activate( $row );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		$mark = self::send( array( 'action' => 'decide', 'id' => $id, 'vendimi' => 'approve' === $decision ? 'ok' : 'no' ) );
		self::flush_summary();
		if ( is_wp_error( $mark ) ) {
			return new WP_Error( 'iamp_sale', 'Klienti u aktivizua, por fleta nuk u përditësua: ' . $mark->get_error_message() . ' Vendos “Konfirmo” te fleta dorazi.' );
		}
		IAMP_Log::add( 'team', 0, ( 'approve' === $decision ? 'Konfirmuar' : 'Refuzuar' ) . ' shitja #' . $id . ' nga mësimi i administratorit' );
		return true;
	}

	/* ---------------- Përmbledhjet nga fleta ---------------- */

	public static function flush_summary() {
		delete_transient( 'iamp_sheet_sum' );
		update_option( 'iamp_sheet_list_v', time(), false ); // Lista e shitjeve të çdo trajneri lexohet sërish.
	}

	/**
	 * Shitjet e një trajneri, një nga një, të lexuara nga fleta (ruhen 10 minuta).
	 *
	 * @return array{rows:array,ok:bool,old_script?:bool}
	 */
	public static function trainer_sales( $trainer_id ) {
		$tid = (int) $trainer_id;
		$key = 'iamp_sl_' . $tid . '_' . (int) get_option( 'iamp_sheet_list_v', 0 );
		$c   = get_transient( $key );
		if ( is_array( $c ) ) {
			return $c;
		}
		if ( ! self::sheet_ready() ) {
			return array( 'rows' => array(), 'ok' => false );
		}
		$r = self::send( array( 'action' => 'list', 'trajneri_id' => $tid ) );
		if ( is_wp_error( $r ) ) {
			$out = array( 'rows' => array(), 'ok' => false );
			if ( false !== strpos( $r->get_error_message(), 'panjohur' ) ) {
				$out['old_script'] = true; // Kodi i fletës nuk është përditësuar ende.
			}
			set_transient( $key, $out, 2 * MINUTE_IN_SECONDS );
			return $out;
		}
		$out = array( 'rows' => isset( $r['rows'] ) ? (array) $r['rows'] : array(), 'ok' => true );
		set_transient( $key, $out, 10 * MINUTE_IN_SECONDS );
		return $out;
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
	 *  kept                    = bonusi i mbajtur (shitje të vjetra) + paratë e plota që ka marrë në dorë (received)
	 *  balance                 = opening + earned − kept − paid  (negative = i ka për t'i dorëzuar kompanisë)
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
		$received = $g( 'received' ); // Paratë e plota që ajo ka marrë në dorë nga klientët (zbriten nga bonusi).
		$kept     = $g( 'kept' ) + $received;
		return array(
			'received'   => $received,
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
			. 'Paguar: ' . number_format( (float) $b['shuma'], 2 ) . ' ' . $b['valuta'] . ' · ' . $b['pagesa'] . "\n"
			. 'Paratë i mori: ' . ( isset( $b['mori'] ) ? $b['mori'] : '—' ) . "\n\n"
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
				$extras[] = html_entity_decode( wp_strip_all_tags( get_the_title( $cid ) ), ENT_QUOTES, 'UTF-8' );
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
