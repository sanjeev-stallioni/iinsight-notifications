<?php
/**
 * Plugin Name:       iinsight Form Notifications
 * Description:       Sends acknowledgement and admin notification emails when the iinsight NDIS external form is successfully submitted. Includes SMTP configuration and a dedicated debug log.
 * Version:           1.0.0
 * Author:            Stallioni Net Solutions
 * Author URI:        https://stallioni.com
 * License:           GPL-2.0-or-later
 * Text Domain:       iinsight-notifications
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Constants ─────────────────────────────────────────────────────────────────
define( 'IINSIGHT_VERSION',     '3.1.0' );
define( 'IINSIGHT_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'IINSIGHT_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'IINSIGHT_PLUGIN_FILE', __FILE__ );

// ── Load order matters: Logger → Admin → SMTP → Mailer → Ajax → Assets ───────
require_once IINSIGHT_PLUGIN_DIR . 'includes/class-iinsight-logger.php';
require_once IINSIGHT_PLUGIN_DIR . 'includes/class-iinsight-admin.php';
require_once IINSIGHT_PLUGIN_DIR . 'includes/class-iinsight-smtp.php';
require_once IINSIGHT_PLUGIN_DIR . 'includes/class-iinsight-mailer.php';
require_once IINSIGHT_PLUGIN_DIR . 'includes/class-iinsight-ajax.php';
require_once IINSIGHT_PLUGIN_DIR . 'includes/class-iinsight-assets.php';

// ── Boot ──────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'iinsight_boot', 5 );
function iinsight_boot() {
	// Respect the admin toggle for logging
	$opts = Iinsight_Admin::get_settings();
	Iinsight_Logger::set_enabled( $opts['enable_debug_log'] === '1' );

	Iinsight_Ajax::init();
	Iinsight_Assets::init();
	Iinsight_Admin::init();
}

// ── Activation ────────────────────────────────────────────────────────────────
register_activation_hook( __FILE__, 'iinsight_activate' );
function iinsight_activate() {
	$log_dir = IINSIGHT_PLUGIN_DIR . 'logs/';

	if ( ! file_exists( $log_dir ) ) {
		wp_mkdir_p( $log_dir );
	}

	// Block direct browser access
	if ( ! file_exists( $log_dir . '.htaccess' ) ) {
		file_put_contents( $log_dir . '.htaccess', "Deny from all\n" );
	}
	if ( ! file_exists( $log_dir . 'index.php' ) ) {
		file_put_contents( $log_dir . 'index.php', "<?php // Silence is golden.\n" );
	}
}

// ── Deactivation ──────────────────────────────────────────────────────────────
register_deactivation_hook( __FILE__, 'iinsight_deactivate' );
function iinsight_deactivate() {
	// Log files are kept intentionally so admins retain submission history.
}
