<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mjete për ndërprerjen e lidhjeve të jashtme (ChatGPT / OpenAI / "bridge") me WordPress-in.
 */
class IAMP_Security {

	const AI_BOTS = 'GPTBot|ChatGPT-User|OAI-SearchBot|OpenAI';

	public static function init() {
		if ( IAMP_Settings::get( 'disable_app_passwords' ) ) {
			add_filter( 'wp_is_application_passwords_available', '__return_false' );
		}
		if ( IAMP_Settings::get( 'disable_xmlrpc' ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', '__return_empty_array' );
		}
		if ( IAMP_Settings::get( 'block_ai_bots' ) ) {
			add_action( 'init', array( __CLASS__, 'block_bots' ), 0 );
			add_filter( 'robots_txt', array( __CLASS__, 'robots' ), 99, 2 );
		}
	}

	public static function block_bots() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore
		if ( $ua && preg_match( '/' . self::AI_BOTS . '/i', $ua ) ) {
			status_header( 403 );
			nocache_headers();
			exit( 'Access denied' );
		}
	}

	public static function robots( $output, $public ) {
		foreach ( array( 'GPTBot', 'ChatGPT-User', 'OAI-SearchBot' ) as $bot ) {
			$output .= "\nUser-agent: $bot\nDisallow: /\n";
		}
		return $output;
	}

	/**
	 * Gjen plugin-et që duken si lidhje me ChatGPT/OpenAI ose "bridge" API.
	 */
	public static function suspicious_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$pattern = '/(chat\s*gpt|openai|gpt|member\s*activation\s*bridge|activation\s*bridge|ai\s*bridge|ai\s*engine|mcp)/i';
		$out     = array();
		$self    = plugin_basename( IAMP_FILE );

		foreach ( get_plugins() as $file => $data ) {
			if ( $file === $self ) {
				continue;
			}
			$hay = $file . ' ' . $data['Name'] . ' ' . $data['Description'] . ' ' . $data['Author'];
			if ( preg_match( $pattern, $hay ) ) {
				$out[] = array(
					'file'   => $file,
					'name'   => $data['Name'],
					'desc'   => wp_strip_all_tags( $data['Description'] ),
					'active' => is_plugin_active( $file ),
					'mu'     => false,
				);
			}
		}
		foreach ( get_mu_plugins() as $file => $data ) {
			$hay = $file . ' ' . $data['Name'] . ' ' . $data['Description'];
			if ( preg_match( $pattern, $hay ) ) {
				$out[] = array(
					'file'   => $file,
					'name'   => $data['Name'] ? $data['Name'] : $file,
					'desc'   => wp_strip_all_tags( $data['Description'] ),
					'active' => true,
					'mu'     => true,
				);
			}
		}
		return $out;
	}

	/**
	 * Të gjitha Application Passwords në faqe (këto përdoren nga ChatGPT/aplikacionet e jashtme për t'u lidhur me REST API).
	 */
	public static function app_passwords() {
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return array();
		}
		$users = get_users( array( 'meta_key' => '_application_passwords', 'fields' => array( 'ID', 'user_login', 'user_email' ) ) ); // phpcs:ignore
		$out   = array();
		foreach ( $users as $u ) {
			foreach ( WP_Application_Passwords::get_user_application_passwords( $u->ID ) as $pw ) {
				$out[] = array(
					'user_id'   => (int) $u->ID,
					'login'     => $u->user_login,
					'email'     => $u->user_email,
					'uuid'      => $pw['uuid'],
					'name'      => $pw['name'],
					'created'   => $pw['created'],
					'last_used' => $pw['last_used'],
					'last_ip'   => $pw['last_ip'],
				);
			}
		}
		return $out;
	}

	public static function revoke_app_password( $user_id, $uuid ) {
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return false;
		}
		$r = WP_Application_Passwords::delete_application_password( (int) $user_id, $uuid );
		IAMP_Log::add( 'security', $user_id, 'U fshi Application Password ' . $uuid );
		return ! is_wp_error( $r );
	}

	public static function revoke_all_app_passwords() {
		$n = 0;
		foreach ( self::app_passwords() as $pw ) {
			if ( self::revoke_app_password( $pw['user_id'], $pw['uuid'] ) ) {
				$n++;
			}
		}
		IAMP_Log::add( 'security', 0, 'U fshinë të gjitha Application Passwords: ' . $n );
		return $n;
	}

	/**
	 * Përdorues me emër që të kujton ChatGPT/bot (p.sh. "chatgpt", "gpt-bridge").
	 */
	public static function suspicious_users() {
		$out = array();
		foreach ( array( '*gpt*', '*openai*', '*bridge*', '*bot*' ) as $search ) {
			foreach ( get_users( array( 'search' => $search, 'search_columns' => array( 'user_login', 'user_email', 'display_name' ), 'number' => 20 ) ) as $u ) {
				$out[ $u->ID ] = $u;
			}
		}
		return array_values( $out );
	}
}
