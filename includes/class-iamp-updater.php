<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Përditësim automatik nga GitHub Releases (IAM-Power-Academy/iam-power-anetaresia).
 *
 * - Kontrollon versionin e fundit çdo orë dhe, kur ka version të ri, e instalon vetë
 *   (pa pritur përditësimet automatike të WordPress-it, që ndodhin vetëm 2 herë në ditë).
 * - Repo private: përdor token-in (vetëm-lexim) nga Cilësimet.
 * - Repo publike: punon pa token.
 */
class IAMP_Updater {

	const REPO      = 'IAM-Power-Academy/iam-power-anetaresia';
	const CACHE_KEY = 'iamp_gh_release';
	const PREFIX    = 'iamp-github:';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 3 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 0 );
		add_action( 'iamp_daily_check', array( __CLASS__, 'self_update' ), 20 );
	}

	public static function basename() {
		return plugin_basename( IAMP_FILE );
	}

	private static function headers( $accept = 'application/vnd.github+json' ) {
		$h = array(
			'Accept'               => $accept,
			'User-Agent'           => 'IAM-Power-Anetaresia-Updater',
			'X-GitHub-Api-Version' => '2022-11-28',
		);
		$token = trim( (string) IAMP_Settings::get( 'github_token' ) );
		if ( $token ) {
			$h['Authorization'] = 'Bearer ' . $token;
		}
		return $h;
	}

	/**
	 * Merr të dhënat e versionit të fundit (me cache).
	 *
	 * @return array|null array( version, package, notes, published, url ) ose null.
	 */
	public static function latest( $force = false ) {
		if ( ! $force ) {
			$cached = get_site_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached['version'] ? $cached : null;
			}
		}

		$res  = wp_remote_get( 'https://api.github.com/repos/' . self::REPO . '/releases/latest', array( 'timeout' => 15, 'headers' => self::headers() ) );
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );

		if ( 200 !== $code ) {
			$err = is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . $code;
			update_option( 'iamp_update_error', $err . ( 404 === $code ? ' — repo private pa token, ose ende pa version.' : '' ), false );
			// Mos e ngarko GitHub-in me kërkesa: provo sërish pas 1 ore.
			set_site_transient( self::CACHE_KEY, array( 'version' => '' ), HOUR_IN_SECONDS );
			return null;
		}

		$data    = json_decode( wp_remote_retrieve_body( $res ), true );
		$version = ltrim( (string) ( isset( $data['tag_name'] ) ? $data['tag_name'] : '' ), 'vV' );
		$package = '';
		foreach ( (array) ( isset( $data['assets'] ) ? $data['assets'] : array() ) as $asset ) {
			if ( isset( $asset['name'] ) && '.zip' === substr( $asset['name'], -4 ) ) {
				// Repo private → shkarkim përmes API me token; publike → linku i drejtpërdrejtë.
				$package = IAMP_Settings::get( 'github_token' ) ? self::PREFIX . $asset['url'] : $asset['browser_download_url'];
				break;
			}
		}

		$out = array(
			'version'   => $version,
			'package'   => $package,
			'notes'     => isset( $data['body'] ) ? (string) $data['body'] : '',
			'published' => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
			'url'       => isset( $data['html_url'] ) ? (string) $data['html_url'] : '',
		);
		delete_option( 'iamp_update_error' );
		set_site_transient( self::CACHE_KEY, $out, HOUR_IN_SECONDS );
		update_option( 'iamp_update_checked', time(), false );
		return $version ? $out : null;
	}

	public static function check( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}
		$rel = self::latest();
		if ( ! $rel || ! $rel['package'] ) {
			return $transient;
		}

		$item = (object) array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => 'iam-power-anetaresia',
			'plugin'       => self::basename(),
			'new_version'  => $rel['version'],
			'url'          => 'https://iampower.al',
			'package'      => $rel['package'],
			'requires_php' => '7.4',
			'icons'        => array(),
			'banners'      => array(),
		);

		if ( version_compare( $rel['version'], IAMP_VERSION, '>' ) ) {
			$transient->response[ self::basename() ] = $item;
		} else {
			$item->new_version = IAMP_VERSION;
			$transient->no_update[ self::basename() ] = $item;
		}
		return $transient;
	}

	/**
	 * Dritarja "Shiko detajet" te faqja Plugins.
	 */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'iam-power-anetaresia' !== $args->slug ) {
			return $result;
		}
		$rel = self::latest();
		return (object) array(
			'name'          => 'IAM Power Anëtarësia',
			'slug'          => 'iam-power-anetaresia',
			'version'       => $rel ? $rel['version'] : IAMP_VERSION,
			'author'        => 'IAM Power Academy',
			'homepage'      => 'https://iampower.al',
			'requires_php'  => '7.4',
			'last_updated'  => $rel ? $rel['published'] : '',
			'download_link' => $rel ? $rel['package'] : '',
			'sections'      => array(
				'description' => 'Aktivizimi i anëtarëve IAM Power në LearnDash.',
				'changelog'   => $rel && $rel['notes'] ? wpautop( esc_html( $rel['notes'] ) ) : 'Pa shënime.',
			),
		);
	}

	/**
	 * Shkarkimi nga repo private: kërko skedarin me token, pastaj ndiq ridrejtimin pa token.
	 */
	public static function download( $reply, $package, $upgrader ) {
		if ( ! is_string( $package ) || 0 !== strpos( $package, self::PREFIX ) ) {
			return $reply;
		}
		$api = substr( $package, strlen( self::PREFIX ) );
		if ( 0 !== strpos( $api, 'https://api.github.com/repos/' . self::REPO . '/' ) ) {
			return new WP_Error( 'iamp_dl', 'Adresë shkarkimi e pavlefshme.' );
		}

		$res = wp_remote_get( $api, array( 'timeout' => 30, 'redirection' => 0, 'headers' => self::headers( 'application/octet-stream' ) ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );

		if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
			$location = wp_remote_retrieve_header( $res, 'location' );
			if ( ! $location ) {
				return new WP_Error( 'iamp_dl', 'GitHub nuk ktheu adresën e skedarit.' );
			}
			return download_url( $location, 300 ); // Linku i nënshkruar nuk ka nevojë për token.
		}

		if ( 200 === $code ) {
			$tmp = wp_tempnam( 'iam-power-anetaresia.zip' );
			file_put_contents( $tmp, wp_remote_retrieve_body( $res ) ); // phpcs:ignore
			return $tmp;
		}

		return new WP_Error( 'iamp_dl', 'Shkarkimi nga GitHub dështoi (HTTP ' . $code . '). Kontrollo token-in te Cilësimet.' );
	}

	public static function auto_update( $update, $item ) {
		if ( isset( $item->plugin ) && self::basename() === $item->plugin && IAMP_Settings::get( 'auto_update' ) ) {
			return true;
		}
		return $update;
	}

	public static function flush() {
		delete_site_transient( self::CACHE_KEY );
	}

	/**
	 * Kontroll manual nga paneli.
	 */
	public static function force_check() {
		delete_site_transient( self::CACHE_KEY );
		delete_site_transient( 'update_plugins' );
		$rel = self::latest( true );
		wp_update_plugins();
		return $rel;
	}

	/**
	 * Çdo orë: nëse në GitHub ka version më të ri, instalohet vetë, pa asnjë klikim.
	 * Fiket me "Përditëso vetë" te Cilësimet & Email.
	 */
	public static function self_update() {
		if ( ! IAMP_Settings::get( 'auto_update' ) || get_transient( 'iamp_self_updating' ) ) {
			return;
		}
		$rel = self::latest();
		if ( ! $rel || ! $rel['package'] || ! version_compare( $rel['version'], IAMP_VERSION, '>' ) ) {
			return;
		}
		set_transient( 'iamp_self_updating', 1, 15 * MINUTE_IN_SECONDS );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$base   = self::basename();
		$active = is_plugin_active( $base );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins(); // Mbush listën e përditësimeve me versionin nga GitHub.

		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->upgrade( $base );

		if ( $active && ! is_plugin_active( $base ) ) {
			activate_plugin( $base, '', false, true );
		}
		delete_transient( 'iamp_self_updating' );

		if ( true === $result || null === $result ) {
			IAMP_Log::add( 'update', 0, 'U përditësua vetë nga ' . IAMP_VERSION . ' në ' . $rel['version'] );
		} else {
			$msg = is_wp_error( $result ) ? $result->get_error_message() : 'arsye e panjohur';
			update_option( 'iamp_update_error', 'Përditësimi automatik dështoi: ' . $msg, false );
			IAMP_Log::add( 'update', 0, 'Përditësimi automatik në ' . $rel['version'] . ' dështoi: ' . $msg );
		}
	}
}
