<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IAMP_Admin {

	private static $actions = array(
		'iamp_activate'       => array( 'activate', IAMP_CAP ),
		'iamp_bulk'           => array( 'bulk', IAMP_CAP ),
		'iamp_revoke'         => array( 'revoke', IAMP_CAP ),
		'iamp_extend'         => array( 'extend', IAMP_CAP ),
		'iamp_resend'         => array( 'resend', IAMP_CAP ),
		'iamp_export'         => array( 'export', IAMP_CAP ),
		'iamp_save_package'   => array( 'save_package', 'manage_options' ),
		'iamp_delete_package' => array( 'delete_package', 'manage_options' ),
		'iamp_gen_codes'      => array( 'gen_codes', IAMP_CAP ),
		'iamp_disable_code'   => array( 'disable_code', IAMP_CAP ),
		'iamp_export_codes'   => array( 'export_codes', IAMP_CAP ),
		'iamp_save_settings'  => array( 'save_settings', 'manage_options' ),
		'iamp_regen_key'      => array( 'regen_key', 'manage_options' ),
		'iamp_test_email'     => array( 'test_email', 'manage_options' ),
		'iamp_run_cron'       => array( 'run_cron', 'manage_options' ),
		'iamp_sec_deactivate' => array( 'sec_deactivate', 'manage_options' ),
		'iamp_sec_revoke_pw'  => array( 'sec_revoke_pw', 'manage_options' ),
		'iamp_sec_revoke_all' => array( 'sec_revoke_all', 'manage_options' ),
		'iamp_sec_save'       => array( 'sec_save', 'manage_options' ),
		'iamp_check_updates'  => array( 'check_updates', 'manage_options' ),
	);

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		foreach ( self::$actions as $action => $cfg ) {
			add_action( 'admin_post_' . $action, array( __CLASS__, 'dispatch' ) );
		}
	}

	public static function menu() {
		add_menu_page( 'IAM Anëtarët', 'IAM Anëtarët', IAMP_CAP, 'iamp', array( __CLASS__, 'page_dashboard' ), 'dashicons-groups', 26 );
		add_submenu_page( 'iamp', 'Aktivizo', 'Aktivizo anëtar', IAMP_CAP, 'iamp', array( __CLASS__, 'page_dashboard' ) );
		add_submenu_page( 'iamp', 'Anëtarësitë', 'Anëtarësitë', IAMP_CAP, 'iamp-members', array( __CLASS__, 'page_members' ) );
		add_submenu_page( 'iamp', 'Kodet', 'Kodet e aktivizimit', IAMP_CAP, 'iamp-codes', array( __CLASS__, 'page_codes' ) );
		add_submenu_page( 'iamp', 'Paketat', 'Paketat', 'manage_options', 'iamp-packages', array( __CLASS__, 'page_packages' ) );
		add_submenu_page( 'iamp', 'Pagesat (Webhook)', 'Pagesat (Webhook)', 'manage_options', 'iamp-webhook', array( __CLASS__, 'page_webhook' ) );
		add_submenu_page( 'iamp', 'Cilësimet', 'Cilësimet & Email', 'manage_options', 'iamp-settings', array( __CLASS__, 'page_settings' ) );
		add_submenu_page( 'iamp', 'Siguria', 'Siguria (ChatGPT)', 'manage_options', 'iamp-security', array( __CLASS__, 'page_security' ) );
		add_submenu_page( 'iamp', 'Regjistri', 'Regjistri', IAMP_CAP, 'iamp-log', array( __CLASS__, 'page_log' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'iamp' ) ) {
			return;
		}
		wp_enqueue_style( 'iamp-admin', IAMP_URL . 'assets/admin.css', array(), IAMP_VERSION );
	}

	/* ===================================================================
	 * Ndihmës
	 * =================================================================== */

	public static function dispatch() {
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( ! isset( self::$actions[ $action ] ) ) {
			wp_die( 'Veprim i panjohur.' );
		}
		list( $method, $cap ) = self::$actions[ $action ];
		if ( ! current_user_can( $cap ) ) {
			wp_die( 'Nuk ke leje për këtë veprim.' );
		}
		check_admin_referer( $action );
		call_user_func( array( __CLASS__, 'do_' . $method ) );
	}

	private static function action_url( $action, $args = array() ) {
		$args['action'] = $action;
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), $action );
	}

	private static function form_open( $action ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action );
	}

	private static function back( $page, $message, $type = 'success', $extra = array() ) {
		set_transient( 'iamp_notice_' . get_current_user_id(), array( 'm' => $message, 't' => $type ), 120 );
		$extra['page'] = $page;
		wp_safe_redirect( add_query_arg( $extra, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function notices() {
		$key = 'iamp_notice_' . get_current_user_id();
		$n   = get_transient( $key );
		if ( $n ) {
			delete_transient( $key );
			echo '<div class="notice notice-' . esc_attr( $n['t'] ) . ' is-dismissible iamp-notice"><p>' . wp_kses_post( $n['m'] ) . '</p></div>';
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, 'iamp' ) && ! iamp_learndash_active() ) {
			echo '<div class="notice notice-error"><p><strong>IAM Anëtarët:</strong> LearnDash nuk është aktiv. Aktivizo LearnDash që qasjet të funksionojnë.</p></div>';
		}
	}

	private static function post( $key, $default = '' ) {
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default; // phpcs:ignore
	}

	private static function req( $key, $default = '' ) {
		return isset( $_REQUEST[ $key ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) ) : $default; // phpcs:ignore
	}

	private static function course_picker( $name, $selected = array(), $type = 'courses' ) {
		$items = 'groups' === $type ? IAMP_Access::groups() : IAMP_Access::courses();
		if ( ! $items ) {
			echo '<p class="description">' . ( 'groups' === $type ? 'Nuk ka grupe LearnDash.' : 'Nuk u gjet asnjë kurs LearnDash.' ) . '</p>';
			return;
		}
		echo '<div class="iamp-picker">';
		foreach ( $items as $p ) {
			$checked = in_array( (int) $p->ID, array_map( 'intval', (array) $selected ), true ) ? ' checked' : '';
			$state   = 'publish' !== $p->post_status ? ' <em>(' . esc_html( $p->post_status ) . ')</em>' : '';
			echo '<label><input type="checkbox" name="' . esc_attr( $name ) . '[]" value="' . (int) $p->ID . '"' . $checked . '> ' . esc_html( $p->post_title ) . $state . '</label>'; // phpcs:ignore
		}
		echo '</div>';
	}

	private static function package_select( $name, $selected = 0, $empty_label = '— Pa paketë (zgjidh kurset më poshtë) —' ) {
		echo '<select name="' . esc_attr( $name ) . '" class="iamp-wide">';
		if ( null !== $empty_label ) {
			echo '<option value="0">' . esc_html( $empty_label ) . '</option>';
		}
		foreach ( IAMP_DB::get_packages( true ) as $pkg ) {
			$d = $pkg->duration_days ? $pkg->duration_days . ' ditë' : 'pa afat';
			echo '<option value="' . (int) $pkg->id . '"' . selected( (int) $selected, (int) $pkg->id, false ) . '>' . esc_html( $pkg->name . ' (' . $d . ')' ) . '</option>';
		}
		echo '</select>';
	}

	private static function duration_fields( $prefix = '' ) {
		?>
		<select name="<?php echo esc_attr( $prefix ); ?>duration" class="iamp-duration">
			<option value="">Sipas paketës</option>
			<option value="0">Pa afat (e përhershme)</option>
			<option value="7">7 ditë</option>
			<option value="30">30 ditë</option>
			<option value="40">40 ditë</option>
			<option value="90">3 muaj</option>
			<option value="180">6 muaj</option>
			<option value="365">1 vit</option>
		</select>
		<span class="iamp-or">ose deri më</span>
		<input type="date" name="<?php echo esc_attr( $prefix ); ?>expires_at">
		<?php
	}

	private static function fmt_date( $mysql ) {
		return $mysql ? date_i18n( 'd.m.Y', strtotime( $mysql ) ) : '—';
	}

	private static function titles( $ids_str ) {
		$t = array();
		foreach ( IAMP_DB::string_to_ids( $ids_str ) as $id ) {
			$t[] = get_the_title( $id );
		}
		return implode( ', ', $t );
	}

	private static function status_badge( $m ) {
		$labels = array( 'active' => 'Aktive', 'expired' => 'Skaduar', 'revoked' => 'Çaktivizuar' );
		$cls    = $m->status;
		if ( 'active' === $m->status && $m->expires_at && strtotime( $m->expires_at ) < current_time( 'timestamp' ) + 7 * DAY_IN_SECONDS ) {
			$cls = 'soon';
		}
		$label = isset( $labels[ $m->status ] ) ? $labels[ $m->status ] : $m->status;
		return '<span class="iamp-badge iamp-' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</span>';
	}

	private static function header( $title, $subtitle = '' ) {
		echo '<div class="wrap iamp-wrap"><div class="iamp-head"><div class="iamp-logo">IAM POWER</div><div><h1>' . esc_html( $title ) . '</h1>';
		if ( $subtitle ) {
			echo '<p>' . esc_html( $subtitle ) . '</p>';
		}
		echo '</div></div>';
	}

	private static function footer() {
		echo '</div>';
	}

	/* ===================================================================
	 * Faqet
	 * =================================================================== */

	public static function page_dashboard() {
		$s = IAMP_DB::stats();
		self::header( 'Aktivizo anëtar', 'Jep qasje në kurset LearnDash — një person ose shumë njëherësh.' );
		?>
		<div class="iamp-stats">
			<div><strong><?php echo (int) $s['active']; ?></strong><span>Anëtarë aktivë</span></div>
			<div><strong><?php echo (int) $s['new_week']; ?></strong><span>Aktivizime këtë javë</span></div>
			<div class="warn"><a href="<?php echo esc_url( admin_url( 'admin.php?page=iamp-members&expiring=7' ) ); ?>"><strong><?php echo (int) $s['expiring']; ?></strong><span>Skadojnë brenda 7 ditëve</span></a></div>
			<div><strong><?php echo (int) $s['expired']; ?></strong><span>Të skaduara</span></div>
		</div>

		<div class="iamp-grid">
			<div class="iamp-card">
				<h2>Aktivizim i shpejtë</h2>
				<?php self::form_open( 'iamp_activate' ); ?>
				<table class="form-table">
					<tr><th>Email-i *</th><td><input type="email" name="email" required class="iamp-wide" placeholder="emri@shembull.com"></td></tr>
					<tr><th>Emri / Mbiemri</th><td><input type="text" name="first_name" placeholder="Emri"> <input type="text" name="last_name" placeholder="Mbiemri"><p class="description">Nëse përdoruesi nuk ekziston, krijohet automatikisht dhe merr email për të vendosur fjalëkalimin.</p></td></tr>
					<tr><th>Paketa</th><td><?php self::package_select( 'package_id' ); ?></td></tr>
					<tr><th>Kurse shtesë</th><td><?php self::course_picker( 'courses' ); ?></td></tr>
					<tr><th>Grupe LearnDash</th><td><?php self::course_picker( 'groups', array(), 'groups' ); ?></td></tr>
					<tr><th>Kohëzgjatja</th><td><?php self::duration_fields(); ?></td></tr>
					<tr><th>Shënim</th><td><input type="text" name="note" class="iamp-wide" placeholder="p.sh. Pagesë me bankë, 12.11"></td></tr>
					<tr><th>Email</th><td><label><input type="checkbox" name="send_email" value="1" checked> Dërgo email mirëseardhjeje</label></td></tr>
				</table>
				<?php submit_button( 'Aktivizo qasjen', 'primary large' ); ?>
				</form>
			</div>

			<div class="iamp-card">
				<h2>Aktivizim në grup</h2>
				<p>Një person për rresht: <code>email, Emri Mbiemri</code> (emri është opsional). Mund të ngjitësh direkt nga Google Sheets.</p>
				<?php self::form_open( 'iamp_bulk' ); ?>
				<textarea name="lines" rows="10" class="iamp-wide" placeholder="arta@gmail.com, Arta Krasniqi&#10;besnik@gmail.com, Besnik Hoxha"></textarea>
				<p><strong>Paketa:</strong><br><?php self::package_select( 'package_id' ); ?></p>
				<p><strong>Kurse shtesë:</strong></p><?php self::course_picker( 'courses' ); ?>
				<p><strong>Kohëzgjatja:</strong><br><?php self::duration_fields(); ?></p>
				<p><label><input type="checkbox" name="send_email" value="1" checked> Dërgo email secilit</label></p>
				<?php submit_button( 'Aktivizo të gjithë', 'primary' ); ?>
				</form>
			</div>
		</div>
		<?php
		self::recent_table();
		self::footer();
	}

	private static function recent_table() {
		$res = IAMP_DB::query_memberships( array( 'per_page' => 10 ) );
		echo '<div class="iamp-card"><h2>Aktivizimet e fundit</h2>';
		self::members_table( $res['rows'], false );
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=iamp-members' ) ) . '">Shiko të gjitha anëtarësitë →</a></p></div>';
	}

	private static function members_table( $rows, $actions = true ) {
		if ( ! $rows ) {
			echo '<p>Ende nuk ka anëtarësi.</p>';
			return;
		}
		$sources = array( 'manual' => 'Manual', 'bulk' => 'Në grup', 'code' => 'Kod', 'webhook' => 'Pagesë' );
		echo '<table class="widefat striped iamp-table"><thead><tr><th>Anëtari</th><th>Paketa / Kurset</th><th>Statusi</th><th>Nga</th><th>Skadon</th><th>Burimi</th>';
		if ( $actions ) {
			echo '<th>Veprime</th>';
		}
		echo '</tr></thead><tbody>';
		$packages = array();
		foreach ( IAMP_DB::get_packages() as $p ) {
			$packages[ $p->id ] = $p->name;
		}
		foreach ( $rows as $m ) {
			$pkg  = isset( $packages[ $m->package_id ] ) ? '<strong>' . esc_html( $packages[ $m->package_id ] ) . '</strong><br>' : '';
			$list = trim( self::titles( $m->courses ) . ( $m->groups_ids ? ' | Grupe: ' . self::titles( $m->groups_ids ) : '' ), ' |' );
			$edit = get_edit_user_link( $m->user_id );
			echo '<tr>';
			echo '<td><a href="' . esc_url( $edit ) . '"><strong>' . esc_html( $m->display_name ? $m->display_name : '#' . $m->user_id ) . '</strong></a><br><small>' . esc_html( $m->user_email ) . '</small></td>';
			echo '<td>' . $pkg . '<small>' . esc_html( $list ) . '</small>' . ( $m->note ? '<br><small class="iamp-note">' . esc_html( $m->note ) . '</small>' : '' ) . '</td>'; // phpcs:ignore
			echo '<td>' . self::status_badge( $m ) . '</td>'; // phpcs:ignore
			echo '<td>' . esc_html( self::fmt_date( $m->starts_at ) ) . '</td>';
			echo '<td>' . esc_html( $m->expires_at ? self::fmt_date( $m->expires_at ) : 'Pa afat' ) . '</td>';
			echo '<td>' . esc_html( isset( $sources[ $m->source ] ) ? $sources[ $m->source ] : $m->source ) . '</td>';
			if ( $actions ) {
				echo '<td class="iamp-actions">';
				if ( 'active' === $m->status ) {
					echo '<a class="button button-small" href="' . esc_url( self::action_url( 'iamp_extend', array( 'id' => $m->id, 'days' => 30 ) ) ) . '">+30 ditë</a> ';
					echo '<a class="button button-small" href="' . esc_url( self::action_url( 'iamp_extend', array( 'id' => $m->id, 'days' => 365 ) ) ) . '">+1 vit</a> ';
					echo '<a class="button button-small" href="' . esc_url( self::action_url( 'iamp_resend', array( 'id' => $m->id ) ) ) . '">Ridërgo email</a> ';
					echo '<a class="button button-small iamp-danger" onclick="return confirm(\'Ta çaktivizoj këtë anëtarësi?\')" href="' . esc_url( self::action_url( 'iamp_revoke', array( 'id' => $m->id ) ) ) . '">Çaktivizo</a>';
				} else {
					echo '<a class="button button-small button-primary" href="' . esc_url( self::action_url( 'iamp_extend', array( 'id' => $m->id, 'days' => 30 ) ) ) . '">Riaktivizo 30 ditë</a> ';
					echo '<a class="button button-small" href="' . esc_url( self::action_url( 'iamp_extend', array( 'id' => $m->id, 'date' => 'never' ) ) ) . '">Pa afat</a>';
				}
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="iamp-inline">';
				echo '<input type="hidden" name="action" value="iamp_extend"><input type="hidden" name="id" value="' . (int) $m->id . '">';
				wp_nonce_field( 'iamp_extend' );
				echo '<input type="date" name="date" required> <button class="button button-small">Vendos datën</button></form>';
				echo '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	public static function page_members() {
		$status   = self::req( 'status' );
		$search   = self::req( 's' );
		$pkg      = absint( self::req( 'package_id' ) );
		$expiring = absint( self::req( 'expiring' ) );
		$paged    = max( 1, absint( self::req( 'paged', 1 ) ) );

		$res   = IAMP_DB::query_memberships( array( 'status' => $status, 'search' => $search, 'package_id' => $pkg, 'expiring_days' => $expiring, 'page' => $paged, 'per_page' => 50 ) );
		$pages = (int) ceil( $res['total'] / 50 );

		self::header( 'Anëtarësitë', $res['total'] . ' rezultate' );
		?>
		<form method="get" class="iamp-filters">
			<input type="hidden" name="page" value="iamp-members">
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Kërko email ose emër">
			<select name="status">
				<option value="">Të gjitha statuset</option>
				<option value="active" <?php selected( $status, 'active' ); ?>>Aktive</option>
				<option value="expired" <?php selected( $status, 'expired' ); ?>>Të skaduara</option>
				<option value="revoked" <?php selected( $status, 'revoked' ); ?>>Të çaktivizuara</option>
			</select>
			<?php self::package_select( 'package_id', $pkg, 'Të gjitha paketat' ); ?>
			<select name="expiring">
				<option value="">Çdo afat</option>
				<option value="7" <?php selected( $expiring, 7 ); ?>>Skadojnë brenda 7 ditëve</option>
				<option value="30" <?php selected( $expiring, 30 ); ?>>Skadojnë brenda 30 ditëve</option>
			</select>
			<button class="button">Filtro</button>
			<a class="button" href="<?php echo esc_url( self::action_url( 'iamp_export', array( 'status' => $status, 's' => $search, 'package_id' => $pkg ) ) ); ?>">⬇ Eksporto CSV</a>
		</form>
		<?php
		self::members_table( $res['rows'], true );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ) ); // phpcs:ignore
			echo '</div></div>';
		}
		self::footer();
	}

	public static function page_packages() {
		$edit_id = absint( self::req( 'edit' ) );
		$edit    = $edit_id ? IAMP_DB::get_package( $edit_id ) : null;
		self::header( 'Paketat', 'Një paketë = një ose më shumë kurse + kohëzgjatje. Përdoret për aktivizim, kode dhe pagesa automatike.' );
		?>
		<div class="iamp-grid">
			<div class="iamp-card">
				<h2><?php echo $edit ? 'Ndrysho paketën' : 'Paketë e re'; ?></h2>
				<?php self::form_open( 'iamp_save_package' ); ?>
				<input type="hidden" name="id" value="<?php echo $edit ? (int) $edit->id : 0; ?>">
				<table class="form-table">
					<tr><th>Emri *</th><td><input type="text" name="name" required class="iamp-wide" value="<?php echo esc_attr( $edit ? $edit->name : '' ); ?>" placeholder="p.sh. 40 Ditë Çlirim Shpirtëror"></td></tr>
					<tr><th>Kurset</th><td><?php self::course_picker( 'courses', $edit ? IAMP_DB::string_to_ids( $edit->courses ) : array() ); ?></td></tr>
					<tr><th>Grupet</th><td><?php self::course_picker( 'groups', $edit ? IAMP_DB::string_to_ids( $edit->groups_ids ) : array(), 'groups' ); ?></td></tr>
					<tr><th>Çmimi (€)</th><td><input type="number" step="0.01" min="0" name="price" value="<?php echo esc_attr( $edit ? $edit->price : '' ); ?>"></td></tr>
					<tr><th>Bonusi i trajnerit (%)</th><td><input type="number" step="0.5" min="0" max="100" name="commission_pct" value="<?php echo esc_attr( $edit ? $edit->commission_pct : 15 ); ?>"> <span class="description">Seminar/webinar 15 · Trajnim me Mimozën/Avniun 10 · Trajnime online 25 · Trajnim me trajner motivues 50 · Libra 10 · Paketa 1 10 · Arkitekti 5 · Donacion 0</span></td></tr>
					<tr><th>Kohëzgjatja (ditë)</th><td><input type="number" min="0" name="duration_days" value="<?php echo esc_attr( $edit ? $edit->duration_days : 0 ); ?>"> <span class="description">0 = pa afat. P.sh. 40 për masterclass-in, 365 për VIP.</span></td></tr>
					<tr><th>ID-të e produkteve</th><td><input type="text" name="product_ids" class="iamp-wide" value="<?php echo esc_attr( $edit ? $edit->product_ids : '' ); ?>" placeholder="p.sh. 512345, VIP Member, iam-vip"><p class="description">Të ndara me presje. ID e produktit në Digistore24, emri i nivelit (tier) në Ko-fi, ose çdo emër/ID që dërgon platforma. Lidh pagesën me këtë paketë.</p></td></tr>
					<tr><th>Statusi</th><td><select name="status"><option value="active" <?php selected( $edit ? $edit->status : 'active', 'active' ); ?>>Aktive</option><option value="inactive" <?php selected( $edit ? $edit->status : '', 'inactive' ); ?>>Joaktive</option></select></td></tr>
				</table>
				<?php submit_button( $edit ? 'Ruaj ndryshimet' : 'Krijo paketën' ); ?>
				<?php if ( $edit ) : ?><a href="<?php echo esc_url( admin_url( 'admin.php?page=iamp-packages' ) ); ?>">Anulo</a><?php endif; ?>
				</form>
			</div>
			<div class="iamp-card">
				<h2>Paketat ekzistuese</h2>
				<?php
				$pkgs = IAMP_DB::get_packages();
				if ( ! $pkgs ) {
					echo '<p>Ende nuk ka paketa. Krijo të parën — p.sh. “40 Ditë Çlirim Shpirtëror” me 40 ditë, ose “VIP” me 365 ditë.</p>';
				} else {
					echo '<table class="widefat striped"><thead><tr><th>Emri</th><th>Kurset</th><th>Çmimi</th><th>Bonusi</th><th>Afati</th><th>Produktet</th><th></th></tr></thead><tbody>';
					foreach ( $pkgs as $p ) {
						echo '<tr><td><strong>' . esc_html( $p->name ) . '</strong> <small>#' . (int) $p->id . ( 'active' !== $p->status ? ' (joaktive)' : '' ) . '</small></td>';
						echo '<td><small>' . esc_html( self::titles( $p->courses ) ) . '</small></td>';
						echo '<td>' . ( (float) $p->price > 0 ? '€' . esc_html( number_format( (float) $p->price, 2 ) ) : '—' ) . '</td><td>' . esc_html( rtrim( rtrim( (string) $p->commission_pct, '0' ), '.' ) ) . '%</td>';
						echo '<td>' . ( $p->duration_days ? (int) $p->duration_days . ' ditë' : 'Pa afat' ) . '</td>';
						echo '<td><small>' . esc_html( $p->product_ids ) . '</small></td>';
						echo '<td><a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=iamp-packages&edit=' . $p->id ) ) . '">Ndrysho</a> ';
						echo '<a class="button button-small iamp-danger" onclick="return confirm(\'Ta fshij paketën? Anëtarësitë ekzistuese nuk preken.\')" href="' . esc_url( self::action_url( 'iamp_delete_package', array( 'id' => $p->id ) ) ) . '">Fshi</a></td></tr>';
					}
					echo '</tbody></table>';
				}
				?>
			</div>
		</div>
		<?php
		self::footer();
	}

	public static function page_codes() {
		$generated = get_transient( 'iamp_codes_' . get_current_user_id() );
		if ( $generated ) {
			delete_transient( 'iamp_codes_' . get_current_user_id() );
		}
		self::header( 'Kodet e aktivizimit', 'Krijo kode që anëtarët i futin vetë në faqe me shortcode-in [iam_aktivizo].' );
		?>
		<div class="iamp-grid">
			<div class="iamp-card">
				<h2>Gjenero kode</h2>
				<?php if ( ! IAMP_DB::get_packages( true ) ) : ?>
					<p>Së pari krijo një <a href="<?php echo esc_url( admin_url( 'admin.php?page=iamp-packages' ) ); ?>">paketë</a>.</p>
				<?php else : ?>
				<?php self::form_open( 'iamp_gen_codes' ); ?>
				<table class="form-table">
					<tr><th>Paketa</th><td><?php self::package_select( 'package_id', 0, null ); ?></td></tr>
					<tr><th>Sa kode</th><td><input type="number" name="count" value="10" min="1" max="500"></td></tr>
					<tr><th>Përdorime për kod</th><td><input type="number" name="max_uses" value="1" min="1"> <span class="description">1 = kod personal. P.sh. 50 për një kod grupi në seminar.</span></td></tr>
					<tr><th>Prefiksi</th><td><input type="text" name="prefix" value="IAM" maxlength="10"></td></tr>
					<tr><th>Vlen deri më</th><td><input type="date" name="valid_until"> <span class="description">bosh = pa afat</span></td></tr>
					<tr><th>Shënim</th><td><input type="text" name="note" class="iamp-wide" placeholder="p.sh. Seminari Tetovë"></td></tr>
				</table>
				<?php submit_button( 'Gjenero kodet' ); ?>
				</form>
				<?php endif; ?>
				<?php if ( $generated ) : ?>
					<h3>Kodet e reja</h3>
					<textarea readonly rows="8" class="iamp-wide iamp-mono" onclick="this.select()"><?php echo esc_textarea( implode( "\n", $generated ) ); ?></textarea>
				<?php endif; ?>
				<p class="description">Vendos <code>[iam_aktivizo]</code> në një faqe (p.sh. iampower.al/aktivizo) dhe <code>[iam_kurset_e_mia]</code> në faqen e anëtarit.</p>
			</div>
			<div class="iamp-card">
				<h2>Kodet <a class="button button-small" href="<?php echo esc_url( self::action_url( 'iamp_export_codes' ) ); ?>">⬇ CSV</a></h2>
				<?php
				$codes = IAMP_DB::get_codes();
				if ( ! $codes ) {
					echo '<p>Ende nuk ka kode.</p>';
				} else {
					$packages = array();
					foreach ( IAMP_DB::get_packages() as $p ) {
						$packages[ $p->id ] = $p->name;
					}
					echo '<table class="widefat striped"><thead><tr><th>Kodi</th><th>Paketa</th><th>Përdorur</th><th>Vlen deri</th><th></th></tr></thead><tbody>';
					foreach ( $codes as $c ) {
						$used = (int) $c->uses >= (int) $c->max_uses;
						echo '<tr' . ( 'active' !== $c->status || $used ? ' class="iamp-muted"' : '' ) . '><td class="iamp-mono">' . esc_html( $c->code ) . ( $c->note ? '<br><small>' . esc_html( $c->note ) . '</small>' : '' ) . '</td>';
						echo '<td>' . esc_html( isset( $packages[ $c->package_id ] ) ? $packages[ $c->package_id ] : '#' . $c->package_id ) . '</td>';
						echo '<td>' . (int) $c->uses . ' / ' . (int) $c->max_uses . '</td>';
						echo '<td>' . esc_html( self::fmt_date( $c->valid_until ) ) . '</td><td>';
						if ( 'active' === $c->status && ! $used ) {
							echo '<a class="button button-small" href="' . esc_url( self::action_url( 'iamp_disable_code', array( 'id' => $c->id ) ) ) . '">Çaktivizo</a>';
						} else {
							echo 'active' !== $c->status ? 'Çaktivizuar' : 'Përdorur';
						}
						echo '</td></tr>';
					}
					echo '</tbody></table>';
				}
				?>
			</div>
		</div>
		<?php
		self::footer();
	}

	public static function page_webhook() {
		$url = IAMP_Webhook::url();
		self::header( 'Pagesat automatike (Webhook)', 'Kur dikush paguan, platforma njofton faqen dhe qasja aktivizohet vetë — pa asnjë ndërmjetës.' );
		?>
		<div class="iamp-card">
			<h2>URL-ja jote sekrete</h2>
			<input type="text" readonly class="iamp-wide iamp-mono" value="<?php echo esc_attr( $url ); ?>" onclick="this.select()">
			<p class="description">Mos e publiko këtë URL. Nëse rrjedh, gjenero çelës të ri (URL-ja e vjetër ndalon së punuari menjëherë).</p>
			<?php self::form_open( 'iamp_regen_key' ); ?>
			<button class="button" onclick="return confirm('Gjenero çelës të ri? Duhet ta përditësosh URL-në në të gjitha platformat.')">Gjenero çelës të ri</button>
			</form>
		</div>

		<div class="iamp-grid">
			<div class="iamp-card">
				<h2>Digistore24</h2>
				<ol>
					<li>Digistore24 → <em>Settings → Integrations (IPN)</em> → <em>Add new connection</em> → <strong>Generic</strong>.</li>
					<li>Ngjit URL-në më lart te “IPN URL”.</li>
					<li>Te paketa këtu, shkruaj <strong>ID-në e produktit</strong> të Digistore24 te “ID-të e produkteve”.</li>
					<li>Kliko “Test connection” — në regjistër do shohësh “Test lidhjeje Digistore24 — OK”.</li>
				</ol>
				<p class="description">Rimbursimet dhe chargeback-et e heqin qasjen automatikisht.</p>
			</div>
			<div class="iamp-card">
				<h2>Ko-fi</h2>
				<ol>
					<li>Ko-fi → <em>Settings → API / Webhooks</em>.</li>
					<li>Ngjit URL-në më lart te “Webhook URL”.</li>
					<li>Kopjo “Verification Token” te <a href="<?php echo esc_url( admin_url( 'admin.php?page=iamp-settings' ) ); ?>">Cilësimet</a>.</li>
					<li>Te paketa shkruaj <strong>emrin e nivelit (tier)</strong> ose kodin e produktit të shop-it.</li>
				</ol>
			</div>
			<div class="iamp-card">
				<h2>Systeme.io</h2>
				<p>Për Systeme.io përdor një URL të veçantë për çdo paketë (nuk ka nevojë për ID produkti):</p>
				<?php
				foreach ( IAMP_DB::get_packages( true ) as $p ) {
					echo '<p><strong>' . esc_html( $p->name ) . '</strong><br><input type="text" readonly class="iamp-wide iamp-mono" value="' . esc_attr( IAMP_Webhook::url( $p->id ) ) . '" onclick="this.select()"></p>';
				}
				?>
				<ol>
					<li>Systeme.io → Funnel → hapi i porosisë → <em>Automation rules</em>.</li>
					<li>Trigger: <strong>New sale</strong> · Action: <strong>Send webhook</strong>.</li>
					<li>Ngjit URL-në e paketës përkatëse.</li>
				</ol>
			</div>
		</div>

		<div class="iamp-card">
			<h2>Thirrjet e fundit</h2>
			<?php self::log_table( array_merge( IAMP_Log::recent( 15, 'webhook' ), IAMP_Log::recent( 15, 'webhook_error' ), IAMP_Log::recent( 10, 'webhook_refund' ) ), true ); ?>
		</div>
		<?php
		self::footer();
	}

	public static function page_settings() {
		$s = IAMP_Settings::all();
		self::header( 'Cilësimet & Email-et' );
		self::form_open( 'iamp_save_settings' );
		?>
		<div class="iamp-card">
			<h2>Të përgjithshme</h2>
			<table class="form-table">
				<tr><th>Emri i dërguesit</th><td><input type="text" name="from_name" value="<?php echo esc_attr( $s['from_name'] ); ?>" class="iamp-wide"></td></tr>
				<tr><th>Email-i i dërguesit</th><td><input type="email" name="from_email" value="<?php echo esc_attr( $s['from_email'] ); ?>" class="iamp-wide" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"><p class="description">Përdor një adresë të domain-it (p.sh. info@iampower.al) që email-et të mos bien në spam.</p></td></tr>
				<tr><th>Faqja e hyrjes</th><td><input type="url" name="login_url" value="<?php echo esc_attr( $s['login_url'] ); ?>" class="iamp-wide" placeholder="<?php echo esc_attr( wp_login_url() ); ?>"></td></tr>
				<tr><th>Ko-fi Verification Token</th><td><input type="text" name="kofi_token" value="<?php echo esc_attr( $s['kofi_token'] ); ?>" class="iamp-wide"></td></tr>
				<tr><th>Kujtesa para skadimit</th><td><input type="number" min="1" max="30" name="reminder_days" value="<?php echo esc_attr( $s['reminder_days'] ); ?>"> ditë më parë</td></tr>
				<tr><th>Ngjyra e email-it</th><td><input type="text" name="email_color" value="<?php echo esc_attr( $s['email_color'] ); ?>"></td></tr>
				<tr><th>Ekipi (Skuadra)</th><td>
					<?php
					foreach ( wp_roles()->roles as $slug => $info ) {
						if ( 'administrator' === $slug ) {
							continue;
						}
						$c = in_array( $slug, (array) $s['team_roles'], true ) ? ' checked' : '';
						echo '<label class="iamp-role"><input type="checkbox" name="team_roles[]" value="' . esc_attr( $slug ) . '"' . $c . '> ' . esc_html( translate_user_role( $info['name'] ) ) . '</label>'; // phpcs:ignore
					}
					?>
					<p class="description">Rolet që mund të aktivizojnë anëtarë dhe të krijojnë kode (pa qasje te cilësimet dhe siguria).</p>
				</td></tr>
			</table>
		</div>

		<div class="iamp-card">
			<h2>Përditësimet automatike (GitHub)</h2>
			<table class="form-table">
				<tr><th>Versioni aktual</th><td><strong><?php echo esc_html( IAMP_VERSION ); ?></strong> · burimi: <code><?php echo esc_html( IAMP_Updater::REPO ); ?></code></td></tr>
				<tr><th>Token GitHub</th><td><input type="password" name="github_token" value="<?php echo esc_attr( $s['github_token'] ); ?>" class="iamp-wide" autocomplete="off" placeholder="github_pat_..."><p class="description">Nevojitet vetëm nëse repo-ja është private. Përdor një “fine-grained token” vetëm për këtë repo, me leje <em>Contents: Read-only</em>.</p></td></tr>
				<tr><th>Përditëso vetë</th><td><label><input type="checkbox" name="auto_update" value="1" <?php checked( $s['auto_update'] ); ?>> Instalo automatikisht versionet e reja</label></td></tr>
			</table>
		</div>

		<?php
		$types = array(
			'welcome'  => array( 'Email mirëseardhjeje', 'send_welcome' ),
			'reminder' => array( 'Kujtesë para skadimit', 'send_reminder' ),
			'expired'  => array( 'Njoftim skadimi', 'send_expired' ),
		);
		foreach ( $types as $key => $cfg ) :
			?>
			<div class="iamp-card">
				<h2><?php echo esc_html( $cfg[0] ); ?> <label class="iamp-toggle"><input type="checkbox" name="<?php echo esc_attr( $cfg[1] ); ?>" value="1" <?php checked( $s[ $cfg[1] ] ); ?>> aktiv</label></h2>
				<p><input type="text" name="<?php echo esc_attr( $key ); ?>_subject" value="<?php echo esc_attr( $s[ $key . '_subject' ] ); ?>" class="iamp-wide"></p>
				<textarea name="<?php echo esc_attr( $key ); ?>_body" rows="9" class="iamp-wide"><?php echo esc_textarea( $s[ $key . '_body' ] ); ?></textarea>
			</div>
		<?php endforeach; ?>
		<p class="description">Fushat: <code>{emri}</code> <code>{email}</code> <code>{kurset}</code> <code>{skadimi}</code> <code>{data_skadimit}</code> <code>{linku_hyrjes}</code> <code>{blloku_fjalekalimit}</code> <code>{faqja}</code></p>
		<?php submit_button( 'Ruaj cilësimet' ); ?>
		</form>

		<div class="iamp-grid">
			<div class="iamp-card">
				<h2>Testo email-in</h2>
				<?php self::form_open( 'iamp_test_email' ); ?>
				<p>Dërgon email-in e mirëseardhjes te adresa jote (<?php echo esc_html( wp_get_current_user()->user_email ); ?>).</p>
				<button class="button">Dërgo email test</button>
				</form>
			</div>
			<div class="iamp-card">
				<h2>Kontrollo për versione të reja</h2>
				<?php
				$checked = get_option( 'iamp_update_checked' );
				$err     = get_option( 'iamp_update_error' );
				echo '<p>Kontrolli i fundit: ' . esc_html( $checked ? wp_date( 'd.m.Y H:i', $checked ) : 'ende jo' ) . '</p>';
				if ( $err ) {
					echo '<p class="iamp-note">Gabim: ' . esc_html( $err ) . '</p>';
				}
				self::form_open( 'iamp_check_updates' );
				?>
				<button class="button">Kontrollo tani</button>
				</form>
			</div>
			<div class="iamp-card">
				<h2>Kontrolli i skadimeve</h2>
				<p>Kontrolli bëhet automatikisht çdo orë. Mund ta nisësh edhe tani.</p>
				<?php self::form_open( 'iamp_run_cron' ); ?>
				<button class="button">Kontrollo tani</button>
				</form>
			</div>
		</div>
		<?php
		self::footer();
	}

	public static function page_security() {
		$s       = IAMP_Settings::all();
		$plugins = IAMP_Security::suspicious_plugins();
		$pws     = IAMP_Security::app_passwords();
		$users   = IAMP_Security::suspicious_users();
		self::header( 'Siguria — ndërprit lidhjet e ChatGPT', 'Gjen dhe mbyll çdo derë nga e cila ChatGPT ose aplikacione të jashtme lidhen me WordPress-in.' );
		?>
		<div class="iamp-card">
			<h2>1. Plugin-et e dyshuara (ChatGPT / OpenAI / Bridge)</h2>
			<?php if ( ! $plugins ) : ?>
				<p class="iamp-good">✓ Nuk u gjet asnjë plugin i tillë.</p>
			<?php else : ?>
				<table class="widefat striped"><thead><tr><th>Plugin</th><th>Statusi</th><th>Veprim</th></tr></thead><tbody>
				<?php foreach ( $plugins as $p ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $p['name'] ); ?></strong><br><small><?php echo esc_html( $p['file'] ); ?> — <?php echo esc_html( wp_trim_words( $p['desc'], 20 ) ); ?></small></td>
						<td><?php echo $p['active'] ? '<span class="iamp-badge iamp-revoked">Aktiv</span>' : '<span class="iamp-badge iamp-expired">Joaktiv</span>'; ?></td>
						<td>
							<?php if ( $p['mu'] ) : ?>
								<small>Must-use plugin: fshije nga <code>wp-content/mu-plugins/</code> me File Manager në cPanel.</small>
							<?php elseif ( $p['active'] ) : ?>
								<a class="button iamp-danger" href="<?php echo esc_url( self::action_url( 'iamp_sec_deactivate', array( 'plugin' => $p['file'] ) ) ); ?>">Çaktivizo</a>
							<?php else : ?>
								<a class="button" href="<?php echo esc_url( admin_url( 'plugins.php?plugin_status=inactive' ) ); ?>">Fshije te Plugins →</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody></table>
				<p class="description">Kontrollo listën para se të çaktivizosh — kërkimi bazohet te fjalë si “GPT”, “OpenAI”, “bridge”, prandaj mund të shfaqet edhe ndonjë plugin tjetër. Pas çaktivizimit, fshije plugin-in e vjetër nga faqja Plugins.</p>
			<?php endif; ?>
		</div>

		<div class="iamp-card">
			<h2>2. Application Passwords (çelësat e aplikacioneve të jashtme)</h2>
			<p>ChatGPT dhe mjetet e jashtme zakonisht lidhen me WordPress me një “Application Password”. Fshirja e tyre e ndërpret lidhjen menjëherë.</p>
			<?php if ( ! $pws ) : ?>
				<p class="iamp-good">✓ Nuk ka asnjë Application Password aktiv.</p>
			<?php else : ?>
				<table class="widefat striped"><thead><tr><th>Përdoruesi</th><th>Emri i çelësit</th><th>Krijuar</th><th>Përdorur së fundi</th><th></th></tr></thead><tbody>
				<?php foreach ( $pws as $pw ) : ?>
					<tr>
						<td><?php echo esc_html( $pw['login'] ); ?><br><small><?php echo esc_html( $pw['email'] ); ?></small></td>
						<td><strong><?php echo esc_html( $pw['name'] ); ?></strong></td>
						<td><?php echo esc_html( $pw['created'] ? date_i18n( 'd.m.Y H:i', $pw['created'] ) : '—' ); ?></td>
						<td><?php echo esc_html( $pw['last_used'] ? date_i18n( 'd.m.Y H:i', $pw['last_used'] ) . ' (' . $pw['last_ip'] . ')' : 'Asnjëherë' ); ?></td>
						<td><a class="button button-small iamp-danger" href="<?php echo esc_url( self::action_url( 'iamp_sec_revoke_pw', array( 'user' => $pw['user_id'], 'uuid' => $pw['uuid'] ) ) ); ?>">Fshi</a></td>
					</tr>
				<?php endforeach; ?>
				</tbody></table>
				<p><a class="button button-primary iamp-danger" onclick="return confirm('Të fshihen TË GJITHA Application Passwords?')" href="<?php echo esc_url( self::action_url( 'iamp_sec_revoke_all' ) ); ?>">Fshi të gjitha</a></p>
			<?php endif; ?>
		</div>

		<div class="iamp-card">
			<h2>3. Llogari përdoruesish që duken si bot</h2>
			<?php if ( ! $users ) : ?>
				<p class="iamp-good">✓ Asnjë llogari e dyshuar.</p>
			<?php else : ?>
				<ul>
				<?php foreach ( $users as $u ) : ?>
					<li><a href="<?php echo esc_url( get_edit_user_link( $u->ID ) ); ?>"><?php echo esc_html( $u->user_login ); ?></a> — <?php echo esc_html( $u->user_email ); ?> (<?php echo esc_html( implode( ', ', $u->roles ) ); ?>)</li>
				<?php endforeach; ?>
				</ul>
				<p class="description">Nëse ndonjëra u krijua për ChatGPT, fshije nga Users ose ndrysho fjalëkalimin.</p>
			<?php endif; ?>
		</div>

		<div class="iamp-card">
			<h2>4. Mbrojtje të përhershme</h2>
			<?php self::form_open( 'iamp_sec_save' ); ?>
			<p><label><input type="checkbox" name="disable_app_passwords" value="1" <?php checked( $s['disable_app_passwords'] ); ?>> <strong>Çaktivizo plotësisht Application Passwords</strong> — asnjë aplikacion i jashtëm nuk mund të krijojë çelës të ri.</label></p>
			<p><label><input type="checkbox" name="disable_xmlrpc" value="1" <?php checked( $s['disable_xmlrpc'] ); ?>> <strong>Çaktivizo XML-RPC</strong> — mbyll një derë të vjetër që përdoret nga bot-et (aplikacioni zyrtar WordPress mobile mund të mos punojë).</label></p>
			<p><label><input type="checkbox" name="block_ai_bots" value="1" <?php checked( $s['block_ai_bots'] ); ?>> <strong>Blloko bot-et e OpenAI</strong> (GPTBot, ChatGPT-User, OAI-SearchBot) — nuk lexojnë dot faqen. Kujdes: faqja nuk do shfaqet në kërkimet e ChatGPT.</label></p>
			<?php submit_button( 'Ruaj mbrojtjet' ); ?>
			</form>
			<p class="description">Webhook-u i këtij plugin-i nuk ndikohet — ai mbrohet me çelësin e vet sekret.</p>
		</div>

		<div class="iamp-card">
			<h2>5. Hapat manualë të rekomanduar</h2>
			<ol>
				<li>Në ChatGPT: <em>Settings → Connected apps / GPTs</em> — fshi çdo Action ose GPT që ka URL-në e iampower.al.</li>
				<li>Nëse i ke dhënë ChatGPT-së fjalëkalimin e administratorit: <strong>ndryshoje sot</strong> te Users → Profile.</li>
				<li>Fshi plugin-in e vjetër “Member Activation Bridge” nga Plugins pasi ta çaktivizosh.</li>
				<li>Nëse ChatGPT ka krijuar kode në <em>functions.php</em> ose “Code Snippets”, kontrolloji dhe fshiji.</li>
			</ol>
		</div>
		<?php
		self::footer();
	}

	public static function page_log() {
		self::header( 'Regjistri i veprimeve', 'Çdo aktivizim, skadim, email dhe pagesë regjistrohet këtu (ruhet 12 muaj).' );
		echo '<div class="iamp-card">';
		self::log_table( IAMP_Log::recent( 300 ) );
		echo '</div>';
		self::footer();
	}

	private static function log_table( $rows, $sort = false ) {
		if ( $sort ) {
			usort(
				$rows,
				function ( $a, $b ) {
					return (int) $b->id - (int) $a->id;
				}
			);
		}
		if ( ! $rows ) {
			echo '<p>Asnjë hyrje ende.</p>';
			return;
		}
		echo '<table class="widefat striped iamp-table"><thead><tr><th>Koha</th><th>Veprimi</th><th>Anëtari</th><th>Nga</th><th>Detaje</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$u     = $r->user_id ? get_userdata( $r->user_id ) : null;
			$actor = $r->actor_id ? get_userdata( $r->actor_id ) : null;
			$det   = $r->details;
			$json  = json_decode( (string) $det, true );
			if ( is_array( $json ) ) {
				$parts = array();
				foreach ( $json as $k => $v ) {
					if ( is_array( $v ) ) {
						$v = implode(
							', ',
							array_map(
								function ( $x ) {
									return is_scalar( $x ) ? (string) $x : wp_json_encode( $x );
								},
								$v
							)
						);
					} elseif ( is_bool( $v ) ) {
						$v = $v ? 'po' : 'jo';
					}
					$parts[] = $k . ': ' . $v;
				}
				$det = implode( ' · ', $parts );
			}
			echo '<tr><td>' . esc_html( date_i18n( 'd.m.Y H:i', strtotime( $r->created_at ) ) ) . '</td>';
			echo '<td>' . esc_html( IAMP_Log::label( $r->action ) ) . '</td>';
			echo '<td>' . ( $u ? esc_html( $u->user_email ) : '—' ) . '</td>';
			echo '<td>' . ( $actor ? esc_html( $actor->display_name ) : 'Sistemi' ) . '</td>';
			echo '<td><small>' . esc_html( $det ) . '</small></td></tr>';
		}
		echo '</tbody></table>';
	}

	/* ===================================================================
	 * Veprimet (admin-post)
	 * =================================================================== */

	private static function grant_args_from_post() {
		$duration = self::post( 'duration', '' );
		return array(
			'package_id'    => absint( self::post( 'package_id', 0 ) ),
			'courses'       => array_map( 'absint', (array) self::post( 'courses', array() ) ),
			'groups'        => array_map( 'absint', (array) self::post( 'groups', array() ) ),
			'duration_days' => '' === $duration ? null : absint( $duration ),
			'expires_at'    => sanitize_text_field( self::post( 'expires_at', '' ) ),
			'send_email'    => (bool) self::post( 'send_email', false ),
			'note'          => sanitize_text_field( self::post( 'note', '' ) ),
		);
	}

	private static function do_activate() {
		$args               = self::grant_args_from_post();
		$args['email']      = sanitize_email( self::post( 'email' ) );
		$args['first_name'] = sanitize_text_field( self::post( 'first_name' ) );
		$args['last_name']  = sanitize_text_field( self::post( 'last_name' ) );
		$args['source']     = 'manual';

		// Pa paketë dhe pa kohëzgjatje të zgjedhur → pa afat.
		if ( ! $args['package_id'] && null === $args['duration_days'] ) {
			$args['duration_days'] = 0;
		}

		$res = IAMP_Access::grant( $args );
		if ( is_wp_error( $res ) ) {
			self::back( 'iamp', $res->get_error_message(), 'error' );
		}
		$msg = '<strong>' . esc_html( $args['email'] ) . '</strong>: ';
		if ( ! empty( $res['already'] ) ) {
			$msg .= 'kishte tashmë qasje të përhershme në këtë paketë.';
		} elseif ( ! empty( $res['extended'] ) ) {
			$msg .= 'anëtarësia ekzistuese u zgjat.';
		} else {
			$msg .= 'qasja u aktivizua' . ( $res['created'] ? ' (llogari e re u krijua)' : '' ) . '.';
		}
		self::back( 'iamp', $msg );
	}

	private static function do_bulk() {
		$base           = self::grant_args_from_post();
		$base['source'] = 'bulk';
		if ( ! $base['package_id'] && null === $base['duration_days'] ) {
			$base['duration_days'] = 0;
		}
		$lines = preg_split( '/\r\n|\r|\n/', (string) self::post( 'lines' ) );
		$ok    = 0;
		$errs  = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$cols  = array_map( 'trim', preg_split( '/[,;\t]/', $line, 2 ) );
			$email = sanitize_email( $cols[0] );
			$name  = isset( $cols[1] ) ? sanitize_text_field( $cols[1] ) : '';
			$parts = explode( ' ', $name, 2 );

			$args               = $base;
			$args['email']      = $email;
			$args['first_name'] = $parts[0];
			$args['last_name']  = isset( $parts[1] ) ? $parts[1] : '';
			$res                = IAMP_Access::grant( $args );
			if ( is_wp_error( $res ) ) {
				$errs[] = esc_html( $line ) . ' — ' . esc_html( $res->get_error_message() );
			} else {
				$ok++;
			}
		}
		$msg = '<strong>' . $ok . '</strong> anëtarë u aktivizuan.';
		if ( $errs ) {
			$msg .= '<br>Gabime (' . count( $errs ) . '):<br>' . implode( '<br>', array_slice( $errs, 0, 30 ) );
		}
		self::back( 'iamp', $msg, $errs ? 'warning' : 'success' );
	}

	private static function do_revoke() {
		$ok = IAMP_Access::revoke( absint( self::req( 'id' ) ), 'revoked', false );
		self::back( 'iamp-members', $ok ? 'Anëtarësia u çaktivizua dhe qasja në kurse u hoq.' : 'Anëtarësia nuk është aktive.', $ok ? 'success' : 'warning' );
	}

	private static function do_extend() {
		$id   = absint( self::req( 'id' ) );
		$days = absint( self::req( 'days' ) );
		$date = self::req( 'date' );
		$res  = IAMP_Access::extend( $id, $days, $date );
		if ( is_wp_error( $res ) ) {
			self::back( 'iamp-members', $res->get_error_message(), 'error' );
		}
		$m = IAMP_DB::get_membership( $id );
		self::back( 'iamp-members', 'U ruajt. Skadimi i ri: <strong>' . esc_html( $m && $m->expires_at ? self::fmt_date( $m->expires_at ) : 'pa afat' ) . '</strong>' );
	}

	private static function do_resend() {
		$m = IAMP_DB::get_membership( absint( self::req( 'id' ) ) );
		if ( ! $m ) {
			self::back( 'iamp-members', 'Nuk u gjet.', 'error' );
		}
		// Përdorues i krijuar nga plugin-i që ende nuk ka hyrë → dërgo sërish linkun e fjalëkalimit.
		$new = (bool) get_user_meta( $m->user_id, 'iamp_created_by_plugin', true ) && ! get_user_meta( $m->user_id, 'learndash-last-login', true );
		$ok  = IAMP_Emails::send( 'welcome', $m->user_id, $m, array( 'new_user' => $new ) );
		self::back( 'iamp-members', $ok ? 'Email-i u dërgua.' : 'Email-i nuk u dërgua — kontrollo konfigurimin e email-it (SMTP).', $ok ? 'success' : 'error' );
	}

	private static function do_export() {
		$res = IAMP_DB::query_memberships(
			array(
				'status'     => self::req( 'status' ),
				'search'     => self::req( 's' ),
				'package_id' => absint( self::req( 'package_id' ) ),
				'per_page'   => 0,
			)
		);
		$packages = array();
		foreach ( IAMP_DB::get_packages() as $p ) {
			$packages[ $p->id ] = $p->name;
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=anetaret-iampower-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM që Excel të lexojë shkronjat ë/ç.
		fputcsv( $out, array( 'Emri', 'Email', 'Paketa', 'Kurset', 'Statusi', 'Nga', 'Skadon', 'Burimi', 'Shënim' ) );
		foreach ( $res['rows'] as $m ) {
			fputcsv(
				$out,
				array(
					$m->display_name,
					$m->user_email,
					isset( $packages[ $m->package_id ] ) ? $packages[ $m->package_id ] : '',
					self::titles( $m->courses ),
					$m->status,
					self::fmt_date( $m->starts_at ),
					$m->expires_at ? self::fmt_date( $m->expires_at ) : 'Pa afat',
					$m->source,
					$m->note,
				)
			);
		}
		fclose( $out );
		exit;
	}

	private static function do_save_package() {
		$id   = absint( self::post( 'id', 0 ) );
		$name = sanitize_text_field( self::post( 'name' ) );
		if ( ! $name ) {
			self::back( 'iamp-packages', 'Emri i paketës është i detyrueshëm.', 'error' );
		}
		$products = implode( ', ', array_filter( array_map( 'trim', explode( ',', sanitize_text_field( self::post( 'product_ids' ) ) ) ) ) );
		IAMP_DB::save_package(
			array(
				'name'          => $name,
				'courses'       => (array) self::post( 'courses', array() ),
				'groups'        => (array) self::post( 'groups', array() ),
				'duration_days' => absint( self::post( 'duration_days', 0 ) ),
				'price'         => (float) str_replace( ',', '.', self::post( 'price', 0 ) ),
				'commission_pct'=> (float) str_replace( ',', '.', self::post( 'commission_pct', 15 ) ),
				'product_ids'   => $products,
				'status'        => 'inactive' === self::post( 'status' ) ? 'inactive' : 'active',
			),
			$id
		);
		self::back( 'iamp-packages', 'Paketa “' . esc_html( $name ) . '” u ruajt.' );
	}

	private static function do_delete_package() {
		IAMP_DB::delete_package( absint( self::req( 'id' ) ) );
		self::back( 'iamp-packages', 'Paketa u fshi.' );
	}

	private static function do_gen_codes() {
		$codes = IAMP_Codes::generate(
			absint( self::post( 'package_id' ) ),
			absint( self::post( 'count', 1 ) ),
			absint( self::post( 'max_uses', 1 ) ),
			sanitize_text_field( self::post( 'valid_until' ) ),
			sanitize_text_field( self::post( 'prefix', 'IAM' ) ),
			sanitize_text_field( self::post( 'note' ) )
		);
		set_transient( 'iamp_codes_' . get_current_user_id(), $codes, 300 );
		self::back( 'iamp-codes', count( $codes ) . ' kode u krijuan.' );
	}

	private static function do_disable_code() {
		IAMP_DB::set_code_status( absint( self::req( 'id' ) ), 'disabled' );
		self::back( 'iamp-codes', 'Kodi u çaktivizua.' );
	}

	private static function do_export_codes() {
		$packages = array();
		foreach ( IAMP_DB::get_packages() as $p ) {
			$packages[ $p->id ] = $p->name;
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=kodet-iampower-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'Kodi', 'Paketa', 'Përdorur', 'Maksimumi', 'Vlen deri', 'Statusi', 'Shënim' ) );
		foreach ( IAMP_DB::get_codes( 5000 ) as $c ) {
			fputcsv( $out, array( $c->code, isset( $packages[ $c->package_id ] ) ? $packages[ $c->package_id ] : '', $c->uses, $c->max_uses, self::fmt_date( $c->valid_until ), $c->status, $c->note ) );
		}
		fclose( $out );
		exit;
	}

	private static function do_save_settings() {
		$roles = array_map( 'sanitize_key', (array) self::post( 'team_roles', array() ) );
		$vals  = array(
			'from_name'     => sanitize_text_field( self::post( 'from_name' ) ),
			'from_email'    => sanitize_email( self::post( 'from_email' ) ),
			'login_url'     => esc_url_raw( self::post( 'login_url' ) ),
			'kofi_token'    => sanitize_text_field( self::post( 'kofi_token' ) ),
			'reminder_days' => max( 1, absint( self::post( 'reminder_days', 3 ) ) ),
			'email_color'   => sanitize_hex_color( self::post( 'email_color' ) ) ? sanitize_hex_color( self::post( 'email_color' ) ) : '#c9a14a',
			'team_roles'    => $roles,
			'send_welcome'  => self::post( 'send_welcome' ) ? 1 : 0,
			'send_reminder' => self::post( 'send_reminder' ) ? 1 : 0,
			'send_expired'  => self::post( 'send_expired' ) ? 1 : 0,
			'github_token'  => sanitize_text_field( self::post( 'github_token' ) ),
			'auto_update'   => self::post( 'auto_update' ) ? 1 : 0,
		);
		foreach ( array( 'welcome', 'reminder', 'expired' ) as $t ) {
			$vals[ $t . '_subject' ] = sanitize_text_field( self::post( $t . '_subject' ) );
			$vals[ $t . '_body' ]    = sanitize_textarea_field( self::post( $t . '_body' ) );
		}
		IAMP_Settings::update( $vals );
		delete_site_transient( IAMP_Updater::CACHE_KEY );
		IAMP_Log::add( 'settings', 0, 'Cilësimet u përditësuan' );
		self::back( 'iamp-settings', 'Cilësimet u ruajtën.' );
	}

	private static function do_regen_key() {
		IAMP_Settings::update( array( 'webhook_key' => wp_generate_password( 32, false, false ) ) );
		IAMP_Log::add( 'security', 0, 'Çelësi i webhook-ut u ndryshua' );
		self::back( 'iamp-webhook', 'Çelësi i ri u krijua. Përditëso URL-në në Digistore24 / Ko-fi / Systeme.io.', 'warning' );
	}

	private static function do_test_email() {
		$user = wp_get_current_user();
		$fake = (object) array(
			'courses'    => IAMP_DB::ids_to_string( wp_list_pluck( array_slice( IAMP_Access::courses(), 0, 2 ), 'ID' ) ),
			'groups_ids' => '',
			'expires_at' => gmdate( 'Y-m-d 23:59:59', current_time( 'timestamp' ) + 40 * DAY_IN_SECONDS ),
		);
		$ok = IAMP_Emails::send( 'welcome', $user->ID, $fake, array( 'new_user' => false ) );
		self::back( 'iamp-settings', $ok ? 'Email-i test u dërgua te ' . esc_html( $user->user_email ) . '.' : 'Email-i nuk u dërgua. Instalo një plugin SMTP (p.sh. WP Mail SMTP) dhe provo sërish.', $ok ? 'success' : 'error' );
	}

	private static function do_check_updates() {
		$rel = IAMP_Updater::force_check();
		if ( ! $rel ) {
			self::back( 'iamp-settings', 'Nuk u lexua dot versioni nga GitHub: ' . esc_html( (string) get_option( 'iamp_update_error' ) ), 'error' );
		}
		if ( version_compare( $rel['version'], IAMP_VERSION, '>' ) ) {
			self::back( 'iamp-settings', 'Versioni i ri <strong>' . esc_html( $rel['version'] ) . '</strong> është gati. <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">Përditëso te Plugins →</a>', 'warning' );
		}
		self::back( 'iamp-settings', 'Ke versionin më të ri (' . esc_html( IAMP_VERSION ) . ').' );
	}

	private static function do_run_cron() {
		IAMP_Cron::run();
		self::back( 'iamp-settings', 'Kontrolli i skadimeve u krye.' );
	}

	private static function do_sec_deactivate() {
		$plugin = self::req( 'plugin' );
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		if ( ! isset( $all[ $plugin ] ) || plugin_basename( IAMP_FILE ) === $plugin ) {
			self::back( 'iamp-security', 'Plugin i panjohur.', 'error' );
		}
		deactivate_plugins( $plugin );
		IAMP_Log::add( 'security', 0, 'U çaktivizua plugin-i: ' . $all[ $plugin ]['Name'] );
		self::back( 'iamp-security', '“' . esc_html( $all[ $plugin ]['Name'] ) . '” u çaktivizua. Tani mund ta fshish nga faqja Plugins.' );
	}

	private static function do_sec_revoke_pw() {
		$ok = IAMP_Security::revoke_app_password( absint( self::req( 'user' ) ), self::req( 'uuid' ) );
		self::back( 'iamp-security', $ok ? 'Çelësi u fshi — ai aplikacion nuk lidhet më.' : 'Nuk u fshi.', $ok ? 'success' : 'error' );
	}

	private static function do_sec_revoke_all() {
		$n = IAMP_Security::revoke_all_app_passwords();
		self::back( 'iamp-security', $n . ' çelësa u fshinë.' );
	}

	private static function do_sec_save() {
		IAMP_Settings::update(
			array(
				'disable_app_passwords' => self::post( 'disable_app_passwords' ) ? 1 : 0,
				'disable_xmlrpc'        => self::post( 'disable_xmlrpc' ) ? 1 : 0,
				'block_ai_bots'         => self::post( 'block_ai_bots' ) ? 1 : 0,
			)
		);
		IAMP_Log::add( 'security', 0, 'Mbrojtjet u përditësuan' );
		self::back( 'iamp-security', 'Mbrojtjet u ruajtën.' );
	}
}
