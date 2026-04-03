<?php
/**
 * Class Iinsight_Assets
 *
 * Enqueues the front-end JS and passes PHP values to it via wp_localize_script.
 * Nonce name must exactly match Iinsight_Ajax::NONCE.
 *
 * @package iinsight-notifications
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Iinsight_Assets {

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	public static function enqueue(): void {
		wp_enqueue_script(
			'iinsight-form-listener',
			IINSIGHT_PLUGIN_URL . 'assets/js/iinsight-listener.js',
			[],
			IINSIGHT_VERSION,
			true   // footer
		);

		$opts     = Iinsight_Admin::get_settings();
		$is_debug = ( $opts['enable_debug_log'] === '1' )
					|| ( defined( 'WP_DEBUG' ) && WP_DEBUG );

		wp_localize_script(
			'iinsight-form-listener',
			'iinsightVars',
			[
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Iinsight_Ajax::NONCE ),
				'action'  => Iinsight_Ajax::ACTION,
				'debug'   => $is_debug ? 'true' : 'false',
			]
		);

		Iinsight_Logger::debug( 'Front-end script enqueued.', [
			'debug_mode' => $is_debug,
			'ajaxurl'    => admin_url( 'admin-ajax.php' ),
		] );
	}
}
