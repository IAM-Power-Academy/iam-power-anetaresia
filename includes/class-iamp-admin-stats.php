<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Paneli i shitjeve (vetëm për administratoren): linku i Google Sheet-it të shitjeve,
 * tabela e shitjeve sipas viteve/muajve dhe grafikët.
 *
 * Historiku nga Google Sheets ruhet në opsionin `iamp_sales_history` (jo në kod, sepse repo është publike):
 * {
 *   "asof": "2026-11-08",
 *   "years": { "2025": { "t": [12 shuma], "p": [12 të paguara], "n": [12 numri] }, ... }
 * }
 * Shitjet e regjistruara në plugin pas datës "asof" shtohen vetë mbi historikun.
 */
class IAMP_Admin_Stats {

	const OPTION        = 'iamp_sales_history';
	const DEFAULT_SHEET = 'https://docs.google.com/spreadsheets/d/1pY1kcwm61MuUWq5PBQW-gXo03JZH4zpxGFZ_4abC1H0/edit';

	private static $months = array( 'Jan', 'Shk', 'Mar', 'Pri', 'Maj', 'Qer', 'Kor', 'Gus', 'Sht', 'Tet', 'Nën', 'Dhj' );
	private static $colors = array( '#9aa5b8', '#6c8ebf', '#3f6fb5', '#1f4e8c', '#c9a14a', '#e07b39', '#8e5bb5' );

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 15 );
		add_action( 'admin_post_iamp_stats_save', array( __CLASS__, 'save' ) );
	}

	public static function menu() {
		add_submenu_page( 'iamp', 'Paneli i shitjeve', '📊 Paneli i shitjeve', 'manage_options', 'iamp-stats', array( __CLASS__, 'page' ), 1 );
	}

	/* ---------------- Të dhënat ---------------- */

	public static function history() {
		$h = get_option( self::OPTION );
		if ( ! is_array( $h ) ) {
			$h = array();
		}
		$h = wp_parse_args( $h, array( 'asof' => '', 'years' => array(), 'links' => array() ) );
		return $h;
	}

	/** Historiku + shitjet e plugin-it pas datës "asof". */
	public static function data() {
		global $wpdb;
		$h     = self::history();
		$years = array();
		foreach ( (array) $h['years'] as $y => $d ) {
			$years[ (int) $y ] = array(
				't' => self::twelve( isset( $d['t'] ) ? $d['t'] : array() ),
				'p' => self::twelve( isset( $d['p'] ) ? $d['p'] : array() ),
				'n' => self::twelve( isset( $d['n'] ) ? $d['n'] : array() ),
			);
		}
		$t    = IAMP_DB::table( 'sales' );
		$from = $h['asof'] ? $h['asof'] . ' 23:59:59' : '1970-01-01 00:00:00';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT YEAR(created_at) y, MONTH(created_at) m, COUNT(*) n, SUM(amount) s, SUM(CASE WHEN status='approved' THEN amount ELSE 0 END) p FROM $t WHERE status IN ('approved','pending') AND created_at > %s GROUP BY y, m", $from ) ); // phpcs:ignore
		foreach ( (array) $rows as $r ) {
			$y = (int) $r->y;
			$m = (int) $r->m - 1;
			if ( ! isset( $years[ $y ] ) ) {
				$years[ $y ] = array( 't' => array_fill( 0, 12, 0 ), 'p' => array_fill( 0, 12, 0 ), 'n' => array_fill( 0, 12, 0 ) );
			}
			$years[ $y ]['t'][ $m ] += (float) $r->s;
			$years[ $y ]['p'][ $m ] += (float) $r->p;
			$years[ $y ]['n'][ $m ] += (int) $r->n;
		}
		ksort( $years );
		return $years;
	}

	private static function twelve( $a ) {
		$a = array_values( array_map( 'floatval', (array) $a ) );
		return array_slice( array_pad( $a, 12, 0 ), 0, 12 );
	}

	private static function eur( $v, $dec = 0 ) {
		return '€' . number_format( (float) $v, $dec, ',', '.' );
	}

	/* ---------------- Ruajtja ---------------- */

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nuk ke leje.' );
		}
		check_admin_referer( 'iamp_stats_save' );
		$h = self::history();

		$links = array();
		$names = isset( $_POST['link_name'] ) ? (array) wp_unslash( $_POST['link_name'] ) : array(); // phpcs:ignore
		$urls  = isset( $_POST['link_url'] ) ? (array) wp_unslash( $_POST['link_url'] ) : array(); // phpcs:ignore
		foreach ( $urls as $i => $u ) {
			$u = esc_url_raw( trim( $u ) );
			if ( $u ) {
				$links[] = array( 'name' => sanitize_text_field( isset( $names[ $i ] ) ? $names[ $i ] : '' ), 'url' => $u );
			}
		}
		$h['links'] = $links;

		$msg  = 'Linqet u ruajtën.';
		$type = 'success';
		$json = isset( $_POST['history_json'] ) ? trim( wp_unslash( $_POST['history_json'] ) ) : ''; // phpcs:ignore
		if ( '' !== $json ) {
			$d = json_decode( $json, true );
			if ( ! is_array( $d ) || empty( $d['years'] ) || ! is_array( $d['years'] ) ) {
				$msg  = 'Historiku nuk u ndryshua: të dhënat nuk janë në formatin e duhur.';
				$type = 'error';
			} else {
				$years = array();
				foreach ( $d['years'] as $y => $v ) {
					$y = (int) $y;
					if ( $y >= 2000 && $y <= 2100 && is_array( $v ) ) {
						$years[ $y ] = array(
							't' => self::twelve( isset( $v['t'] ) ? $v['t'] : array() ),
							'p' => self::twelve( isset( $v['p'] ) ? $v['p'] : array() ),
							'n' => array_map( 'intval', self::twelve( isset( $v['n'] ) ? $v['n'] : array() ) ),
						);
					}
				}
				$asof       = isset( $d['asof'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d['asof'] ) ? $d['asof'] : current_time( 'Y-m-d' );
				$h['years'] = $years;
				$h['asof']  = $asof;
				$msg        = 'Historiku i shitjeve u përditësua (' . count( $years ) . ' vite, deri më ' . mysql2date( 'd.m.Y', $asof ) . ').';
				IAMP_Log::add( 'stats', get_current_user_id(), 'Historiku i shitjeve u përditësua deri më ' . $asof );
			}
		}
		update_option( self::OPTION, $h, false );
		set_transient( 'iamp_notice_' . get_current_user_id(), array( 'm' => $msg, 't' => $type ), 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=iamp-stats' ) );
		exit;
	}

	/* ---------------- Faqja ---------------- */

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nuk ke leje.' );
		}
		$h     = self::history();
		$years = self::data();
		$now_y = (int) current_time( 'Y' );
		$now_m = (int) current_time( 'n' );

		echo '<div class="wrap iamp-wrap"><div class="iamp-head"><div class="iamp-logo">IAM POWER</div><div><h1>Paneli i shitjeve</h1><p>Vetëm për ty. Skuadra nuk e sheh këtë faqe.</p></div></div>';
		self::styles();

		// Linqet.
		echo '<div class="iamp-stats-links">';
		echo '<a class="button button-primary button-hero" target="_blank" rel="noopener" href="' . esc_url( self::DEFAULT_SHEET ) . '">📗 Hap regjistrin e shitjeve në Google</a>';
		foreach ( $h['links'] as $l ) {
			echo '<a class="button button-hero" target="_blank" rel="noopener" href="' . esc_url( $l['url'] ) . '">🔗 ' . esc_html( $l['name'] ? $l['name'] : 'Link' ) . '</a>';
		}
		echo '</div>';

		if ( ! $years ) {
			echo '<div class="iamp-card"><p>Nuk ka ende të dhëna shitjesh. Shto historikun më poshtë te “Cilësimet e panelit”.</p></div>';
			self::settings_form( $h );
			echo '</div>';
			return;
		}

		// Përmbledhje: krahasimi bëhet me muajt e mbyllur (muaji në vazhdim nuk është i plotë).
		$cmp_m    = max( 1, $now_m - 1 );
		$cur      = isset( $years[ $now_y ] ) ? $years[ $now_y ] : null;
		$prev     = isset( $years[ $now_y - 1 ] ) ? $years[ $now_y - 1 ] : null;
		$cur_ytd  = $cur ? array_sum( $cur['t'] ) : 0;
		$cur_cmp  = $cur ? array_sum( array_slice( $cur['t'], 0, $cmp_m ) ) : 0;
		$prev_cmp = $prev ? array_sum( array_slice( $prev['t'], 0, $cmp_m ) ) : 0;
		$prev_all = $prev ? array_sum( $prev['t'] ) : 0;
		$best_y   = 0;
		$best_v   = 0;
		foreach ( $years as $y => $d ) {
			if ( array_sum( $d['t'] ) > $best_v ) {
				$best_v = array_sum( $d['t'] );
				$best_y = $y;
			}
		}
		$period = 'Jan–' . self::$months[ $cmp_m - 1 ];
		$diff   = $prev_cmp > 0 ? ( $cur_cmp - $prev_cmp ) / $prev_cmp * 100 : 0;
		echo '<div class="iamp-stats">';
		echo '<div><strong>' . esc_html( self::eur( $cur_ytd ) ) . '</strong><span>Shitjet ' . (int) $now_y . ' deri tani</span></div>';
		echo '<div><strong>' . esc_html( self::eur( $cur_cmp ) ) . '</strong><span>' . (int) $now_y . ', ' . esc_html( $period ) . '</span></div>';
		echo '<div><strong>' . esc_html( self::eur( $prev_cmp ) ) . '</strong><span>' . (int) ( $now_y - 1 ) . ', ' . esc_html( $period ) . '</span></div>';
		echo '<div class="' . ( $diff >= 0 ? 'ok' : 'down' ) . '"><strong>' . ( $diff >= 0 ? '▲ +' : '▼ ' ) . esc_html( number_format( $diff, 1, ',', '.' ) ) . '%</strong><span>' . (int) $now_y . ' krahasuar me ' . (int) ( $now_y - 1 ) . ' (' . esc_html( $period ) . ')</span></div>';
		echo '<div><strong>' . esc_html( self::eur( $prev_all ) ) . '</strong><span>Gjithë viti ' . (int) ( $now_y - 1 ) . '</span></div>';
		echo '<div><strong>' . (int) $best_y . '</strong><span>Viti më i mirë: ' . esc_html( self::eur( $best_v ) ) . '</span></div>';
		echo '</div>';

		// Grafikët nuk tregojnë vitet me pak muaj të dhëna (p.sh. 2022 ka vetëm dhjetorin).
		$chart_years = array();
		foreach ( $years as $y => $d ) {
			if ( $y === $now_y || count( array_filter( $d['t'] ) ) >= 3 ) {
				$chart_years[ $y ] = $d;
			}
		}

		// Grafikët.
		echo '<div class="iamp-grid">';
		echo '<div class="iamp-card"><h2>Si po ecim: shitjet e grumbulluara gjatë vitit</h2><p class="description">Çdo vijë tregon sa ishin shitjet gjithsej deri në atë muaj. Vija e artë është ' . (int) $now_y . '.</p>';
		echo self::chart_cumulative( $chart_years, $now_y, $now_m ); // phpcs:ignore
		echo '</div>';
		echo '<div class="iamp-card"><h2>Shitjet sipas viteve</h2><p class="description">Totali i çdo viti. Pjesa e çelët te ' . (int) $now_y . ' është vlera e vitit të kaluar për muajt që mbeten.</p>';
		echo self::chart_years( $chart_years, $now_y ); // phpcs:ignore
		echo '</div>';
		echo '</div>';

		echo '<div class="iamp-card"><h2>Shitjet çdo muaj</h2>';
		echo self::chart_monthly( $chart_years, $now_y ); // phpcs:ignore
		echo '</div>';

		// Tabela.
		echo '<div class="iamp-card"><h2>Tabela e shitjeve sipas viteve dhe muajve</h2><div style="overflow-x:auto"><table class="widefat striped iamp-yeartable"><thead><tr><th>Viti</th>';
		foreach ( self::$months as $m ) {
			echo '<th>' . esc_html( $m ) . '</th>';
		}
		echo '<th>Totali</th><th>Paguar</th><th>Shitje</th><th>Ndryshimi</th></tr></thead><tbody>';
		$last = null;
		foreach ( array_reverse( $years, true ) as $y => $d ) {
			$tot = array_sum( $d['t'] );
			echo '<tr' . ( $y === $now_y ? ' class="iamp-cur"' : '' ) . '><th>' . (int) $y . ( $y === $now_y ? ' <small>(deri tani)</small>' : '' ) . '</th>';
			foreach ( $d['t'] as $i => $v ) {
				$future = ( $y === $now_y && $i >= $now_m );
				echo '<td>' . ( $future ? '<span class="iamp-muted">–</span>' : ( $v ? esc_html( self::eur( $v ) ) : '<span class="iamp-muted">0</span>' ) ) . '</td>';
			}
			$p_prev = 0;
			$c_tot  = $tot;
			if ( isset( $years[ $y - 1 ] ) && count( array_filter( $years[ $y - 1 ]['t'] ) ) >= 6 ) {
				$p_prev = array_sum( $years[ $y - 1 ]['t'] );
				if ( $y === $now_y ) {
					$p_prev = array_sum( array_slice( $years[ $y - 1 ]['t'], 0, $cmp_m ) );
					$c_tot  = $cur_cmp;
				}
			}
			$ch = $p_prev > 0 ? ( $c_tot - $p_prev ) / $p_prev * 100 : null;
			echo '<td><strong>' . esc_html( self::eur( $tot ) ) . '</strong></td><td>' . esc_html( self::eur( array_sum( $d['p'] ) ) ) . '</td><td>' . (int) array_sum( $d['n'] ) . '</td>';
			echo '<td>' . ( null === $ch ? '<span class="iamp-muted">–</span>' : '<span class="' . ( $ch >= 0 ? 'iamp-up' : 'iamp-down' ) . '">' . ( $ch >= 0 ? '▲ +' : '▼ ' ) . esc_html( number_format( $ch, 1, ',', '.' ) ) . '%</span>' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		echo '<p class="description">Totali = të gjitha shitjet e regjistruara. Paguar = vetëm ato me statusin PAID. Ndryshimi për ' . (int) $now_y . ' krahasohet me muajt e mbyllur (' . esc_html( $period ) . ') të ' . (int) ( $now_y - 1 ) . '.';
		if ( $h['asof'] ) {
			echo ' Të dhënat nga Google Sheets deri më <strong>' . esc_html( mysql2date( 'd.m.Y', $h['asof'] ) ) . '</strong>; pas kësaj date shtohen vetë shitjet e regjistruara nga Skuadra në faqe.';
		}
		echo '</p></div>';

		self::settings_form( $h );
		echo '</div>';
	}

	private static function settings_form( $h ) {
		echo '<details class="iamp-card"><summary><strong>Cilësimet e panelit</strong> (linqe të tjera, përditësimi i historikut)</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'iamp_stats_save' );
		echo '<input type="hidden" name="action" value="iamp_stats_save">';
		echo '<h3>Linqe të tjera në krye të panelit</h3><table class="form-table">';
		$links   = $h['links'];
		$links[] = array( 'name' => '', 'url' => '' );
		$links[] = array( 'name' => '', 'url' => '' );
		foreach ( $links as $l ) {
			echo '<tr><td><input type="text" name="link_name[]" placeholder="Emri, p.sh. Paneli MIMOZA" value="' . esc_attr( $l['name'] ) . '"> <input type="url" class="iamp-wide" name="link_url[]" placeholder="https://docs.google.com/..." value="' . esc_attr( $l['url'] ) . '"></td></tr>';
		}
		echo '</table>';
		echo '<h3>Historiku nga Google Sheets</h3><p class="description">Lëre bosh që të mos ndryshojë. Këtë e përditëson Claude kur i kërkon “përditëso panelin e shitjeve”.</p>';
		echo '<textarea name="history_json" rows="4" class="iamp-wide" placeholder="{&quot;asof&quot;:&quot;2026-11-08&quot;,&quot;years&quot;:{...}}"></textarea>';
		submit_button( 'Ruaj' );
		echo '</form></details>';
	}

	/* ---------------- Grafikët (SVG, pa librari të jashtme) ---------------- */

	private static function color( $i, $y, $now_y ) {
		if ( $y === $now_y ) {
			return '#c9a14a';
		}
		$c = self::$colors;
		return $c[ $i % 4 ];
	}

	private static function nice_max( $v ) {
		if ( $v <= 0 ) {
			return 1000;
		}
		$p = pow( 10, floor( log10( $v ) ) );
		foreach ( array( 1, 2, 2.5, 5, 10 ) as $s ) {
			if ( $s * $p >= $v ) {
				return $s * $p;
			}
		}
		return 10 * $p;
	}

	private static function axis( $W, $H, $L, $T, $R, $B, $max, $labels ) {
		$o  = '';
		$ih = $H - $T - $B;
		for ( $i = 0; $i <= 4; $i++ ) {
			$y  = $T + $ih - $ih * $i / 4;
			$v  = $max * $i / 4;
			$o .= '<line x1="' . $L . '" x2="' . ( $W - $R ) . '" y1="' . $y . '" y2="' . $y . '" stroke="#e3e6ec"/>';
			$o .= '<text x="' . ( $L - 6 ) . '" y="' . ( $y + 4 ) . '" text-anchor="end" font-size="11" fill="#6b7280">' . esc_html( $v >= 1000 ? number_format( $v / 1000, $v % 1000 ? 1 : 0, ',', '.' ) . 'k' : (int) $v ) . '</text>';
		}
		$n  = count( $labels );
		$iw = $W - $L - $R;
		foreach ( $labels as $i => $lab ) {
			$x  = $L + $iw * ( $i + 0.5 ) / $n;
			$o .= '<text x="' . $x . '" y="' . ( $H - $B + 16 ) . '" text-anchor="middle" font-size="11" fill="#6b7280">' . esc_html( $lab ) . '</text>';
		}
		return $o;
	}

	private static function legend( $years, $now_y, $W ) {
		$o = '';
		$x = 50;
		$i = 0;
		foreach ( $years as $y => $d ) {
			$o .= '<rect x="' . $x . '" y="4" width="12" height="12" rx="2" fill="' . self::color( $i, $y, $now_y ) . '"/><text x="' . ( $x + 16 ) . '" y="14" font-size="12" fill="#1f2937">' . (int) $y . '</text>';
			$x += 62;
			$i++;
		}
		return $o;
	}

	private static function chart_cumulative( $years, $now_y, $now_m ) {
		$W   = 620;
		$H   = 300;
		$L   = 48;
		$T   = 28;
		$R   = 12;
		$B   = 26;
		$max = 0;
		foreach ( $years as $d ) {
			$max = max( $max, array_sum( $d['t'] ) );
		}
		$max = self::nice_max( $max );
		$iw  = $W - $L - $R;
		$ih  = $H - $T - $B;
		$o   = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" class="iamp-svg" role="img" aria-label="Shitjet e grumbulluara sipas vitit">';
		$o  .= self::legend( $years, $now_y, $W );
		$o  .= self::axis( $W, $H, $L, $T, $R, $B, $max, self::$months );
		$i   = 0;
		foreach ( $years as $y => $d ) {
			$sum = 0;
			$pts = array();
			$end = ( $y === $now_y ) ? $now_m : 12;
			for ( $m = 0; $m < $end; $m++ ) {
				$sum  += $d['t'][ $m ];
				$pts[] = round( $L + $iw * ( $m + 0.5 ) / 12, 1 ) . ',' . round( $T + $ih - $ih * $sum / $max, 1 );
			}
			$c   = self::color( $i, $y, $now_y );
			$w   = $y === $now_y ? 3.5 : 2;
			$o  .= '<polyline fill="none" stroke="' . $c . '" stroke-width="' . $w . '" stroke-linejoin="round" points="' . implode( ' ', $pts ) . '"><title>' . (int) $y . ': ' . esc_html( self::eur( $sum ) ) . '</title></polyline>';
			if ( $pts ) {
				list( $lx, $ly ) = explode( ',', end( $pts ) );
				$o .= '<circle cx="' . $lx . '" cy="' . $ly . '" r="' . ( $y === $now_y ? 5 : 3 ) . '" fill="' . $c . '"><title>' . (int) $y . ': ' . esc_html( self::eur( $sum ) ) . '</title></circle>';
				if ( $y === $now_y ) {
					$o .= '<text x="' . ( $lx + 8 ) . '" y="' . ( $ly - 6 ) . '" font-size="12" font-weight="700" fill="#8a6a1f">' . esc_html( self::eur( $sum ) ) . '</text>';
				}
			}
			$i++;
		}
		return $o . '</svg>';
	}

	private static function chart_years( $years, $now_y ) {
		$W   = 460;
		$H   = 300;
		$L   = 48;
		$T   = 28;
		$R   = 12;
		$B   = 26;
		$max = 0;
		foreach ( $years as $y => $d ) {
			$max = max( $max, array_sum( $d['t'] ) );
		}
		$max  = self::nice_max( $max );
		$iw   = $W - $L - $R;
		$ih   = $H - $T - $B;
		$n    = count( $years );
		$bw   = min( 56, $iw / $n * 0.6 );
		$o    = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" class="iamp-svg" role="img" aria-label="Totali i shitjeve sipas viteve">';
		$o   .= self::axis( $W, $H, $L, $T, $R, $B, $max, array_map( 'strval', array_keys( $years ) ) );
		$i    = 0;
		$now_m = (int) current_time( 'n' );
		foreach ( $years as $y => $d ) {
			$v  = array_sum( $d['t'] );
			$x  = $L + $iw * ( $i + 0.5 ) / $n - $bw / 2;
			$bh = $ih * $v / $max;
			$c  = self::color( $i, $y, $now_y );
			if ( $y === $now_y && isset( $years[ $y - 1 ] ) ) {
				$rest = array_sum( array_slice( $years[ $y - 1 ]['t'], $now_m ) );
				$rh   = $ih * $rest / $max;
				$o   .= '<rect x="' . $x . '" y="' . ( $T + $ih - $bh - $rh ) . '" width="' . $bw . '" height="' . $rh . '" fill="#c9a14a" opacity="0.25" rx="3"><title>Nëse muajt e mbetur ecin si ' . (int) ( $y - 1 ) . ': rreth ' . esc_html( self::eur( $v + $rest ) ) . '</title></rect>';
			}
			$o .= '<rect x="' . $x . '" y="' . ( $T + $ih - $bh ) . '" width="' . $bw . '" height="' . $bh . '" fill="' . $c . '" rx="3"><title>' . (int) $y . ': ' . esc_html( self::eur( $v ) ) . '</title></rect>';
			$o .= '<text x="' . ( $x + $bw / 2 ) . '" y="' . ( $T + $ih - $bh - 6 ) . '" text-anchor="middle" font-size="11" font-weight="600" fill="#1f2937">' . esc_html( number_format( $v / 1000, 1, ',', '.' ) ) . 'k</text>';
			$i++;
		}
		return $o . '</svg>';
	}

	private static function chart_monthly( $years, $now_y ) {
		$W     = 1000;
		$H     = 280;
		$L     = 48;
		$T     = 28;
		$R     = 12;
		$B     = 26;
		$max   = 0;
		$now_m = (int) current_time( 'n' );
		foreach ( $years as $d ) {
			$max = max( $max, max( $d['t'] ) );
		}
		$max = self::nice_max( $max );
		$iw  = $W - $L - $R;
		$ih  = $H - $T - $B;
		$n   = count( $years );
		$gw  = $iw / 12 * 0.8;
		$bw  = $gw / max( 1, $n );
		$o   = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" class="iamp-svg" role="img" aria-label="Shitjet çdo muaj sipas vitit">';
		$o  .= self::legend( $years, $now_y, $W );
		$o  .= self::axis( $W, $H, $L, $T, $R, $B, $max, self::$months );
		for ( $m = 0; $m < 12; $m++ ) {
			$gx = $L + $iw * ( $m + 0.5 ) / 12 - $gw / 2;
			$i  = 0;
			foreach ( $years as $y => $d ) {
				if ( $y === $now_y && $m >= $now_m ) {
					$i++;
					continue;
				}
				$v  = $d['t'][ $m ];
				$bh = $ih * $v / $max;
				$o .= '<rect x="' . round( $gx + $i * $bw, 1 ) . '" y="' . round( $T + $ih - $bh, 1 ) . '" width="' . round( max( 1, $bw - 1 ), 1 ) . '" height="' . round( $bh, 1 ) . '" fill="' . self::color( $i, $y, $now_y ) . '"><title>' . esc_html( self::$months[ $m ] ) . ' ' . (int) $y . ': ' . esc_html( self::eur( $v ) ) . '</title></rect>';
				$i++;
			}
		}
		return $o . '</svg>';
	}

	private static function styles() {
		?>
		<style>
			.iamp-stats-links{display:flex;flex-wrap:wrap;gap:10px;margin:16px 0}
			.iamp-svg{width:100%;height:auto;display:block}
			.iamp-yeartable th,.iamp-yeartable td{white-space:nowrap;text-align:right}
			.iamp-yeartable th:first-child{text-align:left}
			.iamp-yeartable tr.iamp-cur th,.iamp-yeartable tr.iamp-cur td{background:#fbf5e6}
			.iamp-muted{color:#9ca3af}
			.iamp-up{color:#15803d;font-weight:600}
			.iamp-down{color:#b91c1c;font-weight:600}
			.iamp-stats .ok strong{color:#15803d}
			.iamp-stats .down strong{color:#b91c1c}
			details.iamp-card summary{cursor:pointer}
		</style>
		<?php
	}
}
