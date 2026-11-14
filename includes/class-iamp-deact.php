<?php
/**
 * Kërkesat për çaktivizim: Skuadra raporton anëtarët që nuk vazhdojnë (p.sh. nuk paguajnë
 * këstet e Paketës 1), Mimoza konfirmon dhe qasja hiqet.
 *
 *  [iam_kerko_caktivizim]     — trajnerja: forma + kërkesat e veta
 *  [iam_kerkesat_caktivizim]  — vetëm administratori: Konfirmo / Mos e çaktivizo
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IAMP_Deact {

	const DB_VERSION = '1.0.0';

	public static function reasons() {
		return array(
			'nuk_paguan'  => 'Nuk i paguan këstet / pagesa e mbetur',
			'nuk_vazhdon' => 'Nuk dëshiron të vazhdojë',
			'nuk_pergjigjet' => 'Nuk përgjigjet më',
			'tjeter'      => 'Arsye tjetër (shkruaj te shënimi)',
		);
	}

	public static function t() {
		global $wpdb;
		return $wpdb->prefix . 'iamp_deact';
	}

	public static function init() {
		add_shortcode( 'iam_kerko_caktivizim', array( __CLASS__, 'form' ) );
		add_shortcode( 'iam_kerkesat_caktivizim', array( __CLASS__, 'requests' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_post' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install' ), 20 );
	}

	public static function maybe_install() {
		if ( get_option( 'iamp_deact_db_version' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::t();
		dbDelta( "CREATE TABLE $t (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			email VARCHAR(190) NOT NULL DEFAULT '',
			name VARCHAR(190) NOT NULL DEFAULT '',
			requested_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			reason VARCHAR(30) NOT NULL DEFAULT 'tjeter',
			note TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			created_at DATETIME NOT NULL,
			decided_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY user_id (user_id)
		) " . $wpdb->get_charset_collate() . ';' );
		update_option( 'iamp_deact_db_version', self::DB_VERSION );
	}

	private static function can_report() {
		return is_user_logged_in() && ( IAMP_Sales::is_team() || current_user_can( 'manage_options' ) );
	}

	private static function msg_key() {
		return 'iamp_deact_msg_' . get_current_user_id();
	}

	private static function flash( $type, $text ) {
		set_transient( self::msg_key(), array( $type, $text ), 120 );
	}

	private static function message() {
		$m = get_transient( self::msg_key() );
		if ( ! $m ) {
			return '';
		}
		delete_transient( self::msg_key() );
		return '<div class="iamt-msg iamt-' . esc_attr( $m[0] ) . '">' . esc_html( $m[1] ) . '</div>';
	}

	public static function handle_post() {
		if ( empty( $_POST['iamp_deact_action'] ) || ! is_user_logged_in() ) {
			return;
		}
		$uid = get_current_user_id();
		$act = sanitize_key( wp_unslash( $_POST['iamp_deact_action'] ) );
		if ( ! isset( $_POST['iamp_deact_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['iamp_deact_nonce'] ) ), 'iamp_deact' ) ) {
			self::flash( 'err', 'Sesioni skadoi. Provo sërish.' );
			return;
		}
		global $wpdb;

		if ( 'report' === $act && self::can_report() ) {
			$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : ''; // phpcs:ignore
			$reason = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : 'tjeter'; // phpcs:ignore
			$reason = isset( self::reasons()[ $reason ] ) ? $reason : 'tjeter';
			$note   = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : ''; // phpcs:ignore
			$user   = is_email( $email ) ? get_user_by( 'email', $email ) : false;
			if ( ! $user ) {
				self::flash( 'err', 'Nuk ka llogari me këtë email në iampower.al. Kontrolloje shkronjë për shkronjë.' );
			} elseif ( ! IAMP_DB::get_user_memberships( $user->ID, 'active' ) ) {
				self::flash( 'err', 'Ky anëtar nuk ka asnjë qasje aktive — nuk ka çfarë të çaktivizohet.' );
			} elseif ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t() . " WHERE user_id = %d AND status = 'pending'", $user->ID ) ) ) { // phpcs:ignore
				self::flash( 'err', 'Për këtë anëtar ka tashmë një kërkesë që pret konfirmimin.' );
			} else {
				$name = trim( $user->first_name . ' ' . $user->last_name );
				$name = $name ? $name : $user->display_name;
				$wpdb->insert( // phpcs:ignore
					self::t(),
					array(
						'user_id'      => $user->ID,
						'email'        => $user->user_email,
						'name'         => $name,
						'requested_by' => $uid,
						'reason'       => $reason,
						'note'         => $note,
						'status'       => 'pending',
						'created_at'   => IAMP_DB::now(),
					)
				);
				IAMP_Log::add( 'deact_request', $user->ID, array( 'nga' => $uid, 'arsyeja' => $reason ) );
				$to = IAMP_Settings::get( 'notify_email' );
				$to = $to ? $to : get_option( 'admin_email' );
				wp_mail(
					$to,
					'IAM Power: kërkesë për çaktivizim (' . $name . ')',
					IAMP_Sales::trainer_name( $uid ) . ' kërkon çaktivizimin e:' . "\n\n" . $name . ' (' . $user->user_email . ")\nArsyeja: " . self::reasons()[ $reason ] . ( $note ? "\nShënim: " . $note : '' ) . "\n\nKonfirmo te faqja e kërkesave për çaktivizim."
				);
				self::flash( 'ok', 'Kërkesa u dërgua. Qasja hiqet vetëm kur Mimoza ta konfirmojë.' );
			}
		} elseif ( 'decide' === $act && current_user_can( 'manage_options' ) ) {
			$id  = absint( $_POST['req_id'] ?? 0 ); // phpcs:ignore
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t() . " WHERE id = %d AND status = 'pending'", $id ) ); // phpcs:ignore
			if ( $row ) {
				if ( 'approve' === ( $_POST['decision'] ?? '' ) ) { // phpcs:ignore
					$n = 0;
					foreach ( IAMP_DB::get_user_memberships( $row->user_id, 'active' ) as $m ) {
						if ( IAMP_Access::revoke( $m->id, 'revoked', false ) ) {
							++$n;
						}
					}
					$wpdb->update( self::t(), array( 'status' => 'done', 'decided_at' => IAMP_DB::now() ), array( 'id' => $id ) ); // phpcs:ignore
					self::flash( 'ok', $row->name . ' u çaktivizua (' . $n . ' qasje u hoqën).' );
				} else {
					$wpdb->update( self::t(), array( 'status' => 'rejected', 'decided_at' => IAMP_DB::now() ), array( 'id' => $id ) ); // phpcs:ignore
					self::flash( 'ok', 'Kërkesa u mbyll — ' . $row->name . ' mbetet aktiv.' );
				}
			}
		}
		$back = isset( $_POST['iamp_back'] ) ? esc_url_raw( wp_unslash( $_POST['iamp_back'] ) ) : ''; // phpcs:ignore
		wp_safe_redirect( wp_validate_redirect( $back, home_url( '/' ) ) );
		exit;
	}

	private static function back_field() {
		$url = ( is_ssl() ? 'https://' : 'http://' ) . ( isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '' ) . ( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '' ); // phpcs:ignore
		return '<input type="hidden" name="iamp_back" value="' . esc_attr( esc_url_raw( $url ) ) . '">';
	}

	private static function status_label( $s ) {
		$l = array( 'pending' => '🟡 Pret konfirmim', 'done' => '✅ U çaktivizua', 'rejected' => '↩ Mbeti aktiv' );
		return isset( $l[ $s ] ) ? $l[ $s ] : $s;
	}

	public static function form() {
		if ( ! self::can_report() ) {
			return IAMP_Team_Front::css_public() . '<div class="iamt"><div class="iamt-msg iamt-err">Kjo faqe është vetëm për Skuadrën IAM Power. Kyçu me llogarinë tënde.</div></div>';
		}
		global $wpdb;
		$uid  = get_current_user_id();
		$mine = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t() . ' WHERE requested_by = %d ORDER BY id DESC LIMIT 30', $uid ) ); // phpcs:ignore
		ob_start();
		echo IAMP_Team_Front::css_public(); // phpcs:ignore
		?>
		<div class="iamt">
			<div class="iamt-head"><h2>Kërkesë për çaktivizim</h2>
				<p>Për anëtarët që nuk vazhdojnë ose nuk i paguajnë këstet (p.sh. Paketa 1 është 12 muaj). Shkruaj emailin e tyre; qasja hiqet vetëm pasi Mimoza ta konfirmojë.</p></div>
			<?php echo self::message(); // phpcs:ignore ?>
			<form class="iamt-card" method="post">
				<?php wp_nonce_field( 'iamp_deact', 'iamp_deact_nonce' ); ?>
				<input type="hidden" name="iamp_deact_action" value="report">
				<?php echo self::back_field(); // phpcs:ignore ?>
				<p><label><strong>Email-i i anëtarit</strong><br><input type="email" name="email" required style="width:100%"></label></p>
				<p><label><strong>Arsyeja</strong><br><select name="reason" style="width:100%"><?php foreach ( self::reasons() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></label></p>
				<p><label><strong>Shënim</strong> (opsionale)<br><textarea name="note" style="width:100%;min-height:70px" placeholder="p.sh. ka paguar 2 nga 4 këste, nuk përgjigjet prej 3 javësh"></textarea></label></p>
				<button class="iamt-btn" type="submit">Dërgo kërkesën</button>
			</form>
			<?php if ( $mine ) : ?>
				<div class="iamt-card"><h3 style="margin:0 0 8px">Kërkesat e mia</h3>
					<?php foreach ( $mine as $r ) : ?>
						<div style="border-top:1px dashed #e6dfcf;padding:6px 0"><strong><?php echo esc_html( $r->name ); ?></strong> · <?php echo esc_html( self::status_label( $r->status ) ); ?> · <?php echo esc_html( mysql2date( 'd.m.Y', $r->created_at ) ); ?></div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function requests() {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return IAMP_Team_Front::css_public() . '<div class="iamt"><div class="iamt-msg iamt-err">Kjo faqe është vetëm për administratorin.</div></div>';
		}
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::t() . " WHERE status = 'pending' ORDER BY id ASC" ); // phpcs:ignore
		ob_start();
		echo IAMP_Team_Front::css_public(); // phpcs:ignore
		?>
		<div class="iamt">
			<div class="iamt-head"><h2>Kërkesa për çaktivizim</h2><p>Kliko “Çaktivizo” dhe qasja e anëtarit hiqet menjëherë.</p></div>
			<?php echo self::message(); // phpcs:ignore ?>
			<?php if ( ! $rows ) : ?>
				<div class="iamt-card"><p style="margin:0">Nuk ka kërkesa që presin. ✅</p></div>
			<?php else : foreach ( $rows as $r ) : ?>
				<form class="iamt-card" method="post">
					<?php wp_nonce_field( 'iamp_deact', 'iamp_deact_nonce' ); ?>
					<input type="hidden" name="iamp_deact_action" value="decide">
					<input type="hidden" name="req_id" value="<?php echo (int) $r->id; ?>">
					<?php echo self::back_field(); // phpcs:ignore ?>
					<h3 style="margin:0 0 6px"><?php echo esc_html( $r->name ); ?> · <?php echo esc_html( $r->email ); ?></h3>
					<p style="margin:0 0 4px">Arsyeja: <?php echo esc_html( self::reasons()[ $r->reason ] ?? $r->reason ); ?> · kërkuar nga <?php echo esc_html( IAMP_Sales::trainer_name( $r->requested_by ) ); ?> më <?php echo esc_html( mysql2date( 'd.m.Y', $r->created_at ) ); ?></p>
					<?php if ( $r->note ) : ?><p style="margin:0 0 8px">📝 <?php echo esc_html( $r->note ); ?></p><?php endif; ?>
					<button class="iamt-btn" type="submit" name="decision" value="approve" onclick="return confirm('Të hiqet qasja e këtij anëtari?');">🔒 Çaktivizo</button>
					<button class="iamt-btn" type="submit" name="decision" value="reject" style="background:#eee;color:#333;margin-left:8px">Mos e çaktivizo</button>
				</form>
			<?php endforeach; endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
