<?php
/**
 * “Mësimi i Ditës” — çdo postim që botohet në blogun e iampower.al shfaqet edhe në aplikacion (skeda “Mësimi”),
 * për të gjithë, edhe pa llogari. Postimet e shkurtra shfaqen të plota; të gjata shfaqen me përmbledhje dhe buton “Lexo”.
 * Butonat drejt kurseve/librave vendosen brenda tekstit të postimit.
 *
 * @package IAM_Power_Anetaresia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IAMP_Mesimi {

	public static function init() {
		add_shortcode( 'iam_mesimi_dites', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Postimet e publikuara të blogut, më i riu i pari.
	 */
	public static function latest( $limit = 14 ) {
		return get_posts(
			array(
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'numberposts'         => $limit,
				'orderby'             => 'date',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'has_password'        => false,
				'suppress_filters'    => false,
			)
		);
	}

	private static function one( $p, $open ) {
		$date  = get_the_date( 'j.n.Y', $p );
		$plain = trim( wp_strip_all_tags( strip_shortcodes( $p->post_content ) ) );
		$words = $plain ? count( preg_split( '/\s+/u', $plain ) ) : 0;
		if ( $words > 0 && $words <= 320 ) {
			$body = wpautop( wp_kses_post( $p->post_content ) );
		} else {
			$ex   = $p->post_excerpt ? $p->post_excerpt : wp_trim_words( $plain, 40, '…' );
			$body = '<p>' . esc_html( $ex ) . '</p><a class="btn" href="' . esc_url( get_permalink( $p ) ) . '" target="_blank" rel="noopener">Lexo më shumë</a>';
		}
		return '<details class="ms"' . ( $open ? ' open' : '' ) . '><summary><span class="d">' . esc_html( $date ) . '</span><span class="t">' . esc_html( get_the_title( $p ) ) . '</span></summary><div class="b">' . $body . '</div></details>';
	}

	/**
	 * HTML për aplikacion: i pari hapur, të tjerët të mbyllur.
	 */
	public static function render( $limit = 14 ) {
		$posts = self::latest( $limit );
		if ( ! $posts ) {
			return '<div class="empty">Mësimi i parë vjen së shpejti. 🌟</div>';
		}
		$out = '';
		foreach ( $posts as $i => $p ) {
			$out .= self::one( $p, 0 === $i );
		}
		return $out;
	}

	public static function css() {
		return '.ms{background:#13264a;border:1px solid rgba(201,161,74,.35);border-radius:16px;margin:0 0 12px;overflow:hidden;text-align:left}'
			. '.ms summary{list-style:none;cursor:pointer;padding:14px;display:flex;flex-direction:column;gap:4px}.ms summary::-webkit-details-marker{display:none}'
			. '.ms .d{font-size:12px;color:#c9a14a}.ms .t{font-size:17px;font-weight:700;color:#fff;line-height:1.35}'
			. '.ms .b{padding:0 14px 16px;color:#e6dfcc;font-size:15px;line-height:1.6}.ms .b p{margin:0 0 12px}.ms .b img{max-width:100%;height:auto;border-radius:10px}.ms .b .btn{display:block;text-align:center;margin-top:6px}';
	}

	public static function shortcode( $atts ) {
		return '<style>' . self::css() . '</style><div class="iamp-ms">' . self::render( 7 ) . '</div>';
	}
}
