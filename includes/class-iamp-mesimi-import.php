<?php
/**
 * Mësimi i Ditës — importim automatik nga GitHub.
 *
 * Skedarët JSON në dosjen "mesimet/" të repo-s (p.sh. mesimet/java-2.json) kthehen vetë në postime blogu
 * të planifikuara. Një mësim krijohet vetëm kur skedari ka "approved": true (miratimi i Mimozës) dhe
 * vetëm një herë (sipas "id"), kështu që ndryshimet e bëra me dorë në WordPress nuk mbishkruhen.
 *
 * Format i skedarit:
 * { "approved": true, "lessons": [ { "id": "j2-d8", "date": "2026-11-17", "time": "08:00",
 *   "title": "…", "content": "<p>…</p>", "button_text": "Shiko ofertën", "button_url": "https://…" } ] }
 *
 * @package IAM_Power_Anetaresia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IAMP_Mesimi_Import {

	const META   = '_iamp_mesim_id';
	const FOLDER = 'mesimet';
	const MAX    = 20; // skedarë maksimum për një kalim.

	public static function init() {
		add_action( 'iamp_daily_check', array( __CLASS__, 'run' ), 30 );
	}

	private static function headers() {
		$h     = array(
			'Accept'               => 'application/vnd.github.raw+json',
			'User-Agent'           => 'IAM-Power-Anetaresia-Mesimi',
			'X-GitHub-Api-Version' => '2022-11-28',
		);
		$token = trim( (string) IAMP_Settings::get( 'github_token' ) );
		if ( $token ) {
			$h['Authorization'] = 'Bearer ' . $token;
		}
		return $h;
	}

	private static function get( $path, $json_list = false ) {
		$url = 'https://api.github.com/repos/' . IAMP_Updater::REPO . '/contents/' . $path . '?ref=main';
		$h   = self::headers();
		if ( $json_list ) {
			$h['Accept'] = 'application/vnd.github+json';
		}
		$res = wp_remote_get( $url, array( 'timeout' => 20, 'headers' => $h ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		return wp_remote_retrieve_body( $res );
	}

	/**
	 * Kalimi kryesor (një herë në orë, bashkë me kontrollin ditor).
	 *
	 * @return int numri i postimeve të reja.
	 */
	public static function run() {
		if ( get_transient( 'iamp_mesim_lock' ) ) {
			return 0;
		}
		set_transient( 'iamp_mesim_lock', 1, 5 * MINUTE_IN_SECONDS );

		$list = self::get( self::FOLDER, true );
		$list = $list ? json_decode( $list, true ) : null;
		if ( ! is_array( $list ) ) {
			return 0;
		}

		$created = 0;
		$files   = 0;
		foreach ( $list as $f ) {
			if ( empty( $f['name'] ) || empty( $f['path'] ) || '.json' !== substr( $f['name'], -5 ) ) {
				continue;
			}
			if ( ++$files > self::MAX ) {
				break;
			}
			$raw  = self::get( $f['path'] );
			$data = $raw ? json_decode( $raw, true ) : null;
			if ( ! is_array( $data ) || empty( $data['approved'] ) || true !== $data['approved'] || empty( $data['lessons'] ) || ! is_array( $data['lessons'] ) ) {
				continue; // jo i miratuar ose i pavlefshëm.
			}
			foreach ( $data['lessons'] as $l ) {
				if ( self::create( $l ) ) {
					$created++;
				}
			}
		}
		if ( $created ) {
			IAMP_Log::add( 'mesimi_import', 0, $created . ' mësime të reja nga GitHub.' );
		}
		return $created;
	}

	private static function exists( $id ) {
		$q = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'any',
				'meta_key'         => self::META, // phpcs:ignore
				'meta_value'       => $id, // phpcs:ignore
				'numberposts'      => 1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		return ! empty( $q );
	}

	private static function create( $l ) {
		if ( ! is_array( $l ) ) {
			return false;
		}
		$id    = isset( $l['id'] ) ? sanitize_key( $l['id'] ) : '';
		$title = isset( $l['title'] ) ? sanitize_text_field( $l['title'] ) : '';
		$body  = isset( $l['content'] ) ? (string) $l['content'] : '';
		$date  = isset( $l['date'] ) ? (string) $l['date'] : '';
		$time  = isset( $l['time'] ) && preg_match( '/^\d{2}:\d{2}$/', $l['time'] ) ? $l['time'] : '08:00';
		if ( ! $id || ! $title || ! $body || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || self::exists( $id ) ) {
			return false;
		}

		$btn_url = isset( $l['button_url'] ) ? esc_url_raw( $l['button_url'], array( 'https' ) ) : '';
		$btn_txt = isset( $l['button_text'] ) ? sanitize_text_field( $l['button_text'] ) : '';
		$html    = wp_kses_post( $body );
		if ( $btn_url && $btn_txt ) {
			$html .= "\n" . '<p><a class="btn" href="' . esc_url( $btn_url ) . '">' . esc_html( $btn_txt ) . ' →</a></p>';
		}

		$local = $date . ' ' . $time . ':00';
		$ts    = strtotime( get_gmt_from_date( $local ) . ' UTC' );
		$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID' ) );

		$post_id = wp_insert_post(
			array(
				'post_type'     => 'post',
				'post_title'    => $title,
				'post_content'  => $html,
				'post_status'   => $ts > time() ? 'future' : 'publish',
				'post_date'     => $local,
				'post_date_gmt' => get_gmt_from_date( $local ),
				'post_author'   => $admin ? (int) $admin[0] : 1,
			),
			true
		);
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return false;
		}
		update_post_meta( $post_id, self::META, $id );
		return true;
	}
}
