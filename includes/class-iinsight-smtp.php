<?php
/**
 * Class Iinsight_SMTP
 *
 * Hooks into PHPMailer via wp_mail to override with SMTP when configured.
 * Only registers the phpmailer_init hook once per request.
 *
 * @package iinsight-notifications
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Iinsight_SMTP {

	private static bool $hooked = false;

	/**
	 * Call once before wp_mail() to configure SMTP if enabled.
	 * Safe to call multiple times — only registers the hook once.
	 */
	public static function init(): void {
		if ( self::$hooked ) {
			return;
		}

		$opts = Iinsight_Admin::get_settings();

		if ( ( $opts['mail_method'] ?? 'wp_mail' ) !== 'smtp' ) {
			return;
		}

		if ( empty( $opts['smtp_host'] ) || empty( $opts['smtp_port'] ) ) {
			Iinsight_Logger::warning( 'SMTP selected but host/port missing. Falling back to wp_mail.' );
			return;
		}

		self::$hooked = true;
		add_action( 'phpmailer_init', [ __CLASS__, 'configure' ] );
	}

	/**
	 * Configure PHPMailer with SMTP settings.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $mailer
	 */
	public static function configure( $mailer ): void {
		$opts = Iinsight_Admin::get_settings();

		$mailer->isSMTP();
		$mailer->Host       = $opts['smtp_host'];
		$mailer->Port       = (int) $opts['smtp_port'];
		$mailer->SMTPSecure = $opts['smtp_encryption'] !== 'none' ? $opts['smtp_encryption'] : '';
		$mailer->SMTPAuth   = ! empty( $opts['smtp_username'] );

		if ( $mailer->SMTPAuth ) {
			$mailer->Username = $opts['smtp_username'];
			$mailer->Password = self::decrypt( $opts['smtp_password'] ?? '' );
		}

		if ( ! empty( $opts['smtp_from_email'] ) && is_email( $opts['smtp_from_email'] ) ) {
			$mailer->setFrom(
				$opts['smtp_from_email'],
				$opts['smtp_from_name'] ?: get_option( 'blogname' )
			);
		}

		Iinsight_Logger::debug( 'SMTP configured.', [
			'host'       => $opts['smtp_host'],
			'port'       => $opts['smtp_port'],
			'encryption' => $opts['smtp_encryption'],
			'auth'       => $mailer->SMTPAuth ? 'yes' : 'no',
		] );
	}

	/**
	 * Send a one-off test email using current SMTP settings.
	 */
	public static function send_test( string $to ): array {
		if ( ! is_email( $to ) ) {
			return [ 'success' => false, 'message' => 'Invalid recipient email address.' ];
		}

		// Force the hook for this one send
		self::$hooked = false;
		self::init();

		$site = get_option( 'blogname' );
		$sent = wp_mail(
			$to,
			"[iinsight] SMTP Test – {$site}",
			"This is a test email from iinsight Notifications.\n\nIf you received this, your SMTP settings are working correctly.\n\n– {$site}",
			[ 'Content-Type: text/plain; charset=UTF-8' ]
		);

		if ( $sent ) {
			Iinsight_Logger::info( 'SMTP test email sent.', [ 'to' => $to ] );
			return [ 'success' => true, 'message' => "Test email sent to {$to} — please check your inbox." ];
		}

		global $phpmailer;
		$error = ( isset( $phpmailer ) && ! empty( $phpmailer->ErrorInfo ) ) ? $phpmailer->ErrorInfo : 'Unknown error.';
		Iinsight_Logger::error( 'SMTP test email failed.', [ 'to' => $to, 'phpmailer_error' => $error ] );
		return [ 'success' => false, 'message' => "Failed. PHPMailer error: {$error}" ];
	}

	// ── Password helpers ──────────────────────────────────────────────────────

	public static function encrypt( string $password ): string {
		if ( $password === '' ) return '';
		$key    = defined( 'AUTH_KEY' ) ? AUTH_KEY : wp_salt();
		$klen   = strlen( $key );
		$out    = '';
		for ( $i = 0; $i < strlen( $password ); $i++ ) {
			$out .= chr( ord( $password[ $i ] ) ^ ord( $key[ $i % $klen ] ) );
		}
		return base64_encode( 'v1:' . base64_encode( $out ) );
	}

	public static function decrypt( string $encrypted ): string {
		if ( $encrypted === '' ) return '';
		try {
			$raw = base64_decode( $encrypted, true );
			if ( $raw === false || strpos( $raw, 'v1:' ) !== 0 ) {
				return $encrypted; // plain-text legacy fallback
			}
			$payload = base64_decode( substr( $raw, 3 ), true );
			if ( $payload === false ) return '';
			$key  = defined( 'AUTH_KEY' ) ? AUTH_KEY : wp_salt();
			$klen = strlen( $key );
			$out  = '';
			for ( $i = 0; $i < strlen( $payload ); $i++ ) {
				$out .= chr( ord( $payload[ $i ] ) ^ ord( $key[ $i % $klen ] ) );
			}
			return $out;
		} catch ( \Exception $e ) {
			return '';
		}
	}
}
