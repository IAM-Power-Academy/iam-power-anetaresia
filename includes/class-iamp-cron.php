<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IAMP_Cron {

	public static function init() {
		add_action( 'iamp_daily_check', array( __CLASS__, 'run' ) );
		// Siguri: nëse ngjarja humbet (p.sh. pas migrimit), riplanifikoje.
		if ( ! wp_next_scheduled( 'iamp_daily_check' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'iamp_daily_check' );
		}
	}

	public static function run() {
		global $wpdb;
		$t   = IAMP_DB::table( 'memberships' );
		$now = IAMP_DB::now();

		// 1) Skadimet.
		$expired = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $t WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < %s LIMIT 200", $now ) ); // phpcs:ignore
		foreach ( $expired as $id ) {
			IAMP_Access::revoke( (int) $id, 'expired', true );
		}

		// 2) Kujtesat para skadimit.
		if ( IAMP_Settings::get( 'send_reminder' ) ) {
			$days  = max( 1, (int) IAMP_Settings::get( 'reminder_days' ) );
			$limit = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $days * DAY_IN_SECONDS );
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE status = 'active' AND reminded = 0 AND expires_at IS NOT NULL AND expires_at >= %s AND expires_at <= %s LIMIT 200", $now, $limit ) ); // phpcs:ignore
			foreach ( $rows as $m ) {
				IAMP_Emails::send( 'reminder', $m->user_id, $m );
				IAMP_DB::update_membership( $m->id, array( 'reminded' => 1 ) );
			}
		}

		// 3) Pastrimi i regjistrit një herë në ditë.
		if ( ! get_transient( 'iamp_log_pruned' ) ) {
			IAMP_Log::prune();
			set_transient( 'iamp_log_pruned', 1, DAY_IN_SECONDS );
		}
	}
}
