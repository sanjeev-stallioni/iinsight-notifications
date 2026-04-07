<?php
/**
 * Class Iinsight_Ajax
 *
 * Registers and handles the wp-ajax endpoint that receives form submission
 * data from the front-end JS and triggers email notifications.
 *
 * @package iinsight-notifications
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Iinsight_Ajax {

	const ACTION = 'iinsight_notify';
	const NONCE  = 'iinsight_nonce';

	public static function init(): void {
		add_action( 'wp_ajax_nopriv_' . self::ACTION, [ __CLASS__, 'handle' ] );
		add_action( 'wp_ajax_'        . self::ACTION, [ __CLASS__, 'handle' ] );
	}

	public static function handle(): void {

		// 1. Nonce check
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			Iinsight_Logger::warning( 'AJAX rejected — bad nonce.', [ 'ip' => self::ip() ] );
			wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 );
		}

		// 2. Notifications enabled?
		$opts = Iinsight_Admin::get_settings();
		if ( $opts['enable_notifications'] !== '1' ) {
			wp_send_json_error( [ 'message' => 'Notifications are disabled.' ], 200 );
		}

		// 3. Rate limit — 5 per IP per hour
		if ( ! self::rate_limit_ok() ) {
			Iinsight_Logger::warning( 'Rate limit hit.', [ 'ip' => self::ip() ] );
			wp_send_json_error( [ 'message' => 'Too many requests.' ], 429 );
		}

		// 4. Sanitise input
		$data = self::sanitise();
		if ( is_wp_error( $data ) ) {
			Iinsight_Logger::warning( 'Validation failed: ' . $data->get_error_message(), [ 'ip' => self::ip() ] );
			wp_send_json_error( [ 'message' => $data->get_error_message() ], 422 );
		}

		// 5. Log + send
		Iinsight_Logger::info( 'Submission received.', [
			'name'  => trim( $data['first_name'] . ' ' . $data['last_name'] ),
			'email' => $data['email'],
			'ip'    => self::ip(),
		] );

		$results = Iinsight_Mailer::send_all( $data );
		Iinsight_Logger::info( 'Mail dispatch complete.', $results );

		wp_send_json_success( $results );
	}

	// ── Sanitise ──────────────────────────────────────────────────────────────

	private static function sanitise() {
		$first        = sanitize_text_field( wp_unslash( $_POST['first_name']   ?? '' ) );
		$last         = sanitize_text_field( wp_unslash( $_POST['last_name']    ?? '' ) );
		$email        = sanitize_email(      wp_unslash( $_POST['email']        ?? '' ) );
		$phone        = sanitize_text_field( wp_unslash( $_POST['phone']        ?? '' ) );
		$ndis_funding = sanitize_text_field( wp_unslash( $_POST['ndis_funding'] ?? '' ) );
		$plan_type    = sanitize_text_field( wp_unslash( $_POST['plan_type']    ?? '' ) );

		if ( empty( $first ) ) {
			return new WP_Error( 'missing_field', 'First name is required.' );
		}
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', 'A valid email address is required.' );
		}

		return [
			'first_name'   => $first,
			'last_name'    => $last,
			'email'        => $email,
			'phone'        => $phone,
			'ndis_funding' => $ndis_funding,
			'plan_type'    => $plan_type,
		];
	}

	// ── Rate limiting ─────────────────────────────────────────────────────────

	private static function rate_limit_ok(): bool {
		$key   = 'iinsight_rl_' . md5( self::ip() );
		$count = (int) get_transient( $key );
		if ( $count >= 200 ) return false;
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}

	// ── IP helper ─────────────────────────────────────────────────────────────

	private static function ip(): string {
		foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ] as $k ) {
			if ( ! empty( $_SERVER[ $k ] ) ) {
				$ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $k ] ) ) )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
			}
		}
		return 'unknown';
	}
}
