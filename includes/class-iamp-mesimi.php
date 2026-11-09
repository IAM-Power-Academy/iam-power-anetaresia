<?php
/**
 * “Mësimi i Ditës” — mësime të shkurtra ditore që shfaqen në aplikacion (për të gjithë, edhe pa llogari).
 * Çdo mësim është një postim i llojit iamp_mesim; mund të planifikohet (data e ardhshme) dhe del vetë në ditën e caktuar.
 * Çdo mësim ka opsionalisht një buton (tekst + link) drejt një kursi ose libri.
 *
 * @package IAM_Power_Anetaresia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IAMP_Mesimi {

	const CPT = 'iamp_mesim';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'metabox' ) );
		add_action( 'save_post_' . self::CPT, array( __CLASS__, 'save' ) );
		add_shortcode( 'iam_mesimi_dites', array( __CLASS__, 'shortcode' ) );
	}

	public static function register() {
		register_post_type(
			self::CPT,
			array(
				'labels'              => array(
					'name'          => 'Mësimi i Ditës',
					'singular_name' => 'Mësim',
					'add_new_item'  => 'Shto mësim',
					'edit_item'     => 'Ndrysho mësimin',
					'all_items'     => 'Mësimi i Ditës',
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'iamp',
				'show_in_rest'        => true,
				'exclude_from_search' => true,
				'supports'            => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
				'capability_type'     => 'post',
			)
		);
		foreach ( array( '_iamp_cta_label', '_iamp_cta_url' ) as $k ) {
			register_post_meta(
				self::CPT,
				$k,
				array(
					'type'          => 'string',
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => function () {
						return current_user_can( 'manage_options' );
					},
				)
			);
		}
	}

	public static function metabox() {
		add_meta_box( 'iamp_mesim_cta', 'Butoni në fund të mësimit', array( __CLASS__, 'metabox_html' ), self::CPT, 'normal', 'high' );
	}

	public static function metabox_html( $post ) {
		wp_nonce_field( 'iamp_mesim', 'iamp_mesim_n' );
		$l = get_post_meta( $post->ID, '_iamp_cta_label', true );
		$u = get_post_meta( $post->ID, '_iamp_cta_url', true );
		echo '<p><label>Teksti i butonit<br><input type="text" name="iamp_cta_label" value="' . esc_attr( $l ) . '" class="widefat" placeholder="Shiko kursin"></label></p>';
		echo '<p><label>Linku<br><input type="url" name="iamp_cta_url" value="' . esc_attr( $u ) . '" class="widefat" placeholder="https://iampower.al/..."></label></p>';
		echo '<p class="description">Për ta planifikuar: shtyp “Planifiko” dhe zgjidh datën. Mësimi del vetë atë ditë.</p>';
	}

	public static function save( $post_id ) {
		if ( ! isset( $_POST['iamp_mesim_n'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['iamp_mesim_n'] ) ), 'iamp_mesim' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, '_iamp_cta_label', isset( $_POST['iamp_cta_label'] ) ? sanitize_text_field( wp_unslash( $_POST['iamp_cta_label'] ) ) : '' );
		update_post_meta( $post_id, '_iamp_cta_url', isset( $_POST['iamp_cta_url'] ) ? esc_url_raw( wp_unslash( $_POST['iamp_cta_url'] ) ) : '' );
	}

	/**
	 * Mësimet e publikuara, më i riu i pari.
	 */
	public static function latest( $limit = 14 ) {
		return get_posts(
			array(
				'post_type'        => self::CPT,
				'post_status'      => 'publish',
				'numberposts'      => $limit,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);
	}

	private static function one( $p, $open ) {
		$label = (string) get_post_meta( $p->ID, '_iamp_cta_label', true );
		$url   = (string) get_post_meta( $p->ID, '_iamp_cta_url', true );
		$date  = get_the_date( 'j.n.Y', $p );
		$body  = wpautop( wp_kses_post( $p->post_content ) );
		$btn   = ( $label && $url ) ? '<a class="btn" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a>' : '';
		return '<details class="ms"' . ( $open ? ' open' : '' ) . '><summary><span class="d">' . esc_html( $date ) . '</span><span class="t">' . esc_html( get_the_title( $p ) ) . '</span></summary><div class="b">' . $body . $btn . '</div></details>';
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
			. '.ms .b{padding:0 14px 16px;color:#e6dfcc;font-size:15px;line-height:1.6}.ms .b p{margin:0 0 12px}.ms .b .btn{display:block;text-align:center;margin-top:6px}';
	}

	public static function shortcode( $atts ) {
		return '<style>' . self::css() . '</style><div class="iamp-ms">' . self::render( 7 ) . '</div>';
	}
}
