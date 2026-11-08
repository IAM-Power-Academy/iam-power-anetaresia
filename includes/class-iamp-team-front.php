<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Faqet e Skuadrës brenda kursit privat LearnDash:
 *  [iam_regjistro_shitjen]  — forma e shitjes
 *  [iam_paneli_im]          — shitjet dhe bonuset e trajnerit të kyçur
 */
class IAMP_Team_Front {

	public static function init() {
		add_shortcode( 'iam_regjistro_shitjen', array( __CLASS__, 'form' ) );
		add_shortcode( 'iam_paneli_im', array( __CLASS__, 'panel' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_post' ) );
		add_action( 'template_redirect', array( __CLASS__, 'protect' ), 1 );
		add_action( 'pre_get_posts', array( __CLASS__, 'hide_from_queries' ) );
		foreach ( array( 'sfwd-courses', 'sfwd-lessons', 'sfwd-topic' ) as $pt ) {
			add_filter( 'rest_prepare_' . $pt, array( __CLASS__, 'hide_from_rest' ), 10, 2 );
		}
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'hide_from_sitemap' ) );
	}

	/**
	 * ID-të e kursit të Skuadrës dhe të mësimeve/temave të tij.
	 */
	public static function protected_ids() {
		static $ids = null;
		if ( null !== $ids ) {
			return $ids;
		}
		$ids    = array();
		$course = (int) get_option( 'iamp_team_course' );
		if ( ! $course ) {
			return $ids;
		}
		$ids[] = $course;
		foreach ( (array) get_option( 'iamp_team_lessons', array() ) as $l ) {
			if ( $l ) {
				$ids[] = (int) $l;
			}
		}
		global $wpdb;
		$children = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'course_id' AND meta_value = %d", $course ) ); // phpcs:ignore
		foreach ( $children as $c ) {
			$ids[] = (int) $c;
		}
		$ids = array_values( array_unique( $ids ) );
		return $ids;
	}

	public static function can_see() {
		return is_user_logged_in() && ( IAMP_Sales::is_team() || current_user_can( 'manage_options' ) );
	}

