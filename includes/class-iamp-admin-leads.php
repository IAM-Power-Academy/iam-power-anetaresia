<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * IAM Anëtarët → Kontaktet: pasqyra për Mimozën (kush ka sa kontakte, sa janë të prekura, sa të vonuara),
 * importi nga CSV dhe cilësimet e ndarjes.
 */
class IAMP_Admin_Leads {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 21 );
		add_action( 'admin_post_iamp_leads_import', array( __CLASS__, 'import' ) );
		add_action( 'admin_post_iamp_leads_save', array( __CLASS__, 'save' ) );
	}

	public static function menu() {
		add_submenu_page( 'iamp', 'Kontaktet', 'Kontaktet', 'manage_options', 'iamp-leads', array( __CLASS__, 'page' ) );
	}

	private static function back( $msg, $type = 'success' ) {
		set_transient( 'iamp_notice_' . get_current_user_id(), array( 'm' => $msg, 't' => $type ), 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=iamp-leads' ) );
		exit;
	}

	public static function import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nuk ke leje.' );
		}
		check_admin_referer( 'iamp_leads_import' );
		if ( empty( $_FILES['csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) { // phpcs:ignore
			self::back( 'Zgjidh një file CSV.', 'error' );
		}
		$r = IAMP_Leads::import_csv( $_FILES['csv']['tmp_name'] ); // phpcs:ignore
		if ( is_wp_error( $r ) ) {
			self::back( $r->get_error_message(), 'error' );
		}
		self::back( sprintf( 'U shtuan %d kontakte. Dublikatë të kapur: %d. Të anashkaluar: %d.', $r['created'], $r['duplicate'], $r['skipped'] ) );
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nuk ke leje.' );
		}
		check_admin_referer( 'iamp_leads_save' );
		$weights = array();
		if ( ! empty( $_POST['w'] ) && is_array( $_POST['w'] ) ) {
			foreach ( wp_unslash( $_POST['w'] ) as $name => $v ) { // phpcs:ignore
				$weights[ strtolower( sanitize_text_field( $name ) ) ] = max( 0, (float) str_replace( ',', '.', $v ) );
			}
		}
		IAMP_Settings::update(
			array(
				'lead_weights'      => $weights,
				'lead_from_activations' => empty( $_POST['from_act'] ) ? 0 : 1,
				'lead_overdue_days' => max( 1, absint( isset( $_POST['overdue'] ) ? $_POST['overdue'] : 5 ) ), // phpcs:ignore
				'lead_wa_template'  => sanitize_textarea_field( isset( $_POST['wa'] ) ? wp_unslash( $_POST['wa'] ) : '' ), // phpcs:ignore
			)
		);
		self::back( 'Cilësimet u ruajtën.' );
	}

	public static function page() {
		$stats    = IAMP_Leads::stats();
		$weights  = IAMP_Leads::weights();
		$ids      = (array) get_option( 'iamp_team_lessons', array() );
		$list_url = ! empty( $ids[4] ) ? get_permalink( (int) $ids[4] ) : '';
		$tot_w    = 0;
		foreach ( IAMP_Leads::trainers() as $name ) {
			$tot_w += isset( $weights[ strtolower( trim( $name ) ) ] ) ? (float) $weights[ strtolower( trim( $name ) ) ] : 0;
		}

		$n = get_transient( 'iamp_notice_' . get_current_user_id() );
		echo '<div class="wrap"><h1>Kontaktet e Skuadrës</h1>';
		if ( $n ) {
			delete_transient( 'iamp_notice_' . get_current_user_id() );
			echo '<div class="notice notice-' . esc_attr( $n['t'] ) . ' is-dismissible"><p>' . esc_html( $n['m'] ) . '</p></div>';
		}

		echo '<div class="card" style="max-width:none"><h2>Pasqyra sipas trajnereve</h2>';
		if ( ! $stats ) {
			echo '<p>Nuk ka anëtarë në Skuadër. Shtoji te "Skuadra & Bonuset".</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Trajnerja</th><th>Gjithsej</th><th>Pa kontaktuar</th><th>Nuk u përgjigj</th><th>Foli</th><th>Rikontakto</th><th>Bleu</th><th>Jo</th><th>Të vonuara</th><th></th></tr></thead><tbody>';
			foreach ( $stats as $uid => $s ) {
				$url = $list_url ? add_query_arg( 'trajneri', $uid, $list_url ) : '';
				echo '<tr><td><strong>' . esc_html( $s['name'] ) . '</strong></td><td>' . (int) $s['total'] . '</td><td>' . (int) $s['new'] . '</td><td>' . (int) $s['noans'] . '</td><td>' . (int) $s['talked'] . '</td><td>' . (int) $s['follow'] . '</td><td>' . (int) $s['bought'] . '</td><td>' . (int) $s['no'] . '</td>';
				echo '<td>' . ( $s['overdue'] ? '<strong style="color:#c0392b">' . (int) $s['overdue'] . '</strong>' : '0' ) . '</td>';
				echo '<td>' . ( $url ? '<a class="button button-small" target="_blank" href="' . esc_url( $url ) . '">Shiko listën</a>' : '' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<p class="description">"Të vonuara" = kontakte të hapura pa lëvizje për ' . (int) IAMP_Settings::get( 'lead_overdue_days' ) . ' ditë ose me datën e rikontaktit të kaluar. Listat e trajnereve i krijon kursi i Skuadrës ("Rikontrollo kursin" te Skuadra & Bonuset shton mësimet "Kontaktet e mia" dhe "Kontakt i ri").</p></div>';

		echo '<div class="card" style="max-width:none"><h2>Importo kontakte (CSV)</h2>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="iamp_leads_import">';
		wp_nonce_field( 'iamp_leads_import' );
		echo '<p>Ngarko CSV-në me kolonat: <code>Emri, Email, Telefon, Sponsori origjinal, Kategoria, Burimi, Pronari i ri (propozim), Statusi i kontaktit, Data e kontaktit, Shënime</code>. Kontaktet që ekzistojnë (i njëjti email ose telefon) anashkalohen, kështu importi mund të përsëritet pa dublikata. Çdo trajnere merr një email të vetëm me sa kontakte i erdhën.</p>';
		echo '<input type="file" name="csv" accept=".csv,text/csv" required> ';
		submit_button( 'Importo', 'primary', 'submit', false );
		echo '</form></div>';

		echo '<div class="card" style="max-width:none"><h2>Cilësimet e ndarjes</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="iamp_leads_save">';
		wp_nonce_field( 'iamp_leads_save' );
		echo '<p>Kontaktet pa trajnere aktive ndahen sipas peshave. Pesha 0 = nuk merr kontakte të reja (vendose 0 për Avni, Mimozën ose llogari testimi). Kontaktet që kanë sponsor aktiv mbeten te ai.</p><table class="form-table">';
		foreach ( IAMP_Leads::trainers() as $name ) {
			$k = strtolower( trim( $name ) );
			$w = isset( $weights[ $k ] ) ? (float) $weights[ $k ] : 0;
			$p = $tot_w > 0 ? round( 100 * $w / $tot_w, 1 ) : 0;
			echo '<tr><th>' . esc_html( $name ) . '</th><td><input type="number" step="0.5" min="0" name="w[' . esc_attr( $k ) . ']" value="' . esc_attr( $w ) . '" style="width:80px"> <span class="description">≈ ' . esc_html( $p ) . '% e kontakteve të reja</span></td></tr>';
		}
		echo '<tr><th>Anëtarët e rinj</th><td><label><input type="checkbox" name="from_act" value="1" ' . checked( (int) IAMP_Settings::get( 'lead_from_activations' ), 1, false ) . '> Çdo anëtar i ri që aktivizohet në iampower.al (nga ti, Avni, kodi ose pagesa automatike) hyn vetë te Kontaktet dhe ndahet te trajnerja.</label></td></tr>';
		echo '<tr><th>Ditë pa lëvizje para "Vonuar"</th><td><input type="number" min="1" name="overdue" value="' . esc_attr( (int) IAMP_Settings::get( 'lead_overdue_days' ) ) . '" style="width:80px"></td></tr>';
		echo '<tr><th>Mesazhi i parë në WhatsApp</th><td><textarea name="wa" rows="3" class="large-text">' . esc_textarea( (string) IAMP_Settings::get( 'lead_wa_template' ) ) . '</textarea><p class="description">Përdor <code>{emri}</code> (emri i klientit) dhe <code>{trajnerja}</code>. Trajnerja mund ta ndryshojë para se ta dërgojë.</p></td></tr>';
		echo '</table>';
		submit_button( 'Ruaj' );
		echo '</form></div></div>';
	}
}
