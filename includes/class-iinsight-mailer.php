<?php
/**
 * Class Iinsight_Mailer
 *
 * Composes and dispatches user acknowledgement + admin notification emails.
 * Subject and body are editable from the admin settings page.
 *
 * Supported placeholders: {first_name} {last_name} {full_name}
 *                         {email} {phone} {funding_type}
 *                         {site_name} {date} {time}
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
		// Configure SMTP once before any wp_mail() call
		Iinsight_SMTP::init();

		return [
			'user_email'  => self::send_user_ack( $data ),
			'admin_email' => self::send_admin_notification( $data ),
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
		$body  = wpautop( self::fill( $opts['user_email_body'] ?: self::default_user_body(), $ph ) );

		$sent = wp_mail( $to, $subj, $body, self::headers() );

		if ( $sent ) {
			Iinsight_Logger::info( 'User acknowledgement sent.', [ 'to' => $to ] );
			return 'sent';
		}

		Iinsight_Logger::error( 'User acknowledgement FAILED.', [ 'to' => $to ] );
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
		$body = wpautop( self::fill( $opts['admin_email_body'] ?: self::default_admin_body(), $ph ) );

		$sent = wp_mail( $to, $subj, $body, self::headers() );

		if ( $sent ) {
			Iinsight_Logger::info( 'Admin notification sent.', [ 'to' => $to ] );
			return 'sent';
		}

		Iinsight_Logger::error( 'Admin notification FAILED.', [ 'to' => $to ] );
		return 'failed';
	}

	// ── Placeholder system ────────────────────────────────────────────────────

	public static function placeholders( array $data ): array {
		$first = trim( $data['first_name'] ?? '' );
		$last  = trim( $data['last_name']  ?? '' );
		$funding = $data['ndis_funding'] ?? '';
		$plan    = $data['plan_type']    ?? '';
		$funding_display = $funding;
		if ( $funding === 'Yes' && ! empty( $plan ) ) {
			$funding_display = "Yes — $plan";
		}

		return [
			'{first_name}'   => $first ?: 'there',
			'{last_name}'    => $last,
			'{full_name}'    => trim( "$first $last" ) ?: 'Unknown',
			'{email}'        => $data['email'] ?? 'N/A',
			'{phone}'        => $data['phone'] ?? 'N/A',
			'{funding_type}' => $funding_display ?: 'N/A',
			'{site_name}'    => get_option( 'blogname' ),
			'{date}'         => gmdate( 'Y-m-d' ),
			'{time}'         => gmdate( 'H:i:s' ) . ' UTC',
		];
	}

	public static function fill( string $template, array $ph ): string {
		return str_replace( array_keys( $ph ), array_values( $ph ), $template );
	}

	// ── Default templates ─────────────────────────────────────────────────────

	public static function default_user_subject(): string {
		return "Your CITTA Intake Has Been Received — Here's What Happens Next";
	}

	public static function default_user_body(): string {
		return '<p>Hi {first_name},</p>
<p>Thank you for taking the first step.</p>
<p>We\'ve received your details and your request to access the CITTA System.</p>
<p><strong>What happens next:</strong></p>
<ul>
<li>One of our team will contact you within 1 business day</li>
<li>We\'ll understand your situation and support needs</li>
<li>Guide you through the Citta Foundation Program</li>
<li>Help you get started based on your funding pathway</li>
</ul>
<p>CITTA is designed to provide structured support and real progress, not just ongoing sessions.</p>
<p>If you have any urgent questions, feel free to reply to this email.</p>
<p>We look forward to speaking with you.</p>
<p><strong>CITTA Team</strong><br />Structured Trauma Recovery System</p>';
	}

	public static function default_admin_subject(): string {
		return 'New Lead Submission — CITTA Intake';
	}

	public static function default_admin_body(): string {
		return '<p>A new lead has been submitted via the CITTA intake form.</p>
<p><strong>Lead Details:</strong></p>
<ul>
<li><strong>Name:</strong> {full_name}</li>
<li><strong>Email:</strong> {email}</li>
<li><strong>Phone:</strong> {phone}</li>
<li><strong>Funding Type:</strong> {funding_type}</li>
<li><strong>Date:</strong> {date}</li>
<li><strong>Time:</strong> {time}</li>
</ul>
<p><strong>Please review and follow up with this lead within 24 hours.</strong></p>';
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private static function headers(): array {
		return [ 'Content-Type: text/html; charset=UTF-8' ];
	}
}
