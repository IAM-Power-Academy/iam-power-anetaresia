<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IAMP_Settings {

	const OPTION = 'iamp_settings';

	public static function defaults() {
		return array(
			'webhook_key'          => '',
			'kofi_token'           => '',
			'from_name'            => 'IAM Power Academy',
			'from_email'           => '',
			'login_url'            => '',
			'reminder_days'        => 3,
			'team_roles'           => array(),
			'send_welcome'         => 1,
			'send_reminder'        => 1,
			'send_expired'         => 1,
			'email_color'          => '#c9a14a',
			'welcome_subject'      => 'Mirë se erdhe në IAM Power, {emri}! Qasja jote është aktive',
			'welcome_body'         => "Përshëndetje {emri},\n\nQasja jote në IAM Power Academy u aktivizua me sukses.\n\nKurset e tua:\n{kurset}\n\nVlefshmëria: {skadimi}\n\n{blloku_fjalekalimit}\n\nHyr këtu: {linku_hyrjes}\n\nMe dashuri dhe dritë,\nEkipi IAM Power",
			'reminder_subject'     => '{emri}, qasja jote skadon më {data_skadimit}',
			'reminder_body'        => "Përshëndetje {emri},\n\nTë kujtojmë me dashuri se qasja jote në:\n{kurset}\n\nskadon më {data_skadimit}.\n\nNëse dëshiron të vazhdosh rrugëtimin, na kontakto ose rinovo anëtarësinë.\n\nEkipi IAM Power",
			'expired_subject'      => 'Qasja jote në IAM Power ka skaduar',
			'expired_body'         => "Përshëndetje {emri},\n\nQasja jote në:\n{kurset}\n\nka përfunduar sot. Faleminderit që ishe pjesë e këtij rrugëtimi.\n\nDera mbetet e hapur — na shkruaj kur të jesh gati për hapin tjetër.\n\nEkipi IAM Power",
			'disable_app_passwords'=> 0,
			'block_ai_bots'        => 0,
			'disable_xmlrpc'       => 0,
			'github_token'         => '',
			'auto_update'          => 1,
			'vat_pct'              => 18,
			'notify_email'         => '',
			'sheet_url'            => '',
			'sheet_secret'         => '',
			'lead_weights'         => array( 'teuta' => 3, 'leonora' => 3, 'zejnepe' => 2, 'alda' => 2, 'anila' => 2 ),
			'lead_from_activations' => 1,
			'lead_overdue_days'    => 5,
			'lead_wa_template'     => 'Përshëndetje {emri}, jam {trajnerja} nga IAM Power Academy.',
			'sheet_link'           => 'https://docs.google.com/spreadsheets/d/1qDFiflVN1Txufd8EJ_U3gf_EECF6g_hn9wpGGXmD-aI/edit',
		);
	}

	public static function ensure_defaults() {
		$opts = get_option( self::OPTION );
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}
		$merged = wp_parse_args( $opts, self::defaults() );
		if ( empty( $merged['sheet_secret'] ) ) {
			$merged['sheet_secret'] = wp_generate_password( 24, false, false );
		}
		if ( empty( $merged['webhook_key'] ) ) {
			$merged['webhook_key'] = wp_generate_password( 32, false, false );
		}
		if ( $merged !== $opts ) {
			update_option( self::OPTION, $merged, false );
		}
	}

	public static function all() {
		$opts = get_option( self::OPTION );
		return wp_parse_args( is_array( $opts ) ? $opts : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function update( $values ) {
		$all = array_merge( self::all(), $values );
		update_option( self::OPTION, $all, false );
		self::sync_role_caps();
	}

	/**
	 * Jep/heq të drejtën e aktivizimit për rolet e ekipit (p.sh. Editor, Shop manager).
	 */
	public static function sync_role_caps() {
		$selected = (array) self::get( 'team_roles' );
		foreach ( wp_roles()->roles as $slug => $info ) {
			if ( 'administrator' === $slug ) {
				continue;
			}
			$role = get_role( $slug );
			if ( ! $role ) {
				continue;
			}
			if ( in_array( $slug, $selected, true ) ) {
				$role->add_cap( IAMP_CAP );
			} elseif ( $role->has_cap( IAMP_CAP ) ) {
				$role->remove_cap( IAMP_CAP );
			}
		}
	}

	public static function login_url() {
		$url = self::get( 'login_url' );
		return $url ? $url : wp_login_url();
	}
}
