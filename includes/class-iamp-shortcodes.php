<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IAMP_Shortcodes {

	public static function init() {
		add_shortcode( 'iam_aktivizo', array( __CLASS__, 'activate_form' ) );
		add_shortcode( 'iam_kurset_e_mia', array( __CLASS__, 'my_courses' ) );
	}

	private static function style() {
		static $done = false;
		if ( $done ) {
			return '';
		}
		$done = true;
		return '<style>
.iamp-box{max-width:520px;margin:20px auto;padding:30px;background:#0b1a33;color:#f5efe2;border-radius:14px;border:1px solid #c9a14a;font-family:inherit}
.iamp-box h3{color:#c9a14a;margin:0 0 14px;font-size:22px}
.iamp-box label{display:block;margin:12px 0 4px;font-size:14px;color:#e6d7b5}
.iamp-box input[type=text],.iamp-box input[type=email]{width:100%;padding:12px;border-radius:8px;border:1px solid #c9a14a;background:#13264a;color:#fff;font-size:16px;box-sizing:border-box}
.iamp-box button{margin-top:18px;width:100%;padding:14px;border:0;border-radius:8px;background:#c9a14a;color:#0b1a33;font-weight:700;font-size:16px;cursor:pointer}
.iamp-msg{padding:12px 14px;border-radius:8px;margin-bottom:14px}
.iamp-ok{background:#1f4d2f;color:#d9ffe4}.iamp-err{background:#5a1f1f;color:#ffe0e0}
.iamp-list{list-style:none;padding:0;margin:0}
.iamp-list li{padding:12px 0;border-bottom:1px solid rgba(201,161,74,.3)}
.iamp-list a{color:#c9a14a;font-weight:600;text-decoration:none}
.iamp-list small{display:block;color:#bfb293;margin-top:3px}
</style>';
	}

	/**
	 * [iam_aktivizo titulli="Aktivizo qasjen tënde"]
	 */
	public static function activate_form( $atts ) {
		$atts = shortcode_atts( array( 'titulli' => 'Aktivizo qasjen tënde' ), $atts );
		$msg  = '';

		if ( isset( $_POST['iamp_code_submit'] ) ) {
			$msg = self::process_code();
		}

		$logged = is_user_logged_in();
		ob_start();
		echo self::style(); // phpcs:ignore
		?>
		<div class="iamp-box">
			<h3><?php echo esc_html( $atts['titulli'] ); ?></h3>
			<?php echo $msg; // phpcs:ignore ?>
			<form method="post">
				<?php wp_nonce_field( 'iamp_code', 'iamp_code_nonce' ); ?>
				<label for="iamp_code">Kodi i aktivizimit</label>
				<input type="text" id="iamp_code" name="iamp_code" required placeholder="IAM-XXXX-XXXX" autocomplete="off">
				<?php if ( ! $logged ) : ?>
					<label for="iamp_name">Emri</label>
					<input type="text" id="iamp_name" name="iamp_name" required>
					<label for="iamp_email">Email-i</label>
					<input type="email" id="iamp_email" name="iamp_email" required>
				<?php endif; ?>
				<div style="position:absolute;left:-9999px" aria-hidden="true"><input type="text" name="iamp_hp" tabindex="-1" autocomplete="off"></div>
				<button type="submit" name="iamp_code_submit" value="1">Aktivizo</button>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function process_code() {
		$err = function ( $t ) {
			return '<div class="iamp-msg iamp-err">' . esc_html( $t ) . '</div>';
		};

		if ( ! isset( $_POST['iamp_code_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['iamp_code_nonce'] ) ), 'iamp_code' ) ) {
			return $err( 'Sesioni skadoi. Rifresko faqen dhe provo sërish.' );
		}
		if ( ! empty( $_POST['iamp_hp'] ) ) {
			return $err( 'Kërkesa u refuzua.' );
		}

		// Mbrojtje nga provat e shumta: 6 përpjekje / 15 minuta për IP.
		$rk    = 'iamp_rl_' . md5( IAMP_Log::ip() );
		$tries = (int) get_transient( $rk );
		if ( $tries >= 6 ) {
			return $err( 'Shumë përpjekje. Provo përsëri pas 15 minutash.' );
		}
		set_transient( $rk, $tries + 1, 15 * MINUTE_IN_SECONDS );

		$code = isset( $_POST['iamp_code'] ) ? sanitize_text_field( wp_unslash( $_POST['iamp_code'] ) ) : '';
		$args = array( 'send_email' => true );

		if ( is_user_logged_in() ) {
			$args['user_id'] = get_current_user_id();
		} else {
			$email = isset( $_POST['iamp_email'] ) ? sanitize_email( wp_unslash( $_POST['iamp_email'] ) ) : '';
			$name  = isset( $_POST['iamp_name'] ) ? sanitize_text_field( wp_unslash( $_POST['iamp_name'] ) ) : '';
			if ( ! is_email( $email ) ) {
				return $err( 'Shkruaj një email të vlefshëm.' );
			}
			$parts              = explode( ' ', $name, 2 );
			$args['email']      = $email;
			$args['first_name'] = $parts[0];
			$args['last_name']  = isset( $parts[1] ) ? $parts[1] : '';
		}

		$res = IAMP_Codes::redeem( $code, $args );
		if ( is_wp_error( $res ) ) {
			return $err( $res->get_error_message() );
		}

		delete_transient( $rk );
		$login = IAMP_Settings::login_url();
		$text  = is_user_logged_in()
			? 'Qasja jote u aktivizua! Kurset i gjen te faqja jote e anëtarit.'
			: 'Qasja u aktivizua! Të dërguam një email me hapat për t\'u kyçur.';
		return '<div class="iamp-msg iamp-ok">' . esc_html( $text ) . ' <a href="' . esc_url( $login ) . '" style="color:#fff;text-decoration:underline">Hyr këtu</a></div>';
	}

	/**
	 * [iam_kurset_e_mia]
	 */
	public static function my_courses() {
		if ( ! is_user_logged_in() ) {
			return self::style() . '<div class="iamp-box"><p>Kyçu për të parë kurset e tua.</p><a href="' . esc_url( IAMP_Settings::login_url() ) . '" style="color:#c9a14a">Hyr</a></div>';
		}
		$rows = IAMP_DB::get_user_memberships( get_current_user_id(), 'active' );
		ob_start();
		echo self::style(); // phpcs:ignore
		echo '<div class="iamp-box"><h3>Kurset e mia</h3>';
		if ( ! $rows ) {
			echo '<p>Ende nuk ke anëtarësi aktive.</p>';
		} else {
			echo '<ul class="iamp-list">';
			$seen = array();
			foreach ( $rows as $m ) {
				$exp = $m->expires_at ? 'Aktive deri më ' . date_i18n( 'd.m.Y', strtotime( $m->expires_at ) ) : 'Qasje e përhershme';
				foreach ( IAMP_DB::string_to_ids( $m->courses ) as $cid ) {
					if ( isset( $seen[ $cid ] ) ) {
						continue;
					}
					$seen[ $cid ] = 1;
					echo '<li><a href="' . esc_url( get_permalink( $cid ) ) . '">' . esc_html( get_the_title( $cid ) ) . '</a><small>' . esc_html( $exp ) . '</small></li>';
				}
			}
			echo '</ul>';
		}
		echo '</div>';
		return ob_get_clean();
	}
}
