<?php
/**
 * Fshirja e plugin-it. Për siguri, të dhënat (anëtarësitë, kodet, regjistri) RUHEN,
 * përveç nëse në wp-config.php shton: define( 'IAMP_DELETE_DATA', true );
 * Qasjet në LearnDash nuk preken në asnjë rast.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( wp_roles()->roles as $slug => $info ) {
	$role = get_role( $slug );
	if ( $role ) {
		$role->remove_cap( 'iam_manage_members' );
	}
}
wp_clear_scheduled_hook( 'iamp_daily_check' );

if ( defined( 'IAMP_DELETE_DATA' ) && IAMP_DELETE_DATA ) {
	global $wpdb;
	foreach ( array( 'packages', 'memberships', 'codes', 'log' ) as $t ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}iamp_{$t}" ); // phpcs:ignore
	}
	delete_option( 'iamp_settings' );
	delete_option( 'iamp_db_version' );
}
