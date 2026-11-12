<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kontaktet e Skuadrës: lista e përbashkët, ndarja automatike mes trajnereve,
 * dhe historiku i çdo kontakti (çfarë i është shkruar, çfarë ka përgjigjur).
 *
 *  [iam_kontaktet_e_mia] — kontaktet e trajneres së kyçur me butonin WhatsApp, statusin dhe shënimet
 *  [iam_kontakt_i_ri]    — forma për të shtuar një kontakt të ri (ndahet vetë te trajnerja përkatëse)
 */
class IAMP_Leads {

	const DB_VERSION = '1.1.0';

	public static function statuses() {
		return array(
			'pa_kontaktuar'  => 'Pa kontaktuar',
			'nuk_u_pergjigj' => 'Nuk u përgjigj',
			'foli'           => 'Foli',
			'rikontakto'     => 'Rikontakto më vonë',
			'bleu'           => 'Bleu',
			'nuk_interesohet' => 'Nuk është i interesuar',
		);
	}

	/** Statuset që e mbyllin kontaktin (nuk dalin si "të vonuara"). */
	private static function closed() {
		return array( 'bleu', 'nuk_interesohet' );
	}

	public static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'iamp_' . $name;
	}

	public static function init() {
		add_shortcode( 'iam_kontaktet_e_mia', array( __CLASS__, 'panel' ) );
		add_shortcode( 'iam_kontakt_i_ri', array( __CLASS__, 'new_form' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_post' ) );
		add_action( 'wp_ajax_iamp_lead_wa', array( __CLASS__, 'ajax_wa' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install' ), 20 );
		add_action( 'iamp_daily_check', array( __CLASS__, 'daily_digest' ), 30 );
		add_action( 'iamp_membership_activated', array( __CLASS__, 'on_activated' ), 20, 2 );
	}

	/* ---------------- Tabelat ---------------- */

	public static function maybe_install() {
		if ( get_option( 'iamp_leads_db_version' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$l       = self::t( 'leads' );
		$e       = self::t( 'lead_events' );

		dbDelta( "CREATE TABLE $l (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL DEFAULT '',
			email VARCHAR(190) NOT NULL DEFAULT '',
			phone VARCHAR(40) NOT NULL DEFAULT '',
			phone_key VARCHAR(20) NOT NULL DEFAULT '',
			original_sponsor VARCHAR(120) NOT NULL DEFAULT '',
			category VARCHAR(80) NOT NULL DEFAULT '',
			source VARCHAR(190) NOT NULL DEFAULT '',
			owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(30) NOT NULL DEFAULT 'pa_kontaktuar',
			urgent TINYINT(1) NOT NULL DEFAULT 0,
			last_contact_at DATETIME NULL,
			next_followup DATE NULL,
			note TEXT NULL,
			created_at DATETIME NOT NULL,
			assigned_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY owner_id (owner_id),
			KEY status (status),
			KEY urgent (urgent),
			KEY phone_key (phone_key),
			KEY email (email)
		) $charset;" );

		dbDelta( "CREATE TABLE $e (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			lead_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			kind VARCHAR(20) NOT NULL DEFAULT 'note',
			status VARCHAR(30) NULL,
			text TEXT NULL,
			PRIMARY KEY  (id),
			KEY lead_id (lead_id)
		) $charset;" );

		update_option( 'iamp_leads_db_version', self::DB_VERSION, false );
	}

	/* ---------------- Ndihmës ---------------- */

	public static function norm_phone( $p ) {
		$p = (string) $p;
		if ( preg_match( '/e\+/i', $p ) ) {
			return ''; // Excel e ka prerë numrin.
		}
		$d = preg_replace( '/\D+/', '', $p );
		$d = preg_replace( '/^00/', '', $d ); // 0049… → 49… (wa.me kërkon pa 00)
		if ( strlen( $d ) < 7 || strlen( $d ) > 15 ) {
			return '';
		}
		return $d;
	}

	public static function phone_key( $digits ) {
		return $digits ? substr( $digits, -9 ) : '';
	}

	public static function norm_email( $e ) {
		foreach ( preg_split( '/[\s;,]+/', strtolower( trim( (string) $e ) ) ) as $t ) {
			if ( is_email( $t ) ) {
				return $t;
			}
		}
		return '';
	}

	public static function get( $id ) {
		global $wpdb;
		$t = self::t( 'leads' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) ); // phpcs:ignore
	}

	public static function find_duplicate( $email, $phone_key ) {
		global $wpdb;
		$t = self::t( 'leads' );
		if ( $email ) {
			$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE email = %s LIMIT 1", $email ) ); // phpcs:ignore
			if ( $r ) {
				return $r;
			}
		}
		if ( $phone_key ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE phone_key = %s LIMIT 1", $phone_key ) ); // phpcs:ignore
		}
		return null;
	}

	/** Trajneret aktive: user_id => emri. */
	public static function trainers() {
		return IAMP_Sales::team();
	}

	/** Pesha e ndarjes për një trajnere (sipas emrit); pa peshë = 0. */
	public static function weights() {
		$w = IAMP_Settings::get( 'lead_weights' );
		if ( ! is_array( $w ) || ! $w ) {
			$w = array( 'teuta' => 3, 'leonora' => 3, 'zejnepe' => 2, 'alda' => 2, 'anila' => 2 );
		}
		return $w;
	}

	private static function weight_for( $name ) {
		$w = self::weights();
		$k = strtolower( trim( $name ) );
		return isset( $w[ $k ] ) ? max( 0.0, (float) $w[ $k ] ) : 0.0; // Pa peshë = nuk merr kontakte të reja (p.sh. Avni, Mimoza).
	}

	/** Gjen user_id të trajneres sipas emrit (p.sh. "Teuta"). */
	public static function trainer_by_name( $name ) {
		$name = strtolower( trim( (string) $name ) );
		if ( '' === $name ) {
			return 0;
		}
		foreach ( self::trainers() as $uid => $tname ) {
			if ( strtolower( trim( $tname ) ) === $name ) {
				return (int) $uid;
			}
		}
		return 0;
	}

	/**
	 * Zgjedh trajneren për një kontakt pa sponsor aktiv: ajo që është më larg
	 * nga pesha e saj (numri i kontakteve të ndara / pesha është më i vogli).
	 */
	public static function pick_owner() {
		global $wpdb;
		$t      = self::t( 'leads' );
		$best   = 0;
		$best_r = null;
		foreach ( self::trainers() as $uid => $tname ) {
			$w = self::weight_for( $tname );
			if ( $w <= 0 ) {
				continue;
			}
			$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE owner_id = %d AND original_sponsor NOT IN ( %s )", $uid, strtolower( trim( $tname ) ) ) ); // phpcs:ignore
			$r = $n / $w;
			if ( null === $best_r || $r < $best_r ) {
				$best_r = $r;
				$best   = (int) $uid;
			}
		}
		return $best;
	}

	/**
	 * Krijon kontaktin dhe e cakton te trajnerja.
	 *
	 * @param array $d name,email,phone,sponsor,category,source,owner (emri i trajneres, opsionale),status,last_contact,note
	 * @return array|WP_Error array( 'id'=>, 'owner'=>, 'duplicate'=>bool )
	 */
	public static function create( $d, $notify = true ) {
		global $wpdb;
		$email = self::norm_email( isset( $d['email'] ) ? $d['email'] : '' );
		$phone = self::norm_phone( isset( $d['phone'] ) ? $d['phone'] : '' );
		$key   = self::phone_key( $phone );
		if ( ! $email && ! $phone ) {
			return new WP_Error( 'iamp_lead', 'Duhet të paktën telefoni ose email-i.' );
		}
		$dup = self::find_duplicate( $email, $key );
		if ( $dup ) {
			return array( 'id' => (int) $dup->id, 'owner' => (int) $dup->owner_id, 'duplicate' => true );
		}

		$sponsor = isset( $d['sponsor'] ) ? sanitize_text_field( $d['sponsor'] ) : '';
		$owner   = 0;
		if ( ! empty( $d['owner'] ) ) {
			$owner = self::trainer_by_name( $d['owner'] );
		}
		if ( ! $owner && $sponsor ) {
			$owner = self::trainer_by_name( $sponsor );
		}
		if ( ! $owner ) {
			$owner = self::pick_owner();
		}

		$status = isset( $d['status'] ) && isset( self::statuses()[ $d['status'] ] ) ? $d['status'] : 'pa_kontaktuar';
		$now    = IAMP_DB::now();
		$wpdb->insert(
			self::t( 'leads' ),
			array(
				'name'             => sanitize_text_field( isset( $d['name'] ) ? $d['name'] : '' ),
				'email'            => $email,
				'phone'            => $phone,
				'phone_key'        => $key,
				'original_sponsor' => strtolower( $sponsor ),
				'category'         => sanitize_text_field( isset( $d['category'] ) ? $d['category'] : '' ),
				'source'           => sanitize_text_field( isset( $d['source'] ) ? $d['source'] : '' ),
				'owner_id'         => $owner,
				'status'           => $status,
				// Kontakti i ri hyn si URGJENT te trajnerja (importi masiv dërgon 'urgent' => 0).
				'urgent'           => ( isset( $d['urgent'] ) && ! $d['urgent'] ) || 'pa_kontaktuar' !== $status ? 0 : 1,
				'last_contact_at'  => ! empty( $d['last_contact'] ) ? $d['last_contact'] : null,
				'note'             => isset( $d['note'] ) ? sanitize_textarea_field( $d['note'] ) : '',
				'created_at'       => $now,
				'assigned_at'      => $now,
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( $id && $owner ) {
			self::add_event( $id, 0, 'assign', null, 'U ndanë te ' . IAMP_Sales::trainer_name( $owner ) );
			if ( $notify ) {
				self::notify_owner( $owner, array( $id ) );
			}
		}
		return array( 'id' => $id, 'owner' => $owner, 'duplicate' => false );
	}

	public static function add_event( $lead_id, $user_id, $kind, $status = null, $text = '' ) {
		global $wpdb;
		$wpdb->insert(
			self::t( 'lead_events' ),
			array(
				'lead_id'    => (int) $lead_id,
				'user_id'    => (int) $user_id,
				'created_at' => IAMP_DB::now(),
				'kind'       => $kind,
				'status'     => $status,
				'text'       => $text,
			)
		);
	}

	public static function events( $lead_id ) {
		global $wpdb;
		$t = self::t( 'lead_events' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE lead_id = %d ORDER BY id DESC LIMIT 100", $lead_id ) ); // phpcs:ignore
	}

	public static function reassign( $lead_id, $new_owner, $actor ) {
		global $wpdb;
		$wpdb->update( self::t( 'leads' ), array( 'owner_id' => (int) $new_owner, 'assigned_at' => IAMP_DB::now(), 'urgent' => 1 ), array( 'id' => (int) $lead_id ) );
		self::add_event( $lead_id, $actor, 'assign', null, 'U kalua te ' . IAMP_Sales::trainer_name( $new_owner ) );
		self::notify_owner( $new_owner, array( (int) $lead_id ) );
	}

	/** Regjistron një rezultat kontakti: status + çfarë u tha/çfarë u përgjigj. */
	public static function log_contact( $lead_id, $user_id, $status, $text, $followup = '' ) {
		global $wpdb;
		if ( ! isset( self::statuses()[ $status ] ) ) {
			$status = 'pa_kontaktuar';
		}
		$row = array(
			'status'          => $status,
			'urgent'          => 0, // Sapo trajnerja e prek kontaktin, nuk është më urgjent.
			'last_contact_at' => IAMP_DB::now(),
			'next_followup'   => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $followup ) ? $followup : null,
		);
		$wpdb->update( self::t( 'leads' ), $row, array( 'id' => (int) $lead_id ) );
		self::add_event( $lead_id, $user_id, 'contact', $status, $text );
		IAMP_Log::add( 'lead_contact', $user_id, array( 'kontakti' => (int) $lead_id, 'statusi' => $status ) );
	}

	/**
	 * Anëtari i ri që aktivizohet (nga Mimoza/Avni, me listë në grup, kod ose webhook)
	 * hyn vetë te Kontaktet dhe ndahet te trajnerja. Shitjet e trajnereve mbeten te trajnerja që shiti.
	 */
	public static function on_activated( $membership_id, $user_id ) {
		if ( ! IAMP_Settings::get( 'lead_from_activations' ) ) {
			return;
		}
		$m    = IAMP_DB::get_membership( $membership_id );
		$user = get_userdata( $user_id );
		if ( ! $m || ! $user ) {
			return;
		}
		$sponsor = '';
		if ( 'shitje' === $m->source && preg_match( '/Trajneri:\s*(.+)$/u', (string) $m->note, $mm ) ) {
			$sponsor = trim( $mm[1] );
		}
		$name  = trim( $user->first_name . ' ' . $user->last_name );
		$phone = (string) get_user_meta( $user_id, 'billing_phone', true );
		$r     = self::create(
			array(
				'name'     => $name ? $name : $user->display_name,
				'email'    => $user->user_email,
				'phone'    => $phone,
				'sponsor'  => $sponsor,
				'category' => 'Anëtar i ri',
				'source'   => 'Aktivizim (' . $m->source . ')',
			),
			'' === $sponsor // Shitjet e trajnereve nuk dërgojnë email për çdo shitje.
		);
		if ( ! is_wp_error( $r ) && ! empty( $r['duplicate'] ) ) {
			self::add_event( $r['id'], 0, 'note', null, 'U aktivizua përsëri në iampower.al (' . $m->source . ')' );
		}
	}


	/**
	 * Nxjerr kontaktet nga teksti i ngjitur nga grupi në WhatsApp.
	 * Një kontakt për rresht (emër + numër), ose një bllok me rreshta të ndarë me rresht bosh.
	 *
	 * @return array[] Lista me name, phone, email.
	 */
	public static function parse_text( $text ) {
		$text = preg_replace( '/\[\d{1,2}[\/.]\d{1,2}[\/.]\d{2,4},?\s*\d{1,2}:\d{2}(:\d{2})?\]\s*[^:]{1,60}:\s*/u', '', (string) $text ); // "[12/11/26, 10:15] Mimoza:"
		$text = preg_replace( '/^\d{1,2}[\/.]\d{1,2}[\/.]\d{2,4},?\s*\d{1,2}:\d{2}\s*-\s*[^:]{1,60}:\s*/mu', '', $text ); // "12/11/26, 10:15 - Mimoza:"
		$blocks = preg_split( '/\R\s*\R/u', trim( $text ) );
		$out    = array();
		$phone_re = '/(\+?\d[\d\s().\-]{6,}\d)/u';
		foreach ( $blocks as $block ) {
			$lines = array_values( array_filter( array_map( 'trim', preg_split( '/\R/u', $block ) ), 'strlen' ) );
			if ( ! $lines ) {
				continue;
			}
			$per_line = count( $lines ) > 1;
			foreach ( $lines as $ln ) {
				if ( ! preg_match( $phone_re, $ln ) && ! self::norm_email( $ln ) ) {
					$per_line = false;
				}
			}
			$groups = $per_line ? array_map( function ( $l ) {
				return array( $l );
			}, $lines ) : array( $lines );
			foreach ( $groups as $g ) {
				$joined = implode( ' ', $g );
				$phone  = '';
				if ( preg_match( $phone_re, $joined, $m ) ) {
					$phone = self::norm_phone( $m[1] );
				}
				$email = self::norm_email( $joined );
				if ( ! $phone && ! $email ) {
					continue;
				}
				$name = preg_replace( $phone_re, ' ', $joined );
				$name = preg_replace( '/\S+@\S+/u', ' ', $name );
				$name = preg_replace( '/\b(emri|mbiemri|tel(efoni)?|nr|numri|email|e-mail|whatsapp|viber|instagram|facebook|nga|klient\w*|kontakt\w*|i ri|e re|të rinj)\b\s*[:\-]?/iu', ' ', $name );
				$name = trim( preg_replace( '/[\s,;:|\-–—]+/u', ' ', $name ) );
				$out[] = array( 'name' => mb_substr( $name, 0, 120 ), 'phone' => $phone, 'email' => $email );
			}
		}
		return $out;
	}

	/** Ngjit mesazhin nga grupi → krijon kontaktet dhe i ndan. */
	public static function paste_import( $text, $sponsor_name = '' ) {
		$items = self::parse_text( $text );
		$by    = array();
		$lines = array();
		$new   = 0;
		foreach ( $items as $it ) {
			$r = self::create(
				array(
					'name'     => $it['name'],
					'phone'    => $it['phone'],
					'email'    => $it['email'],
					'sponsor'  => $sponsor_name,
					'category' => 'Klient i ri',
					'source'   => 'Grupi WhatsApp',
				),
				false
			);
			$label = ( $it['name'] ? $it['name'] : ( $it['phone'] ? '+' . $it['phone'] : $it['email'] ) );
			if ( is_wp_error( $r ) ) {
				continue;
			}
			if ( $r['duplicate'] ) {
				$lines[] = $label . ' → ekziston, është te ' . IAMP_Sales::trainer_name( $r['owner'] );
			} else {
				$new++;
				$by[ $r['owner'] ][] = $r['id'];
				$lines[] = $label . ' → ' . IAMP_Sales::trainer_name( $r['owner'] );
			}
		}
		foreach ( $by as $uid => $ids ) {
			self::notify_owner( $uid, $ids );
		}
		return array( 'new' => $new, 'found' => count( $items ), 'lines' => $lines );
	}

	/** Një email në ditë për trajnere: kontaktet e vonuara dhe rikontaktet e sotme. */
	public static function daily_digest() {
		if ( get_option( 'iamp_leads_digest_day' ) === current_time( 'Y-m-d' ) || (int) current_time( 'G' ) < 8 ) {
			return;
		}
		update_option( 'iamp_leads_digest_day', current_time( 'Y-m-d' ), false );
		global $wpdb;
		$t    = self::t( 'leads' );
		$page = (string) get_option( 'iamp_leads_page_url', '' );
		foreach ( self::stats() as $uid => $s ) {
			$today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE owner_id = %d AND next_followup = %s AND status NOT IN ('bleu','nuk_interesohet')", $uid, current_time( 'Y-m-d' ) ) ); // phpcs:ignore
			if ( ! $s['overdue'] && ! $today && ! $s['new'] && ! $s['urgent'] ) {
				continue;
			}
			$user = get_userdata( $uid );
			if ( ! $user ) {
				continue;
			}
			$msg = 'Përshëndetje ' . $s['name'] . ",\n\nKujtesë për sot:\n";
			$msg .= ( $s['urgent'] ? '• ⚠ URGJENT (kontakte të reja): ' . (int) $s['urgent'] . "\n" : '' ) . '• Pa kontaktuar ende: ' . (int) $s['new'] . "\n• Të vonuara: " . (int) $s['overdue'] . "\n• Për t'u rikontaktuar sot: " . $today . "\n";
			$msg .= $page ? "\nHap listën: " . $page : '';
			wp_mail( $user->user_email, 'IAM Power: kontaktet për sot', $msg . "\n\nEkipi IAM Power" );
		}
	}

	/* ---------------- Njoftimi me email ---------------- */

	public static function notify_owner( $owner_id, $lead_ids ) {
		$user = get_userdata( $owner_id );
		if ( ! $user || ! $lead_ids ) {
			return;
		}
		$n    = count( $lead_ids );
		$page = (string) get_option( 'iamp_leads_page_url', '' );
		$msg  = 'Përshëndetje ' . IAMP_Sales::trainer_name( $owner_id ) . ",\n\n⚠ URGJENT — kontakt i ri që pret përgjigje. Shkruaji sa më shpejt.\n\n";
		if ( 1 === $n ) {
			$l    = self::get( (int) $lead_ids[0] );
			$msg .= 'Të erdhi një kontakt i ri:' . "\n" . ( $l && $l->name ? $l->name : 'pa emër' ) . ( $l && $l->phone ? ' · +' . $l->phone : '' ) . "\n\n";
		} else {
			$msg .= "Të erdhën $n kontakte të reja.\n\n";
		}
		$msg .= 'Hap listën tënde, shkruaji në WhatsApp me butonin dhe shëno çfarë ka ndodhur' . ( $page ? ":\n" . $page : ' te "Kontaktet e mia" në kursin Skuadra IAM Power.' ) . "\n\nEkipi IAM Power";
		$subj = 1 === $n ? 'URGJENT — IAM Power: kontakt i ri për ty' : "URGJENT — IAM Power: $n kontakte të reja për ty";
		wp_mail( $user->user_email, $subj, $msg );
	}

	/* ---------------- Statistika ---------------- */

	public static function stats() {
		global $wpdb;
		$t   = self::t( 'leads' );
		$out = array();
		foreach ( self::trainers() as $uid => $name ) {
			$out[ $uid ] = array( 'name' => $name, 'total' => 0, 'new' => 0, 'noans' => 0, 'talked' => 0, 'follow' => 0, 'bought' => 0, 'no' => 0, 'overdue' => 0, 'urgent' => 0 );
		}
		$rows = $wpdb->get_results( "SELECT owner_id, status, COUNT(*) c FROM $t GROUP BY owner_id, status" ); // phpcs:ignore
		$map  = array( 'pa_kontaktuar' => 'new', 'nuk_u_pergjigj' => 'noans', 'foli' => 'talked', 'rikontakto' => 'follow', 'bleu' => 'bought', 'nuk_interesohet' => 'no' );
		foreach ( $rows as $r ) {
			if ( ! isset( $out[ $r->owner_id ] ) ) {
				continue;
			}
			$out[ $r->owner_id ]['total'] += (int) $r->c;
			if ( isset( $map[ $r->status ] ) ) {
				$out[ $r->owner_id ][ $map[ $r->status ] ] += (int) $r->c;
			}
		}
		foreach ( $wpdb->get_results( "SELECT owner_id, COUNT(*) c FROM $t WHERE urgent = 1 GROUP BY owner_id" ) as $r ) { // phpcs:ignore
			if ( isset( $out[ $r->owner_id ] ) ) {
				$out[ $r->owner_id ]['urgent'] = (int) $r->c;
			}
		}
		$days = (int) IAMP_Settings::get( 'lead_overdue_days' );
		$days = $days > 0 ? $days : 5;
		$over = $wpdb->get_results( $wpdb->prepare( "SELECT owner_id, COUNT(*) c FROM $t WHERE status NOT IN ('bleu','nuk_interesohet') AND ( ( last_contact_at IS NULL AND assigned_at < %s ) OR ( last_contact_at IS NOT NULL AND last_contact_at < %s ) OR ( next_followup IS NOT NULL AND next_followup < %s ) ) GROUP BY owner_id", gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS ), current_time( 'Y-m-d' ) ) ); // phpcs:ignore
		foreach ( $over as $r ) {
			if ( isset( $out[ $r->owner_id ] ) ) {
				$out[ $r->owner_id ]['overdue'] = (int) $r->c;
			}
		}
		return $out;
	}

	/** Importi nga CSV (kolonat sipas titujve të fletës "Master"). */
	public static function import_csv( $path ) {
		$fh = fopen( $path, 'r' ); // phpcs:ignore
		if ( ! $fh ) {
			return new WP_Error( 'iamp_lead', 'File nuk u hap.' );
		}
		$head = fgetcsv( $fh );
		if ( ! $head ) {
			fclose( $fh ); // phpcs:ignore
			return new WP_Error( 'iamp_lead', 'File është bosh.' );
		}
		$head[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $head[0] );
		$idx     = array();
		foreach ( $head as $i => $h ) {
			$idx[ strtolower( trim( $h ) ) ] = $i;
		}
		$col = function ( $row, $names ) use ( $idx ) {
			foreach ( (array) $names as $n ) {
				if ( isset( $idx[ $n ] ) && isset( $row[ $idx[ $n ] ] ) ) {
					return trim( $row[ $idx[ $n ] ] );
				}
			}
			return '';
		};
		$stat_rev = array();
		foreach ( self::statuses() as $k => $label ) {
			$stat_rev[ strtolower( $label ) ] = $k;
		}
		// Statuset e vjetra nga listat e trajnereve → statuset e reja.
		$stat_rev = array_merge(
			$stat_rev,
			array(
				'kontaktuar'  => 'foli',
				'konfirmuar'  => 'bleu',
				'blere'       => 'bleu',
				'blerë'       => 'bleu',
				'nuk reagoi'  => 'nuk_u_pergjigj',
			)
		);
		$done = array( 'created' => 0, 'duplicate' => 0, 'skipped' => 0 );
		$by   = array();
		while ( ( $row = fgetcsv( $fh ) ) !== false ) { // phpcs:ignore
			$phone = preg_replace( '/\s*;\s*.*/', '', $col( $row, 'telefon' ) );
			$sp    = $col( $row, 'sponsori origjinal' );
			$st    = strtolower( $col( $row, 'statusi i kontaktit' ) );
			$lc    = $col( $row, 'data e kontaktit' );
			$lc_db = null;
			if ( preg_match( '/^(\d{2})\.(\d{2})\.(\d{4})$/', $lc, $m ) ) {
				$lc_db = "{$m[3]}-{$m[2]}-{$m[1]} 00:00:00";
			}
			$r = self::create(
				array(
					'name'         => $col( $row, 'emri' ),
					'email'        => $col( $row, 'email' ),
					'phone'        => $phone,
					'sponsor'      => '—' === $sp ? '' : $sp,
					'category'     => $col( $row, 'kategoria' ),
					'source'       => $col( $row, 'burimi' ),
					'owner'        => $col( $row, 'pronari i ri (propozim)' ),
					'status'       => isset( $stat_rev[ $st ] ) ? $stat_rev[ $st ] : 'pa_kontaktuar',
					'last_contact' => $lc_db,
					'note'         => $col( $row, 'shënime' ),
					'urgent'       => 0,
				),
				false
			);
			if ( is_wp_error( $r ) ) {
				$done['skipped']++;
			} elseif ( $r['duplicate'] ) {
				$done['duplicate']++;
			} else {
				$done['created']++;
				if ( $r['owner'] ) {
					$by[ $r['owner'] ][] = $r['id'];
				}
			}
		}
		fclose( $fh ); // phpcs:ignore
		// Një email për trajnere, jo një për çdo kontakt.
		foreach ( $by as $uid => $ids ) {
			self::notify_owner( $uid, $ids );
		}
		IAMP_Log::add( 'lead_import', 0, $done );
		return $done;
	}

	/* ---------------- Veprimet nga faqja ---------------- */

	public static function can_use() {
		return is_user_logged_in() && ( IAMP_Sales::is_team() || current_user_can( 'manage_options' ) );
	}

	public static function handle_post() {
		if ( empty( $_POST['iamp_lead_action'] ) || ! self::can_use() ) {
			return;
		}
		$uid = get_current_user_id();
		$act = sanitize_key( wp_unslash( $_POST['iamp_lead_action'] ) );
		if ( ! isset( $_POST['iamp_lead_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['iamp_lead_nonce'] ) ), 'iamp_lead' ) ) {
			set_transient( 'iamp_lead_msg_' . $uid, array( 'err', 'Sesioni skadoi. Provo sërish.' ), 120 );
			return;
		}
		$p = function ( $k ) {
			return isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : ''; // phpcs:ignore
		};

		if ( 'contact' === $act ) {
			$lead = self::get( absint( $p( 'lead_id' ) ) );
			if ( ! $lead || ( (int) $lead->owner_id !== $uid && ! current_user_can( 'manage_options' ) ) ) {
				set_transient( 'iamp_lead_msg_' . $uid, array( 'err', 'Ky kontakt nuk është i yti.' ), 120 );
			} else {
				$text = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : ''; // phpcs:ignore
				self::log_contact( $lead->id, $uid, $p( 'status' ), $text, $p( 'followup' ) );
				set_transient( 'iamp_lead_msg_' . $uid, array( 'ok', 'U ruajt.' ), 120 );
			}
		} elseif ( 'new' === $act ) {
			$r = self::create(
				array(
					'name'    => $p( 'name' ),
					'email'   => $p( 'email' ),
					'phone'   => $p( 'phone' ),
					'sponsor' => IAMP_Sales::is_team() && 'me' === $p( 'mine' ) ? IAMP_Sales::trainer_name( $uid ) : '',
					'source'  => $p( 'source' ),
					'note'    => isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '', // phpcs:ignore
				)
			);
			if ( is_wp_error( $r ) ) {
				set_transient( 'iamp_lead_msg_' . $uid, array( 'err', $r->get_error_message() ), 120 );
			} elseif ( $r['duplicate'] ) {
				set_transient( 'iamp_lead_msg_' . $uid, array( 'err', 'Ky kontakt ekziston tashmë dhe është te ' . IAMP_Sales::trainer_name( $r['owner'] ) . '.' ), 120 );
			} else {
				set_transient( 'iamp_lead_msg_' . $uid, array( 'ok', 'Kontakti u shtua dhe u ndanë te ' . IAMP_Sales::trainer_name( $r['owner'] ) . '.' ), 120 );
			}
		} elseif ( 'paste' === $act ) {
			$text = isset( $_POST['paste'] ) ? sanitize_textarea_field( wp_unslash( $_POST['paste'] ) ) : ''; // phpcs:ignore
			$res  = self::paste_import( $text, IAMP_Sales::is_team() && 'me' === $p( 'mine' ) ? IAMP_Sales::trainer_name( $uid ) : '' );
			if ( ! $res['found'] ) {
				set_transient( 'iamp_lead_msg_' . $uid, array( 'err', 'Nuk gjeta asnjë numër telefoni ose email në tekst.' ), 120 );
			} else {
				set_transient( 'iamp_lead_msg_' . $uid, array( 'ok', 'U shtuan ' . $res['new'] . ' nga ' . $res['found'] . ' kontakte: ' . implode( ' · ', $res['lines'] ) ), 120 );
			}
		} elseif ( 'reassign' === $act && current_user_can( 'manage_options' ) ) {
			$new = absint( $p( 'owner' ) );
			if ( $new && isset( self::trainers()[ $new ] ) ) {
				self::reassign( absint( $p( 'lead_id' ) ), $new, $uid );
				set_transient( 'iamp_lead_msg_' . $uid, array( 'ok', 'Kontakti u kalua.' ), 120 );
			}
		}
		$back = isset( $_POST['iamp_back'] ) ? esc_url_raw( wp_unslash( $_POST['iamp_back'] ) ) : ''; // phpcs:ignore
		if ( ! $back ) {
			$back = wp_get_referer() ? wp_get_referer() : (string) get_option( 'iamp_leads_page_url', '' );
		}
		wp_safe_redirect( remove_query_arg( 'x', wp_validate_redirect( $back, home_url() ) ) );
		exit;
	}

	/** Fusha e fshehur me adresën e faqes aktuale, që pas ruajtjes të kthehesh aty (pa u varur nga referrer-i i shfletuesit). */
	private static function back_field() {
		$url = ( is_ssl() ? 'https://' : 'http://' ) . ( isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '' ) . ( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '' ); // phpcs:ignore
		return '<input type="hidden" name="iamp_back" value="' . esc_attr( esc_url_raw( $url ) ) . '">';
	}

	/** Regjistron hapjen e WhatsApp-it (nuk ndryshon statusin). */
	public static function ajax_wa() {
		if ( ! self::can_use() || ! check_ajax_referer( 'iamp_lead', 'n', false ) ) {
			wp_send_json_error();
		}
		$lead = self::get( isset( $_POST['lead_id'] ) ? absint( $_POST['lead_id'] ) : 0 ); // phpcs:ignore
		if ( $lead && ( (int) $lead->owner_id === get_current_user_id() || current_user_can( 'manage_options' ) ) ) {
			self::add_event( $lead->id, get_current_user_id(), 'wa', null, 'U hap WhatsApp' );
			wp_send_json_success();
		}
		wp_send_json_error();
	}

	/* ---------------- Faqet ---------------- */

	private static function msg() {
		$m = get_transient( 'iamp_lead_msg_' . get_current_user_id() );
		if ( ! $m ) {
			return '';
		}
		delete_transient( 'iamp_lead_msg_' . get_current_user_id() );
		return '<div class="iamt-msg ' . ( 'ok' === $m[0] ? 'iamt-ok' : 'iamt-err' ) . '">' . esc_html( $m[1] ) . '</div>';
	}

	private static function guard() {
		if ( ! is_user_logged_in() ) {
			return '<div class="iamt"><div class="iamt-msg iamt-err">Kyçu me llogarinë tënde të Skuadrës.</div></div>';
		}
		if ( ! self::can_use() ) {
			return '<div class="iamt"><div class="iamt-msg iamt-err">Kjo faqe është vetëm për Skuadrën IAM Power.</div></div>';
		}
		return '';
	}

	private static function css() {
		return IAMP_Team_Front::css_public() . '<style>
.iaml-bar{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 14px}
.iaml-bar a{padding:7px 13px;border-radius:20px;border:1px solid #d5ccb8;background:#fffdf8;color:#0b1a33;text-decoration:none;font-size:14px}
.iaml-bar a.on{background:#0b1a33;color:#fff;border-color:#0b1a33}
.iaml-lead{border:1px solid #e6dfcf;border-radius:12px;padding:14px 16px;margin:0 0 12px;background:#fff}
.iaml-lead.late{border-left:5px solid #c0392b}
.iaml-lead.urg{border:2px solid #c0392b;background:#fff6f4;border-left-width:8px}
.iaml-tag.urg{background:#c0392b;color:#fff;font-weight:700;animation:iamlp 1.6s infinite}
@keyframes iamlp{50%{opacity:.55}}
.iaml-alert{background:#c0392b;color:#fff;padding:12px 16px;border-radius:10px;margin:0 0 14px;font-weight:600}
.iaml-alert a{color:#fff;text-decoration:underline}
.iaml-top{display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px;align-items:center}
.iaml-top strong{font-size:16px;color:#0b1a33}
.iaml-meta{color:#6b6657;font-size:13px;margin:2px 0 8px}
.iaml-wa{display:inline-block;background:#25d366;color:#fff!important;padding:8px 14px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px}
.iaml-tag{display:inline-block;padding:2px 9px;border-radius:12px;font-size:12px;background:#f1ead8;color:#0b1a33;margin-left:6px}
.iaml-tag.late{background:#fbe3df;color:#8a241a}
.iaml-lead form{display:grid;grid-template-columns:1fr 1fr;gap:8px 12px;margin-top:10px}
.iaml-lead form textarea{grid-column:1/3;min-height:60px}
.iaml-lead details{margin-top:8px;font-size:13px}
.iaml-ev{border-top:1px dashed #e6dfcf;padding:6px 0}
@media(max-width:640px){.iaml-lead form{grid-template-columns:1fr}.iaml-lead form textarea{grid-column:1}}
</style>';
	}

	private static function wa_link( $lead, $trainer_name ) {
		if ( ! $lead->phone ) {
			return '';
		}
		$tpl = (string) IAMP_Settings::get( 'lead_wa_template' );
		if ( '' === $tpl ) {
			$tpl = 'Përshëndetje {emri}, jam {trajnerja} nga IAM Power Academy.';
		}
		$first = $lead->name ? preg_split( '/\s+/', trim( $lead->name ) )[0] : '';
		$text  = strtr( $tpl, array( '{emri}' => $first, '{trajnerja}' => $trainer_name ) );
		return 'https://wa.me/' . $lead->phone . '?text=' . rawurlencode( trim( $text ) );
	}

	private static function is_late( $lead ) {
		if ( in_array( $lead->status, self::closed(), true ) ) {
			return false;
		}
		$days  = (int) IAMP_Settings::get( 'lead_overdue_days' );
		$days  = $days > 0 ? $days : 5;
		$limit = current_time( 'timestamp' ) - $days * DAY_IN_SECONDS;
		if ( $lead->next_followup && strtotime( $lead->next_followup ) < strtotime( current_time( 'Y-m-d' ) ) ) {
			return true;
		}
		$ref = $lead->last_contact_at ? $lead->last_contact_at : $lead->assigned_at;
		return $ref && strtotime( $ref ) < $limit;
	}

	public static function panel() {
		$g = self::guard();
		if ( $g ) {
			return self::css() . $g;
		}
		global $wpdb;
		$t   = self::t( 'leads' );
		$uid = get_current_user_id();
		if ( current_user_can( 'manage_options' ) ) {
			if ( ! empty( $_GET['trajneri'] ) ) { // phpcs:ignore
				$uid = absint( $_GET['trajneri'] ); // phpcs:ignore
			} elseif ( ! IAMP_Sales::is_team( $uid ) ) {
				$ids = array_keys( self::trainers() );
				$uid = $ids ? (int) $ids[0] : $uid;
			}
		}
		$filter = isset( $_GET['statusi'] ) ? sanitize_key( wp_unslash( $_GET['statusi'] ) ) : 'hapur'; // phpcs:ignore
		$where  = $wpdb->prepare( 'owner_id = %d', $uid );
		if ( 'hapur' === $filter ) {
			$where .= " AND status NOT IN ('bleu','nuk_interesohet')";
		} elseif ( 'vonuar' === $filter ) {
			$where .= " AND status NOT IN ('bleu','nuk_interesohet')";
		} elseif ( 'urgjent' === $filter ) {
			$where .= ' AND urgent = 1';
		} elseif ( isset( self::statuses()[ $filter ] ) ) {
			$where .= $wpdb->prepare( ' AND status = %s', $filter );
		}
		$rows = $wpdb->get_results( "SELECT * FROM $t WHERE $where ORDER BY urgent DESC, IF( urgent = 1, id, 0 ) DESC, ( last_contact_at IS NULL ) DESC, last_contact_at ASC, id ASC LIMIT 600" ); // phpcs:ignore
		if ( 'vonuar' === $filter ) {
			$rows = array_values( array_filter( $rows, array( __CLASS__, 'is_late' ) ) );
		}
		$counts = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) c FROM $t WHERE owner_id = %d GROUP BY status", $uid ), OBJECT_K ); // phpcs:ignore
		$tname  = IAMP_Sales::trainer_name( $uid );
		$nonce  = wp_create_nonce( 'iamp_lead' );
		$base   = get_permalink();
		$keep   = ! empty( $_GET['trajneri'] ) ? array( 'trajneri' => absint( $_GET['trajneri'] ) ) : array(); // phpcs:ignore
		$total  = 0;
		foreach ( $counts as $c ) {
			$total += (int) $c->c;
		}
		$n_urg = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE owner_id = %d AND urgent = 1", $uid ) ); // phpcs:ignore
		ob_start();
		echo self::css(); // phpcs:ignore
		?>
		<div class="iamt">
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<div class="iamt-switch"><span>Shiko kontaktet e:</span>
					<?php foreach ( self::trainers() as $tid => $tn ) : ?>
						<a class="<?php echo (int) $tid === (int) $uid ? 'on' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'trajneri', (int) $tid, $base ) ); ?>"><?php echo esc_html( $tn ); ?></a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<div class="iamt-head"><h2>Kontaktet e mia — <?php echo esc_html( $tname ); ?></h2><p>Shkruaji në WhatsApp, pastaj shëno çfarë ndodhi. Asnjë kontakt nuk humbet.</p></div>
			<?php echo self::msg(); // phpcs:ignore ?>
			<?php if ( $n_urg ) : ?>
				<div class="iaml-alert">⚠ Ke <?php echo (int) $n_urg; ?> kontakt<?php echo 1 === $n_urg ? '' : 'e'; ?> të ri URGJENT — shkruaji sa më shpejt në WhatsApp. <?php if ( 'urgjent' !== $filter ) : ?><a href="<?php echo esc_url( add_query_arg( array_merge( $keep, array( 'statusi' => 'urgjent' ) ), $base ) ); ?>">Shiko vetëm urgjentet</a><?php endif; ?></div>
			<?php endif; ?>
			<div class="iaml-bar">
				<?php
				$tabs = array( 'urgjent' => '🔴 Urgjente (' . $n_urg . ')', 'hapur' => 'Të hapura', 'vonuar' => 'Të vonuara', 'all' => 'Të gjitha (' . $total . ')' );
				foreach ( self::statuses() as $k => $label ) {
					$tabs[ $k ] = $label . ( isset( $counts[ $k ] ) ? ' (' . (int) $counts[ $k ]->c . ')' : '' );
				}
				foreach ( $tabs as $k => $label ) {
					echo '<a class="' . ( $k === $filter ? 'on' : '' ) . '" href="' . esc_url( add_query_arg( array_merge( $keep, array( 'statusi' => $k ) ), $base ) ) . '">' . esc_html( $label ) . '</a>';
				}
				?>
			</div>
			<?php if ( ! $rows ) : ?>
				<div class="iamt-card">Nuk ka kontakte në këtë listë.</div>
			<?php endif; ?>
			<?php foreach ( $rows as $lead ) :
				$late = self::is_late( $lead );
				$urg  = ! empty( $lead->urgent );
				$wa   = self::wa_link( $lead, $tname );
				$ev   = self::events( $lead->id );
				$days = $lead->last_contact_at ? floor( ( current_time( 'timestamp' ) - strtotime( $lead->last_contact_at ) ) / DAY_IN_SECONDS ) : null;
				?>
				<div class="iaml-lead <?php echo $urg ? 'urg' : ( $late ? 'late' : '' ); ?>">
					<div class="iaml-top">
						<div><strong><?php echo esc_html( $lead->name ? $lead->name : 'Pa emër' ); ?></strong>
							<span class="iaml-tag"><?php echo esc_html( self::statuses()[ $lead->status ] ?? $lead->status ); ?></span>
							<?php if ( $urg ) : ?><span class="iaml-tag urg">🔴 URGJENT · i ri</span><?php endif; ?>
							<?php if ( $late && ! $urg ) : ?><span class="iaml-tag late">Vonuar</span><?php endif; ?>
						</div>
						<?php if ( $wa ) : ?>
							<a class="iaml-wa" target="_blank" rel="noopener" href="<?php echo esc_url( $wa ); ?>" data-lead="<?php echo (int) $lead->id; ?>" data-n="<?php echo esc_attr( $nonce ); ?>">💬 WhatsApp</a>
						<?php endif; ?>
					</div>
					<div class="iaml-meta">
						<?php echo $lead->phone ? '+' . esc_html( $lead->phone ) : 'pa telefon'; ?>
						<?php echo $lead->email ? ' · ' . esc_html( $lead->email ) : ''; ?>
						<?php echo $lead->category ? ' · ' . esc_html( $lead->category ) : ''; ?>
						<?php echo null !== $days ? ' · kontakti i fundit: para ' . (int) $days . ' ditësh' : ' · ende pa kontaktuar'; ?>
						<?php echo $lead->next_followup ? ' · rikontakto: ' . esc_html( mysql2date( 'd.m.Y', $lead->next_followup ) ) : ''; ?>
					</div>
					<?php if ( $lead->note ) : ?><div class="iaml-meta">📝 <?php echo esc_html( $lead->note ); ?></div><?php endif; ?>
					<form method="post">
						<input type="hidden" name="iamp_lead_action" value="contact">
						<input type="hidden" name="iamp_lead_nonce" value="<?php echo esc_attr( $nonce ); ?>">
						<?php echo self::back_field(); // phpcs:ignore ?>
						<input type="hidden" name="lead_id" value="<?php echo (int) $lead->id; ?>">
						<select name="status">
							<?php foreach ( self::statuses() as $k => $label ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $lead->status, $k ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<input type="date" name="followup" value="<?php echo esc_attr( $lead->next_followup ); ?>" title="Rikontakto më">
						<textarea name="text" placeholder="Çfarë i shkrove / çfarë të ktheu (p.sh. 'e pyeta për Paketën 1, tha do të mendohet')"></textarea>
						<button type="submit" class="iamt-btn">Ruaj</button>
					</form>
					<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<form method="post" style="grid-template-columns:auto auto">
						<input type="hidden" name="iamp_lead_action" value="reassign">
						<input type="hidden" name="iamp_lead_nonce" value="<?php echo esc_attr( $nonce ); ?>">
						<?php echo self::back_field(); // phpcs:ignore ?>
						<input type="hidden" name="lead_id" value="<?php echo (int) $lead->id; ?>">
						<select name="owner"><?php foreach ( self::trainers() as $tid => $tn ) : ?><option value="<?php echo (int) $tid; ?>" <?php selected( (int) $lead->owner_id, (int) $tid ); ?>><?php echo esc_html( $tn ); ?></option><?php endforeach; ?></select>
						<button type="submit" class="iamt-btn">Kalo te…</button>
					</form>
					<?php endif; ?>
					<?php if ( $ev ) : ?>
					<details><summary>Historiku (<?php echo count( $ev ); ?>)</summary>
						<?php foreach ( $ev as $e ) :
							$who = $e->user_id ? IAMP_Sales::trainer_name( $e->user_id ) : 'Sistemi'; ?>
							<div class="iaml-ev"><strong><?php echo esc_html( mysql2date( 'd.m.Y H:i', $e->created_at ) ); ?></strong> · <?php echo esc_html( $who ); ?>
								<?php echo $e->status ? ' · <em>' . esc_html( self::statuses()[ $e->status ] ?? $e->status ) . '</em>' : ''; ?>
								<?php echo $e->text ? '<br>' . esc_html( $e->text ) : ''; ?></div>
						<?php endforeach; ?>
					</details>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<script>
		document.querySelectorAll('.iaml-wa').forEach(function(a){a.addEventListener('click',function(){
			var fd=new FormData();fd.append('action','iamp_lead_wa');fd.append('lead_id',a.dataset.lead);fd.append('n',a.dataset.n);
			if(navigator.sendBeacon){navigator.sendBeacon('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>',fd);}
		});});
		</script>
		<?php
		return ob_get_clean();
	}

	public static function new_form() {
		$g = self::guard();
		if ( $g ) {
			return self::css() . $g;
		}
		$nonce = wp_create_nonce( 'iamp_lead' );
		ob_start();
		echo self::css(); // phpcs:ignore
		?>
		<div class="iamt">
			<div class="iamt-head"><h2>Kontakt i ri</h2><p>Shto emrin dhe numrin. Sistemi e cakton vetë te trajnerja përkatëse dhe i dërgon njoftim.</p></div>
			<?php echo self::msg(); // phpcs:ignore ?>
			<div class="iamt-card">
				<h3 style="margin-top:0">Ngjit nga grupi në WhatsApp</h3>
				<p class="iamt-hint">Kopjo mesazhin ose mesazhet me klientët e rinj (emri dhe numri, një për rresht) dhe ngjiti këtu. Sistemi i gjen vetë dhe i ndan.</p>
				<form method="post">
					<input type="hidden" name="iamp_lead_action" value="paste">
					<input type="hidden" name="iamp_lead_nonce" value="<?php echo esc_attr( $nonce ); ?>">
						<?php echo self::back_field(); // phpcs:ignore ?>
					<textarea name="paste" rows="6" placeholder="Arta Berisha +355 69 123 4567&#10;Drita Hoxha +383 44 555 666" style="min-height:120px"></textarea>
					<?php if ( IAMP_Sales::is_team() ) : ?>
					<label class="iamt-check"><input type="checkbox" name="mine" value="me" style="width:auto"> Janë klientët e mi (mbeten te unë)</label>
					<?php endif; ?>
					<p><button type="submit" class="iamt-btn">Shto nga teksti</button></p>
				</form>
			</div>
			<div class="iamt-card">
				<h3 style="margin-top:0">Ose shto një kontakt</h3>
				<form method="post">
					<input type="hidden" name="iamp_lead_action" value="new">
					<input type="hidden" name="iamp_lead_nonce" value="<?php echo esc_attr( $nonce ); ?>">
						<?php echo self::back_field(); // phpcs:ignore ?>
					<div class="iamt-grid">
						<div><label>Emri dhe mbiemri</label><input type="text" name="name"></div>
						<div><label>Telefoni (me kodin e shtetit)</label><input type="text" name="phone" placeholder="+355..."></div>
						<div><label>Email</label><input type="email" name="email"></div>
						<div><label>Nga vjen (webinar, Instagram, …)</label><input type="text" name="source"></div>
					</div>
					<?php if ( IAMP_Sales::is_team() ) : ?>
					<label><input type="checkbox" name="mine" value="me" style="width:auto"> Është kontakti im (mbetet te unë)</label>
					<?php endif; ?>
					<label>Shënim</label><textarea name="note"></textarea>
					<p><button type="submit" class="iamt-btn">Shto kontaktin</button></p>
				</form>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
