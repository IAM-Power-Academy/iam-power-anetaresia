<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IAMP_Emails {

	/**
	 * Dërgon email sipas llojit: welcome | reminder | expired.
	 *
	 * @param string $type
	 * @param int    $user_id
	 * @param object $membership Rreshti nga tabela memberships.
	 * @param array  $extra      p.sh. array( 'new_user' => true )
	 */
	public static function send( $type, $user_id, $membership, $extra = array() ) {
		$s    = IAMP_Settings::all();
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		$subject = isset( $s[ $type . '_subject' ] ) ? $s[ $type . '_subject' ] : '';
		$body    = isset( $s[ $type . '_body' ] ) ? $s[ $type . '_body' ] : '';
		if ( ! $subject || ! $body ) {
			return false;
		}

		$vars    = self::vars( $user, $membership, $extra );
		$subject = strtr( $subject, $vars['text'] );
		$body    = strtr( esc_html( $body ), $vars['html'] );
		$html    = self::wrap( nl2br( $body ), $s['email_color'] );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$from    = $s['from_email'] ? $s['from_email'] : get_option( 'admin_email' );
		$headers[] = 'From: ' . wp_specialchars_decode( $s['from_name'], ENT_QUOTES ) . ' <' . $from . '>';

		$ok = wp_mail( $user->user_email, wp_specialchars_decode( $subject, ENT_QUOTES ), $html, $headers );
		IAMP_Log::add( $ok ? 'email_sent' : 'email_failed', $user_id, array( 'lloji' => $type, 'te' => $user->user_email ) );
		return $ok;
	}

	private static function vars( $user, $membership, $extra ) {
		$courses_html = array();
		$courses_text = array();
		foreach ( IAMP_DB::string_to_ids( $membership->courses ) as $cid ) {
			$title          = get_the_title( $cid );
			$courses_text[] = '• ' . $title;
			$courses_html[] = '• <a href="' . esc_url( get_permalink( $cid ) ) . '">' . esc_html( $title ) . '</a>';
		}
		foreach ( IAMP_DB::string_to_ids( $membership->groups_ids ) as $gid ) {
			$title          = get_the_title( $gid );
			$courses_text[] = '• ' . $title;
			$courses_html[] = '• ' . esc_html( $title );
		}

		$expiry_date = $membership->expires_at ? date_i18n( 'd.m.Y', strtotime( $membership->expires_at ) ) : '';
		$expiry_text = $expiry_date ? 'deri më ' . $expiry_date : 'pa afat (qasje e përhershme)';

		$login = IAMP_Settings::login_url();

		$pw_html = '';
		$pw_text = '';
		if ( ! empty( $extra['new_user'] ) ) {
			$key = get_password_reset_key( $user );
			if ( ! is_wp_error( $key ) ) {
				$link    = network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ), 'login' );
				$pw_text = 'Përdoruesi yt: ' . $user->user_email . ' — vendos fjalëkalimin këtu: ' . $link;
				$pw_html = '<strong>Përdoruesi yt:</strong> ' . esc_html( $user->user_email ) . '<br>'
					. '<a href="' . esc_url( $link ) . '" style="display:inline-block;margin-top:8px;padding:12px 22px;background:#0b1a33;color:#fff;text-decoration:none;border-radius:6px">Vendos fjalëkalimin tënd</a>';
			}
		} else {
			$pw_text = 'Hyr me email-in dhe fjalëkalimin tënd ekzistues.';
			$pw_html = esc_html( $pw_text );
		}

		$name = $user->first_name ? $user->first_name : $user->display_name;

		$text = array(
			'{emri}'                => $name,
			'{email}'               => $user->user_email,
			'{kurset}'              => implode( "\n", $courses_text ),
			'{skadimi}'             => $expiry_text,
			'{data_skadimit}'       => $expiry_date ? $expiry_date : '—',
			'{linku_hyrjes}'        => $login,
			'{blloku_fjalekalimit}' => $pw_text,
			'{faqja}'               => get_bloginfo( 'name' ),
		);
		$html = array(
			'{emri}'                => esc_html( $name ),
			'{email}'               => esc_html( $user->user_email ),
			'{kurset}'              => implode( '<br>', $courses_html ),
			'{skadimi}'             => esc_html( $expiry_text ),
			'{data_skadimit}'       => esc_html( $expiry_date ? $expiry_date : '—' ),
			'{linku_hyrjes}'        => '<a href="' . esc_url( $login ) . '">' . esc_html( $login ) . '</a>',
			'{blloku_fjalekalimit}' => $pw_html,
			'{faqja}'               => esc_html( get_bloginfo( 'name' ) ),
		);
		return array( 'text' => $text, 'html' => $html );
	}

	private static function wrap( $content, $color ) {
		$color = sanitize_hex_color( $color ) ? $color : '#c9a14a';
		$site  = esc_html( get_bloginfo( 'name' ) );
		return '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f4f1ea">'
			. '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f1ea;padding:30px 0"><tr><td align="center">'
			. '<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;font-family:Georgia,serif">'
			. '<tr><td style="background:#0b1a33;padding:26px;text-align:center;color:' . $color . ';font-size:22px;letter-spacing:2px">IAM POWER</td></tr>'
			. '<tr><td style="padding:32px;color:#222;font-size:16px;line-height:1.65">' . $content . '</td></tr>'
			. '<tr><td style="border-top:3px solid ' . $color . ';padding:16px;text-align:center;color:#888;font-size:12px">' . $site . '</td></tr>'
			. '</table></td></tr></table></body></html>';
	}
}