	/**
	 * Kush nuk është në Skuadër: faqja nuk ekziston (404). Nuk shihet as titulli, as çmimi, as mësimet.
	 */
	public static function protect() {
		if ( is_admin() || self::can_see() || ! is_singular() ) {
			return;
		}
		$id = (int) get_queried_object_id();
		if ( $id && in_array( $id, self::protected_ids(), true ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	public static function hide_from_queries( $q ) {
		if ( is_admin() || self::can_see() ) {
			return;
		}
		$ids = self::protected_ids();
		if ( ! $ids ) {
			return;
		}
		if ( $q->is_singular() && ! $q->is_main_query() ) {
			return;
		}
		if ( $q->is_main_query() && $q->is_singular() ) {
			return; // Trajtohet nga protect() me 404.
		}
		$not = (array) $q->get( 'post__not_in' );
		$q->set( 'post__not_in', array_merge( $not, $ids ) );
	}

	public static function hide_from_rest( $response, $post ) {
		if ( self::can_see() ) {
			return $response;
		}
		if ( in_array( (int) $post->ID, self::protected_ids(), true ) ) {
			return new WP_Error( 'rest_post_invalid_id', 'Nuk u gjet.', array( 'status' => 404 ) );
		}
		return $response;
	}

	public static function hide_from_sitemap( $args ) {
		$args['post__not_in'] = array_merge( isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array(), self::protected_ids() );
		return $args;
	}

	private static function css() {
		static $done = false;
		if ( $done ) {
			return '';
		}
		$done = true;
		return '<style>
.iamt{font-family:inherit;color:#1f2633;max-width:980px;margin:0 auto}
.iamt-card{background:#fff;border:1px solid #e6dfcf;border-radius:14px;padding:24px;margin:0 0 20px;box-shadow:0 2px 10px rgba(11,26,51,.05)}
.iamt-head{background:#0b1a33;color:#fff;border-radius:14px;padding:22px 26px;margin-bottom:20px;border-bottom:4px solid #c9a14a}
.iamt-head h2{color:#fff;margin:0 0 4px;font-size:22px}.iamt-head p{margin:0;color:#d8c9a3}
.iamt label{display:block;font-weight:600;font-size:14px;margin:14px 0 6px;color:#0b1a33}
.iamt input[type=text],.iamt input[type=email],.iamt input[type=number],.iamt input[type=date],.iamt select,.iamt textarea{width:100%;padding:11px 12px;border:1px solid #d5ccb8;border-radius:9px;font-size:15px;background:#fffdf8;box-sizing:border-box}
.iamt-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 18px}
.iamt-btn{display:inline-block;margin-top:20px;background:#c9a14a;color:#0b1a33;border:0;border-radius:10px;padding:14px 26px;font-weight:700;font-size:16px;cursor:pointer}
.iamt-msg{padding:13px 16px;border-radius:10px;margin-bottom:16px;font-weight:600}
.iamt-ok{background:#ddf3e4;color:#1d6b35}.iamt-err{background:#fde3e3;color:#9b1c1c}
.iamt-hint{font-size:13px;color:#6b7280;margin-top:4px}
.iamt-check{display:flex;gap:10px;align-items:flex-start;font-weight:500!important}
.iamt-courses{max-height:170px;overflow:auto;border:1px solid #e6dfcf;border-radius:9px;padding:8px 12px;background:#fffdf8}
.iamt-courses label{font-weight:400;margin:4px 0}
.iamt-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}
.iamt-kpi{background:#fbf6ea;border-radius:12px;padding:16px;border-bottom:3px solid #c9a14a}
.iamt-kpi span{display:block;font-size:12px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.4px}
.iamt-kpi strong{display:block;font-size:24px;color:#0b1a33;margin-top:6px}
.iamt-kpi.green{border-color:#1d6b35}.iamt-kpi.green strong{color:#1d6b35}
.iamt table{width:100%;border-collapse:collapse;font-size:14px}
.iamt th{background:#0b1a33;color:#fff;text-align:left;padding:10px;font-weight:600}
.iamt td{padding:9px 10px;border-bottom:1px solid #eee6d6;vertical-align:top}
.iamt tr:nth-child(even) td{background:#faf8f3}
.iamt-b{display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700}
.iamt-switch{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:16px;font-weight:600;color:#6b7280}
.iamt-switch a{padding:8px 16px;border-radius:20px;background:#fbf6ea;color:#0b1a33;text-decoration:none;border:1px solid #e6dfcf}
.iamt-switch a.on{background:#0b1a33;color:#fff;border-color:#0b1a33}
.iamt-b.pending{background:#ffe9c7;color:#9a5b00}.iamt-b.approved{background:#ddf3e4;color:#1d6b35}.iamt-b.rejected{background:#fde3e3;color:#9b1c1c}
@media(max-width:700px){.iamt-grid,.iamt-kpis{grid-template-columns:1fr 1fr}.iamt td,.iamt th{font-size:12px;padding:7px}}
</style>';
	}

	private static function guard() {
		if ( ! is_user_logged_in() ) {
			return '<div class="iamt"><div class="iamt-msg iamt-err">Kyçu me llogarinë tënde të Skuadrës.</div></div>';
		}
		if ( ! IAMP_Sales::is_team() && ! current_user_can( 'manage_options' ) ) {
			return '<div class="iamt"><div class="iamt-msg iamt-err">Kjo faqe është vetëm për Skuadrën IAM Power.</div></div>';
		}
		return '';
	}

	private static function eur( $v ) {
		return '€' . number_format( (float) $v, 2, ',', '.' );
	}

	/* ---------------- Forma ---------------- */

	public static function handle_post() {
		if ( empty( $_POST['iamp_sale_submit'] ) ) {
			return;
		}
		if ( ! is_user_logged_in() || ( ! IAMP_Sales::is_team() && ! current_user_can( 'manage_options' ) ) ) {
			return;
		}
		if ( ! isset( $_POST['iamp_sale_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['iamp_sale_nonce'] ) ), 'iamp_sale' ) ) {
			set_transient( 'iamp_sale_msg_' . get_current_user_id(), array( 'err', 'Sesioni skadoi. Provo sërish.' ), 120 );
			return;
		}
		$p = function ( $k ) {
			return isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : ''; // phpcs:ignore
		};

		// Dëshmia e pagesës nuk ruhet në WordPress: dërgohet te dosja e fletës në Google Drive.
		$proof = array();
		if ( ! empty( $_FILES['iamp_proof']['name'] ) && ! empty( $_FILES['iamp_proof']['tmp_name'] ) && is_uploaded_file( $_FILES['iamp_proof']['tmp_name'] ) ) { // phpcs:ignore
			$fname = sanitize_file_name( wp_unslash( $_FILES['iamp_proof']['name'] ) ); // phpcs:ignore
			$ext   = strtolower( pathinfo( $fname, PATHINFO_EXTENSION ) );
			$types = array( 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic', 'pdf' => 'application/pdf' );
			$size  = (int) $_FILES['iamp_proof']['size']; // phpcs:ignore
			if ( isset( $types[ $ext ] ) && $size > 0 && $size <= 8 * MB_IN_BYTES ) {
				$raw = file_get_contents( $_FILES['iamp_proof']['tmp_name'] ); // phpcs:ignore
				if ( false !== $raw ) {
					$proof = array( 'emri' => $fname, 'tipi' => $types[ $ext ], 'b64' => base64_encode( $raw ) ); // phpcs:ignore
				}
			}
			@unlink( $_FILES['iamp_proof']['tmp_name'] ); // phpcs:ignore
		}

		$trainer = get_current_user_id();
		if ( current_user_can( 'manage_options' ) && $p( 'iamp_trainer' ) ) {
			$trainer = absint( $p( 'iamp_trainer' ) );
		}

		$res = IAMP_Sales::create(
			array(
				'trainer_id'           => $trainer,
				'client_name'          => $p( 'iamp_client_name' ),
				'client_email'         => sanitize_email( $p( 'iamp_client_email' ) ),
				'client_phone'         => $p( 'iamp_client_phone' ),
				'package_id'           => absint( $p( 'iamp_package' ) ),
				'extra_courses'        => isset( $_POST['iamp_extra'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['iamp_extra'] ) ) : array(), // phpcs:ignore
				'amount'               => (float) str_replace( ',', '.', $p( 'iamp_amount' ) ),
				'currency'             => $p( 'iamp_currency' ),
				'payment_method'       => $p( 'iamp_method' ),
				'payment_ref'          => $p( 'iamp_ref' ),
				'payment_date'         => $p( 'iamp_paydate' ),
				'proof'                => $proof,
				'collected_by_trainer' => ! empty( $_POST['iamp_collected'] ),
				'note'                 => isset( $_POST['iamp_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['iamp_note'] ) ) : '',
			)
		);
		if ( is_wp_error( $res ) ) {
			set_transient( 'iamp_sale_msg_' . get_current_user_id(), array( 'err', $res->get_error_message() ), 120 );
		} else {
			set_transient( 'iamp_sale_msg_' . get_current_user_id(), array( 'ok', 'Shitja u regjistrua. Klienti aktivizohet sapo Mimoza ta konfirmojë.' ), 120 );
			$back = $p( 'iamp_return' );
			$back = $back ? $back : ( wp_get_referer() ? wp_get_referer() : home_url( '/' ) );
			wp_safe_redirect( add_query_arg( 'u', (int) $res, $back ) );
			exit;
		}
	}

	private static function message() {
		$k = 'iamp_sale_msg_' . get_current_user_id();
		$m = get_transient( $k );
		if ( ! $m ) {
			return '';
		}
		delete_transient( $k );
		return '<div class="iamt-msg iamt-' . esc_attr( $m[0] ) . '">' . esc_html( $m[1] ) . '</div>';
	}

	public static function form() {
		$g = self::guard();
		if ( $g ) {
			return self::css() . $g;
		}
		$methods  = array( 'Ko-fi', 'Bankë (Sun and Stars)', 'Western Union', 'Ria', 'MoneyGram', 'PayPal', 'Digistore24', 'Kartelë', 'Kesh' );
		$packages = IAMP_DB::get_packages( true );
		ob_start();
		echo self::css(); // phpcs:ignore
		?>
		<div class="iamt">
			<div class="iamt-head"><h2>Regjistro shitjen</h2><p>Një formë për çdo blerje. Klienti aktivizohet pasi Mimoza ta konfirmojë.</p></div>
			<?php echo self::message(); // phpcs:ignore ?>
			<form class="iamt-card" method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'iamp_sale', 'iamp_sale_nonce' ); ?>
				<input type="hidden" name="iamp_return" value="<?php echo esc_url( get_permalink() ); ?>">
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<label>Trajneri</label>
					<select name="iamp_trainer"><option value="">Unë</option>
						<?php foreach ( IAMP_Sales::team() as $uid => $name ) : ?>
							<option value="<?php echo (int) $uid; ?>"><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>
				<div class="iamt-grid">
					<div><label>Emri dhe mbiemri i klientit *</label><input type="text" name="iamp_client_name" required></div>
					<div><label>Telefoni</label><input type="text" name="iamp_client_phone" placeholder="+383 ..."></div>
				</div>
				<label>Email-i i klientit *</label>
				<input type="email" name="iamp_client_email" required>
				<div class="iamt-hint">Email-i me të cilin klienti hyn në iampower.al. Nëse s'ka llogari, krijohet vetë.</div>

				<label>Kursi ose oferta *</label>
				<select name="iamp_package" required>
					<option value="">Zgjidh…</option>
					<?php foreach ( $packages as $pkg ) : ?>
						<option value="<?php echo (int) $pkg->id; ?>"><?php echo esc_html( $pkg->name . ( (float) $pkg->price > 0 ? ' · €' . rtrim( rtrim( number_format( (float) $pkg->price, 2, '.', '' ), '0' ), '.' ) : '' ) ); ?></option>
					<?php endforeach; ?>
				</select>

				<div class="iamt-grid">
					<div><label>Shuma e paguar *</label><input type="number" step="0.01" min="0" name="iamp_amount" required></div>
					<div><label>Valuta</label><select name="iamp_currency"><option>EUR</option><option>CHF</option><option>USD</option><option>GBP</option><option>ALL</option><option>MKD</option></select></div>
					<div><label>Mënyra e pagesës *</label><select name="iamp_method" required><option value="">Zgjidh…</option>
						<?php foreach ( $methods as $m ) : ?><option><?php echo esc_html( $m ); ?></option><?php endforeach; ?>
					</select></div>
					<div><label>Data e pagesës</label><input type="date" name="iamp_paydate" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>"></div>
				</div>
				<label>Nr. i transaksionit / referenca</label>
				<input type="text" name="iamp_ref" placeholder="p.sh. MTCN, kodi Ko-fi, nr. bankar">
				<label>Foto ose PDF e pagesës</label>
				<input type="file" name="iamp_proof" accept="image/*,application/pdf">
				<label class="iamt-check"><input type="checkbox" name="iamp_collected" value="1"> Pagesën e mora unë dhe e mbajta bonusin si provizion</label>

				<label>Bonus falas shtesë (vetëm nëse klienti merr diçka përtej ofertës)</label>
				<div class="iamt-courses">
					<?php foreach ( IAMP_Access::courses() as $c ) : ?>
						<label><input type="checkbox" name="iamp_extra[]" value="<?php echo (int) $c->ID; ?>"> <?php echo esc_html( $c->post_title ); ?></label>
					<?php endforeach; ?>
				</div>

				<label>Shënime</label>
				<textarea name="iamp_note" rows="3" placeholder="p.sh. kësti i parë, ofertë ditëlindjeje…"></textarea>
				<button class="iamt-btn" type="submit" name="iamp_sale_submit" value="1">Regjistro shitjen</button>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ---------------- Paneli im ---------------- */

	public static function panel() {
		$g = self::guard();
		if ( $g ) {
			return self::css() . $g;
		}
		$uid = get_current_user_id();
		if ( current_user_can( 'manage_options' ) ) {
			if ( ! empty( $_GET['trajneri'] ) ) { // phpcs:ignore
				$uid = absint( $_GET['trajneri'] ); // phpcs:ignore
			} elseif ( ! IAMP_Sales::is_team( $uid ) ) {
				$ids = array_keys( IAMP_Sales::team() );
				$uid = $ids ? (int) $ids[0] : $uid;
			}
		}
		$sum   = IAMP_Sales::summary( $uid );
		$pays  = IAMP_Sales::payouts( $uid );
		ob_start();
		echo self::css(); // phpcs:ignore
		if ( class_exists( 'IAMP_Admin_Stats' ) ) {
			echo IAMP_Admin_Stats::front_block(); // phpcs:ignore -- vetëm për administratoren.
		}
		?>
		<div class="iamt">
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<div class="iamt-switch"><span>Shiko panelin e:</span>
					<?php foreach ( IAMP_Sales::team() as $tid => $tname ) : ?>
						<a class="<?php echo (int) $tid === (int) $uid ? 'on' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'trajneri', (int) $tid, get_permalink() ) ); ?>"><?php echo esc_html( $tname ); ?></a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<div class="iamt-head"><h2>Paneli im — <?php echo esc_html( IAMP_Sales::trainer_name( $uid ) ); ?></h2><p>Totalet e shitjeve dhe bonusi yt. I sheh vetëm ti dhe Mimoza.</p></div>
			<div class="iamt-kpis">
				<div class="iamt-kpi"><span>Shitje këtë muaj</span><strong><?php echo (int) $sum['month_n']; ?></strong></div>
				<div class="iamt-kpi"><span>Bonus këtë muaj</span><strong><?php echo esc_html( self::eur( $sum['month_c'] ) ); ?></strong></div>
				<div class="iamt-kpi"><span>Presin konfirmim</span><strong><?php echo (int) $sum['pending_n']; ?></strong></div>
				<div class="iamt-kpi green"><span>Bonus për të marrë</span><strong><?php echo esc_html( self::eur( $sum['balance'] ) ); ?></strong></div>
			</div>
			<div class="iamt-card">
				<h3 style="margin-top:0">Llogaria e bonusit</h3>
				<table>
					<tr><td>Saldo nga llogaritë e mëparshme</td><td style="text-align:right"><?php echo esc_html( self::eur( $sum['opening'] ) ); ?></td></tr>
					<tr><td>Bonus nga shitjet e konfirmuara (<?php echo (int) $sum['count']; ?>)</td><td style="text-align:right">+ <?php echo esc_html( self::eur( $sum['earned'] ) ); ?></td></tr>
					<tr><td>Mbajtur vetë si provizion</td><td style="text-align:right">− <?php echo esc_html( self::eur( $sum['kept'] ) ); ?></td></tr>
					<tr><td>Paguar nga IAM Power</td><td style="text-align:right">− <?php echo esc_html( self::eur( $sum['paid'] ) ); ?></td></tr>
					<tr><td><strong>Për të marrë</strong></td><td style="text-align:right"><strong><?php echo esc_html( self::eur( $sum['balance'] ) ); ?></strong></td></tr>
				</table>
			</div>
			<div class="iamt-card">
				<h3 style="margin-top:0">Shitjet e mia</h3>
				<table>
					<tr><td>Shitje të konfirmuara</td><td style="text-align:right"><?php echo (int) $sum['count']; ?> · <?php echo esc_html( self::eur( $sum['sold'] ) ); ?></td></tr>
					<tr><td>Presin konfirmim nga Mimoza</td><td style="text-align:right"><?php echo (int) $sum['pending_n']; ?> · bonus <?php echo esc_html( self::eur( $sum['pending_c'] ) ); ?></td></tr>
					<tr><td>Këtë muaj</td><td style="text-align:right"><?php echo (int) $sum['month_n']; ?> · <?php echo esc_html( self::eur( $sum['month_sold'] ) ); ?></td></tr>
				</table>
				<?php if ( empty( $sum['sheet_ok'] ) ) : ?><p class="iamt-hint">Shifrat po përditësohen. Rifresko faqen pas pak minutash.</p><?php endif; ?>
			</div>
			<?php if ( $pays ) : ?>
			<div class="iamt-card">
				<h3 style="margin-top:0">Pagesat e bonusit</h3>
				<table>
					<tr><th>Data</th><th>Shuma</th><th>Mënyra</th><th>Shënim</th></tr>
					<?php foreach ( $pays as $p ) : ?>
						<tr><td><?php echo esc_html( mysql2date( 'd.m.Y', $p->paid_on ) ); ?></td>
							<td><?php echo esc_html( 'opening' === $p->kind ? 'Saldo fillestare ' . self::eur( -1 * $p->amount ) : self::eur( $p->amount ) ); ?></td>
							<td><?php echo esc_html( $p->method ); ?></td><td><?php echo esc_html( $p->note ); ?></td></tr>
					<?php endforeach; ?>
				</table>
			</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
