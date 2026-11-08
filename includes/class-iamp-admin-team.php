<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Paneli i administrimit për shitjet e Skuadrës dhe bonuset.
 */
class IAMP_Admin_Team {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		foreach ( array( 'iamp_sale_ok', 'iamp_sale_no', 'iamp_sale_pct', 'iamp_team_add', 'iamp_team_remove', 'iamp_payout_add', 'iamp_payout_del', 'iamp_team_course', 'iamp_team_save' ) as $a ) {
			add_action( 'admin_post_' . $a, array( __CLASS__, 'dispatch' ) );
		}
	}

	public static function menu() {
		$n     = IAMP_Sales::pending_count();
		$badge = $n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '';
		add_submenu_page( 'iamp', 'Shitjet e Skuadrës', 'Shitjet e Skuadrës' . $badge, 'manage_options', 'iamp-sales', array( __CLASS__, 'page_sales' ) );
		add_submenu_page( 'iamp', 'Skuadra & Bonuset', 'Skuadra & Bonuset', 'manage_options', 'iamp-team', array( __CLASS__, 'page_team' ) );
	}

	private static function url( $action, $args = array() ) {
		$args['action'] = $action;
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), $action );
	}

	private static function back( $page, $msg, $type = 'success' ) {
		set_transient( 'iamp_notice_' . get_current_user_id(), array( 'm' => $msg, 't' => $type ), 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . $page ) );
		exit;
	}

	private static function eur( $v ) {
		return '€' . number_format( (float) $v, 2, ',', '.' );
	}

	private static function r( $k ) {
		return isset( $_REQUEST[ $k ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $k ] ) ) : ''; // phpcs:ignore
	}

	public static function dispatch() {
		$action = self::r( 'action' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Nuk ke leje.' );
		}
		check_admin_referer( $action );
		switch ( $action ) {
			case 'iamp_sale_ok':
				$ids = array_filter( array_map( 'absint', explode( ',', self::r( 'id' ) ) ) );
				$ok  = 0;
				$err = array();
				foreach ( $ids as $id ) {
					$r = IAMP_Sales::approve( $id, self::r( 'note' ) );
					if ( is_wp_error( $r ) ) {
						$err[] = '#' . $id . ': ' . $r->get_error_message();
					} else {
						$ok++;
					}
				}
				self::back( 'iamp-sales', $ok . ' shitje u konfirmuan dhe klientët u aktivizuan.' . ( $err ? '<br>' . esc_html( implode( ' · ', $err ) ) : '' ), $err ? 'warning' : 'success' );
				break;
			case 'iamp_sale_no':
				IAMP_Sales::reject( absint( self::r( 'id' ) ), self::r( 'note' ) );
				self::back( 'iamp-sales', 'Shitja u refuzua.', 'warning' );
				break;
			case 'iamp_sale_pct':
				IAMP_Sales::set_pct( absint( self::r( 'id' ) ), (float) str_replace( ',', '.', self::r( 'pct' ) ) );
				self::back( 'iamp-sales', 'Përqindja e bonusit u ndryshua.' );
				break;
			case 'iamp_team_add':
				$r = IAMP_Sales::add_member( self::r( 'user' ), self::r( 'name' ), (float) str_replace( ',', '.', self::r( 'opening' ) ) );
				self::back( 'iamp-team', is_wp_error( $r ) ? $r->get_error_message() : 'U shtua në Skuadër.', is_wp_error( $r ) ? 'error' : 'success' );
				break;
			case 'iamp_team_remove':
				IAMP_Sales::remove_member( absint( self::r( 'uid' ) ) );
				self::back( 'iamp-team', 'U hoq nga Skuadra. Historiku i shitjeve ruhet.', 'warning' );
				break;
			case 'iamp_payout_add':
				IAMP_Sales::add_payout( absint( self::r( 'uid' ) ), (float) str_replace( ',', '.', self::r( 'amount' ) ), self::r( 'date' ), self::r( 'method' ), self::r( 'note' ) );
				self::back( 'iamp-team', 'Pagesa e bonusit u regjistrua.' );
				break;
			case 'iamp_payout_del':
				IAMP_Sales::delete_payout( absint( self::r( 'id' ) ) );
				self::back( 'iamp-team', 'Pagesa u fshi.', 'warning' );
				break;
			case 'iamp_team_course':
				$r = self::create_course();
				self::back( 'iamp-team', is_wp_error( $r ) ? $r->get_error_message() : 'Kursi privat "Skuadra IAM Power" u krijua dhe anëtarët u regjistruan.', is_wp_error( $r ) ? 'error' : 'success' );
				break;
			case 'iamp_team_save':
				IAMP_Settings::update(
					array(
						'vat_pct'      => (float) str_replace( ',', '.', self::r( 'vat_pct' ) ),
						'notify_email' => sanitize_email( self::r( 'notify_email' ) ),
						'sheet_url'    => esc_url_raw( self::r( 'sheet_url' ) ),
						'sheet_secret' => self::r( 'sheet_secret' ),
					)
				);
				self::back( 'iamp-team', 'Cilësimet u ruajtën.' );
				break;
		}
	}

	/**
	 * Krijon kursin privat LearnDash me dy mësimet dhe regjistron Skuadrën.
	 */
	public static function create_course() {
		if ( ! iamp_learndash_active() ) {
			return new WP_Error( 'iamp', 'LearnDash nuk është aktiv.' );
		}
		$course = (int) get_option( 'iamp_team_course' );
		if ( ! $course || 'sfwd-courses' !== get_post_type( $course ) ) {
			$course = wp_insert_post(
				array(
					'post_type'    => 'sfwd-courses',
					'post_status'  => 'publish',
					'post_title'   => 'Skuadra IAM Power',
					'post_content' => 'Hapësira e Skuadrës: regjistro shitjet, shiko bonuset e tua dhe linqet e punës.',
				)
			);
			if ( is_wp_error( $course ) ) {
				return $course;
			}
			update_option( 'iamp_team_course', $course, false );
		}
		// Kurs i mbyllur: hyjnë vetëm ata që regjistron Mimoza.
		if ( function_exists( 'learndash_update_setting' ) ) {
			learndash_update_setting( $course, 'course_price_type', 'closed' );
			learndash_update_setting( $course, 'course_disable_lesson_progression', 'on' );
		} else {
			$meta = (array) get_post_meta( $course, '_sfwd-courses', true );
			$meta['sfwd-courses_course_price_type'] = 'closed';
			update_post_meta( $course, '_sfwd-courses', $meta );
		}
		$links   = '<h3>Linqet e punës për ofertat</h3>'
			. '<p>Mimoza i përditëson këto linqe këtu (Edit lesson). I sheh vetëm Skuadra.</p>'
			. '<ul>'
			. '<li><strong>Arkitekti i Universit</strong>: pas blerjes, klienti plotëson pyetësorin '
			. '<a href="https://iampower.al/pyetesori-arkitekti-i-universit/" target="_blank">iampower.al/pyetesori-arkitekti-i-universit</a> (fjalëkalimi: 8888)</li>'
			. '</ul>';
		$lessons = array(
			array( 'Regjistro shitjen', '[iam_regjistro_shitjen]', 1 ),
			array( 'Paneli im', '[iam_paneli_im]', 2 ),
			array( 'Linqet e punës', $links, 3 ),
		);
		$ids = (array) get_option( 'iamp_team_lessons', array() );
		foreach ( $lessons as $i => $l ) {
			$lid = isset( $ids[ $i ] ) ? (int) $ids[ $i ] : 0;
			if ( ! $lid || 'sfwd-lessons' !== get_post_type( $lid ) ) {
				$lid = wp_insert_post(
					array(
						'post_type'    => 'sfwd-lessons',
						'post_status'  => 'publish',
						'post_title'   => $l[0],
						'post_content' => $l[1],
						'menu_order'   => $l[2],
					)
				);
				$ids[ $i ] = $lid;
			}
			if ( function_exists( 'learndash_update_setting' ) ) {
				learndash_update_setting( $lid, 'course', $course );
			}
			update_post_meta( $lid, 'course_id', $course );
		}
		update_option( 'iamp_team_lessons', $ids, false );
		if ( function_exists( 'learndash_course_add_child_to_parent' ) ) {
			foreach ( $ids as $lid ) {
				learndash_course_add_child_to_parent( $course, $lid, $course );
			}
		}
		foreach ( array_keys( IAMP_Sales::team() ) as $uid ) {
			ld_update_course_access( $uid, $course, false );
		}
		IAMP_Log::add( 'team', 0, 'U krijua kursi i Skuadrës #' . $course );
		return $course;
	}

	/* ---------------- Faqet ---------------- */

	private static function head( $title, $sub ) {
		echo '<div class="wrap iamp-wrap"><div class="iamp-head"><div class="iamp-logo">IAM POWER</div><div><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $sub ) . '</p></div></div>';
	}

	public static function page_sales() {
		$status = self::r( 'status' );
		$status = $status ? $status : 'pending';
		$sales  = IAMP_Sales::query( array( 'status' => 'all' === $status ? '' : $status, 'trainer_id' => absint( self::r( 'trajneri' ) ), 'search' => self::r( 's' ) ) );
		$pk     = array();
		foreach ( IAMP_DB::get_packages() as $p ) {
			$pk[ $p->id ] = $p->name;
		}
		self::head( 'Shitjet e Skuadrës', 'Konfirmo shitjet: klienti aktivizohet me paketën dhe bonuset, dhe bonusi i shkon trajnerit.' );
		$tabs = array( 'pending' => 'Presin konfirmim (' . IAMP_Sales::pending_count() . ')', 'approved' => 'Të konfirmuara', 'rejected' => 'Të refuzuara', 'all' => 'Të gjitha' );
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $l ) {
			echo '<a class="nav-tab' . ( $k === $status ? ' nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=iamp-sales&status=' . $k ) ) . '">' . esc_html( $l ) . '</a>';
		}
		echo '</h2>';
		if ( 'pending' === $status && $sales ) {
			$ids = implode( ',', wp_list_pluck( $sales, 'id' ) );
			echo '<p><a class="button button-primary button-hero" onclick="return confirm(\'Të konfirmohen dhe aktivizohen të gjitha ' . count( $sales ) . ' shitjet?\')" href="' . esc_url( self::url( 'iamp_sale_ok', array( 'id' => $ids ) ) ) . '">✓ Konfirmo të gjitha (' . count( $sales ) . ')</a></p>';
		}
		if ( ! $sales ) {
			echo '<div class="iamp-card"><p>Asnjë shitje këtu.</p></div></div>';
			return;
		}
		$labels = array( 'pending' => 'Pret', 'approved' => 'Konfirmuar', 'rejected' => 'Refuzuar' );
		echo '<table class="widefat striped iamp-table"><thead><tr><th>#</th><th>Data</th><th>Trajneri</th><th>Klienti</th><th>Kursi / bonus shtesë</th><th>Pagesa</th><th>Bonusi i trajnerit</th><th>Statusi</th><th></th></tr></thead><tbody>';
		foreach ( $sales as $s ) {
			$extra = array();
			foreach ( IAMP_DB::string_to_ids( $s->extra_courses ) as $cid ) {
				$extra[] = get_the_title( $cid );
			}
			echo '<tr><td>' . (int) $s->id . '</td><td>' . esc_html( mysql2date( 'd.m.Y H:i', $s->created_at ) ) . '</td>';
			echo '<td><strong>' . esc_html( IAMP_Sales::trainer_name( $s->trainer_id ) ) . '</strong></td>';
			echo '<td><strong>' . esc_html( $s->client_name ) . '</strong><br><small>' . esc_html( $s->client_email ) . '<br>' . esc_html( $s->client_phone ) . '</small></td>';
			echo '<td>' . esc_html( $pk[ $s->package_id ] ?? '' ) . ( $extra ? '<br><small class="iamp-note">+ falas: ' . esc_html( implode( ', ', $extra ) ) . '</small>' : '' ) . ( $s->note ? '<br><small>' . esc_html( $s->note ) . '</small>' : '' ) . '</td>';
			echo '<td><strong>' . esc_html( number_format( (float) $s->amount, 2, ',', '.' ) . ' ' . $s->currency ) . '</strong><br><small>' . esc_html( $s->payment_method . ( $s->payment_ref ? ' · ' . $s->payment_ref : '' ) ) . ( $s->payment_date ? '<br>' . esc_html( mysql2date( 'd.m.Y', $s->payment_date ) ) : '' ) . '</small>';
			if ( $s->proof_id ) {
				echo '<br><a href="' . esc_url( wp_get_attachment_url( $s->proof_id ) ) . '" target="_blank">📎 Dëshmia</a>';
			}
			if ( $s->collected_by_trainer ) {
				echo '<br><small class="iamp-note">Pagesën e mori trajneri</small>';
			}
			echo '</td><td>' . esc_html( self::eur( $s->commission ) ) . ' <small>(' . esc_html( rtrim( rtrim( (string) $s->commission_pct, '0' ), '.' ) ) . '%)</small>';
			if ( 'pending' === $s->status ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="iamp-inline"><input type="hidden" name="action" value="iamp_sale_pct"><input type="hidden" name="id" value="' . (int) $s->id . '">';
				wp_nonce_field( 'iamp_sale_pct' );
				echo '<input type="number" step="0.5" name="pct" value="' . esc_attr( $s->commission_pct ) . '" style="width:70px"> % <button class="button button-small">Ndrysho</button></form>';
			}
			echo '</td><td><span class="iamp-badge iamp-' . esc_attr( 'approved' === $s->status ? 'active' : ( 'pending' === $s->status ? 'soon' : 'revoked' ) ) . '">' . esc_html( $labels[ $s->status ] ?? $s->status ) . '</span></td><td class="iamp-actions">';
			if ( 'pending' === $s->status ) {
				echo '<a class="button button-primary" href="' . esc_url( self::url( 'iamp_sale_ok', array( 'id' => $s->id ) ) ) . '">✓ Konfirmo & aktivizo</a> ';
				echo '<a class="button iamp-danger" onclick="var r=prompt(\'Arsyeja e refuzimit:\');if(r===null)return false;this.href+=\'&note=\'+encodeURIComponent(r);" href="' . esc_url( self::url( 'iamp_sale_no', array( 'id' => $s->id ) ) ) . '">Refuzo</a>';
			} elseif ( $s->admin_note ) {
				echo '<small>' . esc_html( $s->admin_note ) . '</small>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function page_team() {
		$team = IAMP_Sales::team();
		$s    = IAMP_Settings::all();
		self::head( 'Skuadra & Bonuset', 'Anëtarët e Skuadrës, bonuset e tyre dhe pagesat.' );
		$course = (int) get_option( 'iamp_team_course' );
		echo '<div class="iamp-card"><h2>Hapësira e Skuadrës në LearnDash</h2>';
		if ( $course && get_post( $course ) ) {
			echo '<p>Kursi privat: <a href="' . esc_url( get_permalink( $course ) ) . '" target="_blank"><strong>Skuadra IAM Power</strong></a>, me mësimet "Regjistro shitjen", "Paneli im" dhe "Linqet e punës". Hyjnë vetëm anëtarët e Skuadrës.</p>';
			$ids = (array) get_option( 'iamp_team_lessons', array() );
			if ( ! empty( $ids[2] ) ) {
				echo '<p><a class="button" href="' . esc_url( get_edit_post_link( $ids[2] ) ) . '">Ndrysho linqet e punës</a></p>';
			}
		} else {
			echo '<p>Krijo kursin privat ku trajnerët regjistrojnë shitjet dhe shohin bonuset.</p>';
		}
		echo '<a class="button button-primary" href="' . esc_url( self::url( 'iamp_team_course' ) ) . '">' . ( $course ? 'Rikontrollo kursin dhe regjistrimet' : 'Krijo kursin e Skuadrës' ) . '</a></div>';

		echo '<div class="iamp-card"><h2>Bonuset e trajnerëve</h2>';
		if ( ! $team ) {
			echo '<p>Shto anëtarët e Skuadrës më poshtë.</p>';
		} else {
			echo '<table class="widefat striped iamp-table"><thead><tr><th>Trajneri</th><th>Shitje këtë muaj</th><th>Shitje të konfirmuara</th><th>Saldo e vjetër</th><th>Bonus i fituar</th><th>Mbajtur vetë</th><th>Paguar</th><th>Për të marrë</th><th>Regjistro pagesën</th></tr></thead><tbody>';
			$tot = array( 'opening' => 0, 'earned' => 0, 'kept' => 0, 'paid' => 0, 'balance' => 0 );
			foreach ( $team as $uid => $name ) {
				$m = IAMP_Sales::summary( $uid );
				foreach ( $tot as $k => $v ) {
					$tot[ $k ] += $m[ $k ];
				}
				$u = get_userdata( $uid );
				$ids   = (array) get_option( 'iamp_team_lessons', array() );
				$panel = ! empty( $ids[1] ) ? add_query_arg( 'trajneri', $uid, get_permalink( $ids[1] ) ) : '';
				echo '<tr><td><strong>' . esc_html( $name ) . '</strong><br><small>' . esc_html( $u ? $u->user_email : '' ) . '</small><br>' . ( $panel ? '<a href="' . esc_url( $panel ) . '" target="_blank" class="button button-small">Shiko panelin</a> ' : '' ) . '<a href="' . esc_url( self::url( 'iamp_team_remove', array( 'uid' => $uid ) ) ) . '" onclick="return confirm(\'Ta heq nga Skuadra?\')" class="iamp-danger" style="font-size:11px">hiq</a></td>';
				echo '<td>' . (int) $m['month_n'] . ' · ' . esc_html( self::eur( $m['month_sold'] ) ) . '</td><td>' . (int) $m['count'] . ' · ' . esc_html( self::eur( $m['sold'] ) ) . '</td>';
				echo '<td>' . esc_html( self::eur( $m['opening'] ) ) . '</td><td>' . esc_html( self::eur( $m['earned'] ) ) . '</td><td>' . esc_html( self::eur( $m['kept'] ) ) . '</td><td>' . esc_html( self::eur( $m['paid'] ) ) . '</td>';
				echo '<td><strong style="font-size:15px;color:#1d6b35">' . esc_html( self::eur( $m['balance'] ) ) . '</strong></td><td>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="iamp-inline"><input type="hidden" name="action" value="iamp_payout_add"><input type="hidden" name="uid" value="' . (int) $uid . '">';
				wp_nonce_field( 'iamp_payout_add' );
				echo '<input type="number" step="0.01" name="amount" placeholder="€" style="width:80px" required> <input type="date" name="date" value="' . esc_attr( current_time( 'Y-m-d' ) ) . '"> <input type="text" name="method" placeholder="Ria, bankë…" style="width:90px"> <button class="button button-small">Paguaj</button></form></td></tr>';
			}
			echo '</tbody><tfoot><tr><th>Gjithsej</th><th></th><th></th><th>' . esc_html( self::eur( $tot['opening'] ) ) . '</th><th>' . esc_html( self::eur( $tot['earned'] ) ) . '</th><th>' . esc_html( self::eur( $tot['kept'] ) ) . '</th><th>' . esc_html( self::eur( $tot['paid'] ) ) . '</th><th>' . esc_html( self::eur( $tot['balance'] ) ) . '</th><th></th></tr></tfoot></table>';
		}
		echo '</div><div class="iamp-grid"><div class="iamp-card"><h2>Shto anëtar në Skuadër</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="iamp_team_add">';
		wp_nonce_field( 'iamp_team_add' );
		echo '<table class="form-table"><tr><th>Email ose username në iampower.al</th><td><input type="text" name="user" required class="iamp-wide"></td></tr>';
		echo '<tr><th>Emri në Skuadër</th><td><input type="text" name="name" required placeholder="p.sh. Leonora"></td></tr>';
		echo '<tr><th>Saldo e mbetur nga llogaritë e vjetra (€)</th><td><input type="number" step="0.01" name="opening" placeholder="0.00"><p class="description">Sa bonus i takon ende nga llogaritë në Google Sheets.</p></td></tr></table>';
		submit_button( 'Shto në Skuadër' );
		echo '</form></div><div class="iamp-card"><h2>Cilësimet</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="iamp_team_save">';
		wp_nonce_field( 'iamp_team_save' );
		echo '<table class="form-table"><tr><th>TVSH (%)</th><td><input type="number" step="0.1" name="vat_pct" value="' . esc_attr( $s['vat_pct'] ) . '"><p class="description">Bonusi = shuma pa TVSH × përqindja e paketës.</p></td></tr>';
		echo '<tr><th>Njoftimet për shitje të reja</th><td><input type="email" name="notify_email" class="iamp-wide" value="' . esc_attr( $s['notify_email'] ) . '" placeholder="' . esc_attr( get_option( 'admin_email' ) ) . '"></td></tr>';
		echo '<tr><th>Google Sheet (Apps Script URL)</th><td><input type="url" name="sheet_url" class="iamp-wide" value="' . esc_attr( $s['sheet_url'] ) . '"><p class="description">Çdo shitje kopjohet vetë në Google Sheet.</p></td></tr>';
		echo '<tr><th>Çelësi i Google Sheet</th><td><input type="text" name="sheet_secret" class="iamp-wide" value="' . esc_attr( $s['sheet_secret'] ) . '"></td></tr></table>';
		submit_button( 'Ruaj' );
		echo '</form></div></div>';

		$pays = IAMP_Sales::payouts();
		if ( $pays ) {
			echo '<div class="iamp-card"><h2>Pagesat e fundit të bonuseve</h2><table class="widefat striped"><thead><tr><th>Data</th><th>Trajneri</th><th>Shuma</th><th>Mënyra</th><th>Shënim</th><th></th></tr></thead><tbody>';
			foreach ( $pays as $p ) {
				echo '<tr><td>' . esc_html( mysql2date( 'd.m.Y', $p->paid_on ) ) . '</td><td>' . esc_html( IAMP_Sales::trainer_name( $p->trainer_id ) ) . '</td><td>' . esc_html( 'opening' === $p->kind ? 'Saldo fillestare ' . self::eur( -1 * $p->amount ) : self::eur( $p->amount ) ) . '</td><td>' . esc_html( $p->method ) . '</td><td>' . esc_html( $p->note ) . '</td>';
				echo '<td><a class="iamp-danger" onclick="return confirm(\'Ta fshij?\')" href="' . esc_url( self::url( 'iamp_payout_del', array( 'id' => $p->id ) ) ) . '">fshi</a></td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</div>';
	}
}
