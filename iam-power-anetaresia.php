<?php
/**
 * Plugin Name:       IAM Power Anëtarësia
 * Plugin URI:        https://iampower.al
 * Description:       Aktivizim i anëtarëve në kurset LearnDash: aktivizim manual dhe në grup, paketa me afat, kode aktivizimi, webhook për Digistore24 / Ko-fi / Systeme.io, skadim automatik, email-e në shqip, regjistër veprimesh dhe mjete sigurie për ndërprerjen e lidhjeve të jashtme (ChatGPT/OpenAI).
 * Version:           1.3.3
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            IAM Power Academy
 * Update URI:        https://github.com/IAM-Power-Academy/iam-power-anetaresia
 * Text Domain:       iam-power-anetaresia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IAMP_VERSION', '1.3.3' );
define( 'IAMP_FILE', __FILE__ );
define( 'IAMP_DIR', plugin_dir_path( __FILE__ ) );
define( 'IAMP_URL', plugin_dir_url( __FILE__ ) );
define( 'IAMP_CAP', 'iam_manage_members' );

require_once IAMP_DIR . 'includes/class-iamp-db.php';
require_once IAMP_DIR . 'includes/class-iamp-settings.php';
require_once IAMP_DIR . 'includes/class-iamp-log.php';
require_once IAMP_DIR . 'includes/class-iamp-emails.php';
require_once IAMP_DIR . 'includes/class-iamp-access.php';
require_once IAMP_DIR . 'includes/class-iamp-codes.php';
require_once IAMP_DIR . 'includes/class-iamp-webhook.php';
require_once IAMP_DIR . 'includes/class-iamp-cron.php';
require_once IAMP_DIR . 'includes/class-iamp-security.php';
require_once IAMP_DIR . 'includes/class-iamp-shortcodes.php';
require_once IAMP_DIR . 'includes/class-iamp-admin.php';
require_once IAMP_DIR . 'includes/class-iamp-updater.php';
require_once IAMP_DIR . 'includes/class-iamp-sales.php';
require_once IAMP_DIR . 'includes/class-iamp-team-front.php';
require_once IAMP_DIR . 'includes/class-iamp-admin-team.php';

register_activation_hook( __FILE__, 'iamp_activate' );
register_deactivation_hook( __FILE__, 'iamp_deactivate' );

function iamp_activate() {
	IAMP_DB::install();
	IAMP_Settings::ensure_defaults();

	$admin = get_role( 'administrator' );
	if ( $admin && ! $admin->has_cap( IAMP_CAP ) ) {
		$admin->add_cap( IAMP_CAP );
	}
	IAMP_Settings::sync_role_caps();

	if ( ! wp_next_scheduled( 'iamp_daily_check' ) ) {
		wp_schedule_event( time() + 300, 'hourly', 'iamp_daily_check' );
	}
}

function iamp_deactivate() {
	wp_clear_scheduled_hook( 'iamp_daily_check' );
}

add_action( 'plugins_loaded', function () {
	// Përditësim i tabelave nëse versioni ndryshon.
	if ( get_option( 'iamp_db_version' ) !== IAMP_DB::VERSION ) {
		IAMP_DB::install();
	}
	IAMP_Settings::ensure_defaults();

	IAMP_Security::init();
	IAMP_Webhook::init();
	IAMP_Cron::init();
	IAMP_Shortcodes::init();
	IAMP_Updater::init();
	IAMP_Team_Front::init();
	IAMP_Sales::init();

	if ( is_admin() ) {
		IAMP_Admin::init();
		IAMP_Admin_Team::init();
	}
} );

/**
 * A është LearnDash aktiv?
 */
function iamp_learndash_active() {
	return function_exists( 'ld_update_course_access' );
}
