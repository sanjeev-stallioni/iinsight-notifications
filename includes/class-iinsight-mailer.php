<?php
/**
 * Class Iinsight_Mailer
 *
 * Composes and dispatches user acknowledgement + admin notification emails.
 * Subject and body are editable from the admin settings page.
 *
 * Supported placeholders: {first_name} {last_name} {full_name}
 *                         {email} {phone} {site_name} {date} {time}
 *
 * @package iinsight-notifications
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Iinsight_Mailer {

	/**
	 * Send both emails. Called from the AJAX handler.
	 */
	public static function send_all( array $data ): array {
		Iinsight_Logger::debug( 'Mailer::send_all called.', [
			'first_name' => $data['first_name'] ?? '(empty)',
			'last_name'  => $data['last_name']  ?? '(empty)',
			'email'      => $data['email']       ?? '(empty)',
		] );

		// Configure SMTP once before any wp_mail() call
		Iinsight_SMTP::init();

		$user_result  = self::send_user_ack( $data );
		$admin_result = self::send_admin_notification( $data );

		Iinsight_Logger::debug( 'Mailer::send_all results.', [
			'user_email'  => $user_result,
			'admin_email' => $admin_result,
		] );

		return [
			'user_email'  => $user_result,
			'admin_email' => $admin_result,
		];
	}

	// ── User acknowledgement ──────────────────────────────────────────────────

	private static function send_user_ack( array $data ): string {
		$to = $data['email'] ?? '';

		if ( ! is_email( $to ) ) {
			Iinsight_Logger::warning( 'User ack skipped — invalid email.', [ 'email' => $to ] );
			return 'skipped – invalid email';
		}

		$opts  = Iinsight_Admin::get_settings();
		$ph    = self::placeholders( $data );
		$subj  = self::fill( $opts['user_email_subject'] ?: self::default_user_subject(), $ph );
		$body  = self::fill( $opts['user_email_body']    ?: self::default_user_body(),    $ph );

		Iinsight_Logger::debug( 'Sending user acknowledgement.', [
			'to'      => $to,
			'subject' => $subj,
			'body_length' => strlen( $body ),
			'headers' => self::headers(),
		] );

		$sent = wp_mail( $to, $subj, $body, self::headers() );

		if ( $sent ) {
			Iinsight_Logger::info( 'User acknowledgement sent.', [ 'to' => $to ] );
			return 'sent';
		}

		global $phpmailer;
		$error = ( isset( $phpmailer ) && ! empty( $phpmailer->ErrorInfo ) ) ? $phpmailer->ErrorInfo : 'Unknown error';
		Iinsight_Logger::error( 'User acknowledgement FAILED.', [ 'to' => $to, 'phpmailer_error' => $error ] );
		return 'failed';
	}

	// ── Admin notification ────────────────────────────────────────────────────

	private static function send_admin_notification( array $data ): string {
		$opts  = Iinsight_Admin::get_settings();
		$to    = ! empty( $opts['admin_email_override'] )
				? $opts['admin_email_override']
				: get_option( 'admin_email' );

		$ph   = self::placeholders( $data );
		$subj = self::fill( $opts['admin_email_subject'] ?: self::default_admin_subject(), $ph );
		$body = self::fill( $opts['admin_email_body']    ?: self::default_admin_body(),    $ph );

		Iinsight_Logger::debug( 'Sending admin notification.', [
			'to'            => $to,
			'subject'       => $subj,
			'body_length'   => strlen( $body ),
			'using_override' => ! empty( $opts['admin_email_override'] ),
		] );

		$sent = wp_mail( $to, $subj, $body, self::headers() );

		if ( $sent ) {
			Iinsight_Logger::info( 'Admin notification sent.', [ 'to' => $to ] );
			return 'sent';
		}

		global $phpmailer;
		$error = ( isset( $phpmailer ) && ! empty( $phpmailer->ErrorInfo ) ) ? $phpmailer->ErrorInfo : 'Unknown error';
		Iinsight_Logger::error( 'Admin notification FAILED.', [ 'to' => $to, 'phpmailer_error' => $error ] );
		return 'failed';
	}

	// ── Placeholder system ────────────────────────────────────────────────────

	public static function placeholders( array $data ): array {
		$first = trim( $data['first_name'] ?? '' );
		$last  = trim( $data['last_name']  ?? '' );
		return [
			'{first_name}' => $first ?: 'there',
			'{last_name}'  => $last,
			'{full_name}'  => trim( "$first $last" ) ?: 'Unknown',
			'{email}'      => $data['email'] ?? 'N/A',
			'{phone}'      => $data['phone'] ?? 'N/A',
			'{site_name}'  => get_option( 'blogname' ),
			'{date}'       => gmdate( 'Y-m-d' ),
			'{time}'       => gmdate( 'H:i:s' ) . ' UTC',
		];
	}

	public static function fill( string $template, array $ph ): string {
		return str_replace( array_keys( $ph ), array_values( $ph ), $template );
	}

	// ── Default templates ─────────────────────────────────────────────────────

	public static function default_user_subject(): string {
		return 'Thank you for your NDIS enquiry – {site_name}';
	}

	public static function default_user_body(): string {
		return "Hi {first_name},\n\nThank you for submitting your NDIS referral form. We have received your details and a member of our team will be in touch with you shortly.\n\nIf you have any urgent questions in the meantime, please don't hesitate to contact us directly.\n\nKind regards,\n{site_name}";
	}

	public static function default_admin_subject(): string {
		return 'New NDIS Form Submission – {site_name}';
	}

	public static function default_admin_body(): string {
		return "A new NDIS referral form has been submitted.\n\n----------------------------\nName  : {full_name}\nEmail : {email}\nPhone : {phone}\nDate  : {date}\nTime  : {time}\n----------------------------\n\nPlease log in to iinsight to review and action this referral.";
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private static function headers(): array {
		return [ 'Content-Type: text/plain; charset=UTF-8' ];
	}
}
