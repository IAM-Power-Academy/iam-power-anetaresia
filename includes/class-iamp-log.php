<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IAMP_Log {

	public static function add( $action, $user_id = 0, $details = '' ) {
		global $wpdb;
		if ( is_array( $details ) ) {
			$details = wp_json_encode( $details, JSON_UNESCAPED_UNICODE );
		}
		$wpdb->insert(
			IAMP_DB::table( 'log' ),
			array(
				'created_at' => IAMP_DB::now(),
				'user_id'    => (int) $user_id,
				'actor_id'   => get_current_user_id(),
				'action'     => substr( $action, 0, 60 ),
				'details'    => $details,
				'ip'         => self::ip(),
			)
		);
	}

	public static function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return substr( $ip, 0, 64 );
	}

	public static function recent( $limit = 200, $action = '' ) {
		global $wpdb;
		$t = IAMP_DB::table( 'log' );
		if ( $action ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE action = %s ORDER BY id DESC LIMIT %d", $action, $limit ) ); // phpcs:ignore
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t ORDER BY id DESC LIMIT %d", $limit ) ); // phpcs:ignore
	}

	/**
	 * Mban regjistrin të pastër: fshin hyrjet më të vjetra se 365 ditë.
	 */
	public static function prune() {
		global $wpdb;
		$t      = IAMP_DB::table( 'log' );
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - YEAR_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE created_at < %s", $cutoff ) ); // phpcs:ignore
	}

	public static function label( $action ) {
		$labels = array(
			'activate'        => 'Aktivizim',
			'extend'          => 'Zgjatje',
			'revoke'          => 'Çaktivizim',
			'expire'          => 'Skadim automatik',
			'reactivate'      => 'Riaktivizim',
			'user_created'    => 'Përdorues i ri',
			'email_sent'      => 'Email u dërgua',
			'email_failed'    => 'Email dështoi',
			'code_used'       => 'Kod i përdorur',
			'code_failed'     => 'Kod i pasaktë',
			'webhook'         => 'Webhook',
			'webhook_refund'  => 'Webhook — rimbursim',
			'webhook_error'   => 'Webhook — gabim',
			'security'        => 'Siguria',
			'settings'        => 'Cilësime',
		);
		return isset( $labels[ $action ] ) ? $labels[ $action ] : $action;
	}
}
