<?php
/**
 * Class Iinsight_Admin
 *
 * Admin settings page with four tabbed sections:
 *   1. General      — enable/disable, admin email, debug log toggle
 *   2. Email Content — editable subject + body with clickable placeholders
 *   3. Mail Method   — WordPress mail vs SMTP with full config + live test
 *   4. Debug Log     — viewer, month selector, clear, download
 *
 * @package iinsight-notifications
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Iinsight_Admin {

	const MENU_SLUG    = 'iinsight-notifications';
	const OPTION_GROUP = 'iinsight_settings_group';
	const OPTION_NAME  = 'iinsight_settings';
	const ADMIN_NONCE  = 'iinsight_admin_nonce';

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( 'admin_menu',            [ __CLASS__, 'register_menu' ] );
		add_action( 'admin_init',            [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'admin_post_iinsight_clear_log',   [ __CLASS__, 'handle_clear_log' ] );
		add_action( 'wp_ajax_iinsight_smtp_test',      [ __CLASS__, 'handle_smtp_test' ] );
		add_action( 'wp_ajax_iinsight_reset_template', [ __CLASS__, 'handle_reset_template' ] );
	}

	// ── Menu ──────────────────────────────────────────────────────────────────

	public static function register_menu(): void {
		add_options_page(
			__( 'iinsight Notifications', 'iinsight-notifications' ),
			__( 'iinsight Notify', 'iinsight-notifications' ),
			'manage_options',
			self::MENU_SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	// ── Settings ──────────────────────────────────────────────────────────────

	public static function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			[
				'sanitize_callback' => [ __CLASS__, 'sanitize_settings' ],
				'default'           => self::default_settings(),
			]
		);
	}

	public static function default_settings(): array {
		return [
			// General
			'enable_notifications' => '1',
			'admin_email_override' => '',
			'enable_debug_log'     => '1',
			// Email content
			'user_email_subject'   => '',
			'user_email_body'      => '',
			'admin_email_subject'  => '',
			'admin_email_body'     => '',
			// Mail method
			'mail_method'          => 'wp_mail',
			'smtp_host'            => '',
			'smtp_port'            => '587',
			'smtp_encryption'      => 'tls',
			'smtp_username'        => '',
			'smtp_password'        => '',
			'smtp_from_email'      => '',
			'smtp_from_name'       => '',
		];
	}

	public static function sanitize_settings( $input ): array {
		$clean    = self::default_settings();
		$existing = (array) get_option( self::OPTION_NAME, [] );

		// General
		$clean['enable_notifications'] = ! empty( $input['enable_notifications'] ) ? '1' : '0';
		$clean['enable_debug_log']     = ! empty( $input['enable_debug_log'] )     ? '1' : '0';
		$clean['admin_email_override'] = is_email( $input['admin_email_override'] ?? '' )
			? sanitize_email( $input['admin_email_override'] )
			: '';

		// Email content
		$clean['user_email_subject']  = sanitize_text_field(     $input['user_email_subject']  ?? '' );
		$clean['user_email_body']     = wp_kses_post( $input['user_email_body']     ?? '' );
		$clean['admin_email_subject'] = sanitize_text_field(     $input['admin_email_subject'] ?? '' );
		$clean['admin_email_body']    = wp_kses_post( $input['admin_email_body']    ?? '' );

		// Mail method
		$clean['mail_method'] = in_array( $input['mail_method'] ?? '', [ 'wp_mail', 'smtp' ], true )
			? $input['mail_method']
			: 'wp_mail';

		// SMTP settings
		$clean['smtp_host']       = sanitize_text_field( $input['smtp_host']      ?? '' );
		$clean['smtp_port']       = (string) ( absint( $input['smtp_port'] ?? 587 ) ?: 587 );
		$clean['smtp_encryption'] = in_array( $input['smtp_encryption'] ?? '', [ 'tls', 'ssl', 'none' ], true )
			? $input['smtp_encryption']
			: 'tls';
		$clean['smtp_username']   = sanitize_text_field( $input['smtp_username']  ?? '' );
		$clean['smtp_from_email'] = is_email( $input['smtp_from_email'] ?? '' )
			? sanitize_email( $input['smtp_from_email'] )
			: '';
		$clean['smtp_from_name']  = sanitize_text_field( $input['smtp_from_name'] ?? '' );

		// Password — only re-encrypt if a real new value submitted (not the masked placeholder)
		$new_pw = $input['smtp_password'] ?? '';
		if ( $new_pw !== '' && $new_pw !== '••••••••' ) {
			$clean['smtp_password'] = Iinsight_SMTP::encrypt( $new_pw );
		} else {
			$clean['smtp_password'] = $existing['smtp_password'] ?? '';
		}

		return $clean;
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	public static function enqueue_assets( string $hook ): void {
		if ( 'settings_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		// Ensure jQuery is loaded (it is by default in WP admin, but be explicit)
		wp_enqueue_script( 'jquery' );

		$css = '
			/* ── Tabs ───────────────────────────────────────── */
			.iinsight-nav-tabs {
				display:flex; gap:0; border-bottom:2px solid #c3c4c7;
				margin:16px 0 24px; padding:0;
			}
			.iinsight-nav-tab {
				background:none; border:none; border-bottom:3px solid transparent;
				padding:10px 22px; font-size:14px; font-weight:500; color:#50575e;
				cursor:pointer; margin-bottom:-2px; transition:color .15s,border-color .15s;
				text-decoration:none; display:inline-block;
			}
			.iinsight-nav-tab:hover   { color:#2271b1; }
			.iinsight-nav-tab.active  { color:#2271b1; border-bottom-color:#2271b1; }
			.iinsight-tab-panel       { display:none; }
			.iinsight-tab-panel.active{ display:block; }

			/* ── SMTP conditional rows ────────────────────── */
			.iinsight-smtp-row        { display:none; }
			.smtp-method-active .iinsight-smtp-row { display:table-row; }

			/* ── Placeholder tags ────────────────────────── */
			.iinsight-ph-wrap { margin:0 0 8px; }
			.iinsight-ph-tag {
				display:inline-block; background:#f0f6fc; border:1px solid #c3c4c7;
				border-radius:3px; padding:2px 8px; font-family:monospace; font-size:12px;
				color:#2271b1; cursor:pointer; margin:2px 2px 2px 0;
				transition:background .15s;
			}
			.iinsight-ph-tag:hover { background:#dce9f5; }

			/* ── Log viewer ──────────────────────────────── */
			.iinsight-log-box {
				background:#1a1a2e; color:#e0e0e0; font-family:monospace;
				font-size:12px; line-height:1.7; padding:16px; border-radius:6px;
				max-height:520px; overflow-y:auto; white-space:pre-wrap; word-break:break-all;
				border:1px solid #2d2d44;
			}
			.iinsight-log-box .lvl-info    { color:#4fc3f7; }
			.iinsight-log-box .lvl-warning { color:#ffb74d; }
			.iinsight-log-box .lvl-error   { color:#ef5350; }
			.iinsight-log-box .lvl-debug   { color:#757575; }
			.iinsight-log-toolbar {
				display:flex; align-items:center; gap:10px;
				margin-bottom:12px; flex-wrap:wrap;
			}

			/* ── Misc ────────────────────────────────────── */
			.iinsight-badge {
				background:#2271b1; color:#fff; font-size:11px;
				padding:2px 9px; border-radius:10px; vertical-align:middle;
				display:inline-block;
			}
			.iinsight-section-note { color:#646970; font-style:italic; margin:0 0 16px; }
			textarea.iinsight-body { width:100%; min-height:150px; font-family:monospace; font-size:13px; }
			.iinsight-test-result  { margin-top:8px; font-weight:600; }
			.iinsight-test-result.ok  { color:#00a32a; }
			.iinsight-test-result.err { color:#d63638; }
			#iinsight-smtp-table th { width:220px; }
		';
		wp_add_inline_style( 'wp-admin', $css );

		$nonce = wp_create_nonce( self::ADMIN_NONCE );
		$js    = <<<JS
jQuery(function($){

	/* ── Tab switching ───────────────────────────────────────── */
	function activateTab(id) {
		$('.iinsight-nav-tab').removeClass('active');
		$('.iinsight-tab-panel').removeClass('active');
		$('.iinsight-nav-tab[data-tab="' + id + '"]').addClass('active');
		$('#iinsight-tab-' + id).addClass('active');
		try { history.replaceState(null, '', '#tab-' + id); } catch(e){}
	}
	$('.iinsight-nav-tab').on('click', function(e){
		e.preventDefault();
		activateTab($(this).data('tab'));
	});
	var initTab = (window.location.hash || '').replace('#tab-','');
	activateTab(initTab || 'general');

	/* ── SMTP field visibility ───────────────────────────────── */
	function toggleSmtp(){
		var method = $('input[name="iinsight_settings[mail_method]"]:checked').val();
		if(method === 'smtp'){
			$('#iinsight-smtp-table').addClass('smtp-method-active');
		} else {
			$('#iinsight-smtp-table').removeClass('smtp-method-active');
		}
	}
	$('input[name="iinsight_settings[mail_method]"]').on('change', toggleSmtp);
	toggleSmtp();

	/* ── Placeholder click-to-insert (supports wp_editor) ────── */
	$(document).on('click', '.iinsight-ph-tag', function(){
		var tag    = $(this).text();
		var target = $(this).closest('.iinsight-ph-group').data('for');
		var editor = typeof tinymce !== 'undefined' ? tinymce.get(target) : null;
		if(editor && !editor.isHidden()){
			editor.execCommand('mceInsertContent', false, tag);
		} else {
			var el = document.getElementById(target);
			if(!el) return;
			var s = el.selectionStart, e2 = el.selectionEnd, v = el.value;
			el.value = v.slice(0, s) + tag + v.slice(e2);
			el.selectionStart = el.selectionEnd = s + tag.length;
			el.focus();
		}
	});

	/* ── Reset email template (supports wp_editor) ──────────── */
	$('.iinsight-reset-tpl').on('click', function(){
		if(!confirm('Reset to default? Your custom content will be lost.')) return;
		var which = $(this).data('which');
		$.post(ajaxurl, {action:'iinsight_reset_template', nonce:'{$nonce}', which:which}, function(r){
			if(r.success){
				$('#iinsight_' + which + '_subject').val(r.data.subject);
				var editorId = 'iinsight_' + which + '_body';
				var editor   = typeof tinymce !== 'undefined' ? tinymce.get(editorId) : null;
				if(editor && !editor.isHidden()){
					editor.setContent(r.data.body);
				} else {
					$('#' + editorId).val(r.data.body);
				}
			}
		});
	});

	/* ── SMTP test email ─────────────────────────────────────── */
	$('#iinsight-test-btn').on('click', function(){
		var \$btn = $(this), \$res = $('#iinsight-test-result');
		var to  = $('#iinsight-test-to').val().trim();
		if(!to){ \$res.text('Please enter a recipient email.').removeClass('ok').addClass('err'); return; }
		\$btn.prop('disabled', true).text('Sending…');
		\$res.text('').removeClass('ok err');
		$.post(ajaxurl, {action:'iinsight_smtp_test', nonce:'{$nonce}', to:to}, function(r){
			\$btn.prop('disabled', false).text('Send Test Email');
			if(r.success){
				\$res.text(r.data.message).removeClass('err').addClass('ok');
			} else {
				\$res.text(r.data.message).removeClass('ok').addClass('err');
			}
		}).fail(function(){
			\$btn.prop('disabled', false).text('Send Test Email');
			\$res.text('Request failed — check browser console.').removeClass('ok').addClass('err');
		});
	});

});
JS;
		wp_add_inline_script( 'jquery', $js );
	}

	// ── AJAX: SMTP test ───────────────────────────────────────────────────────

	public static function handle_smtp_test(): void {
		check_ajax_referer( self::ADMIN_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
		}
		$to     = sanitize_email( wp_unslash( $_POST['to'] ?? '' ) );
		$result = Iinsight_SMTP::send_test( $to );
		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	// ── AJAX: Reset template ──────────────────────────────────────────────────

	public static function handle_reset_template(): void {
		check_ajax_referer( self::ADMIN_NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [], 403 );
		}
		$which = sanitize_key( $_POST['which'] ?? '' );
		if ( $which === 'user' ) {
			wp_send_json_success( [
				'subject' => Iinsight_Mailer::default_user_subject(),
				'body'    => Iinsight_Mailer::default_user_body(),
			] );
		}
		if ( $which === 'admin' ) {
			wp_send_json_success( [
				'subject' => Iinsight_Mailer::default_admin_subject(),
				'body'    => Iinsight_Mailer::default_admin_body(),
			] );
		}
		wp_send_json_error( [ 'message' => 'Unknown template.' ] );
	}

	// ── Clear log ─────────────────────────────────────────────────────────────

	public static function handle_clear_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'iinsight-notifications' ) );
		}
		check_admin_referer( 'iinsight_clear_log' );
		Iinsight_Logger::clear_current_log();
		Iinsight_Logger::info( 'Log cleared by admin.' );
		wp_safe_redirect( add_query_arg(
			[ 'page' => self::MENU_SLUG, 'cleared' => '1' ],
			admin_url( 'options-general.php' )
		) );
		exit;
	}

	// ── Page renderer ─────────────────────────────────────────────────────────

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Handle log download before any output
		if (
			isset( $_GET['iinsight_dl'] ) &&
			check_admin_referer( 'iinsight_dl' ) &&
			current_user_can( 'manage_options' )
		) {
			self::stream_download();
		}

		$opts         = self::get_settings();
		$log_files    = Iinsight_Logger::get_log_files();
		$selected_log = isset( $_GET['log_file'] ) ? sanitize_file_name( wp_unslash( $_GET['log_file'] ) ) : '';
		$log_contents = $selected_log
			? Iinsight_Logger::get_file_contents( $selected_log )
			: Iinsight_Logger::get_log_contents();

		$ph_tags = self::placeholder_tags_html();
		?>
		<div class="wrap">

			<h1>
				<?php esc_html_e( 'iinsight Form Notifications', 'iinsight-notifications' ); ?>
				<span class="iinsight-badge">v<?php echo esc_html( IINSIGHT_VERSION ); ?></span>
			</h1>

			<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><strong><?php esc_html_e( 'Settings saved.', 'iinsight-notifications' ); ?></strong></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['cleared'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Log file cleared.', 'iinsight-notifications' ); ?></p></div>
			<?php endif; ?>

			<!-- ── Tab navigation ──────────────────────────────────────── -->
			<div class="iinsight-nav-tabs" role="tablist">
				<a class="iinsight-nav-tab" data-tab="general"       href="#tab-general"       role="tab"><?php esc_html_e( 'General', 'iinsight-notifications' ); ?></a>
				<a class="iinsight-nav-tab" data-tab="email-content"  href="#tab-email-content" role="tab"><?php esc_html_e( 'Email Content', 'iinsight-notifications' ); ?></a>
				<a class="iinsight-nav-tab" data-tab="mail-method"    href="#tab-mail-method"   role="tab"><?php esc_html_e( 'Mail Method', 'iinsight-notifications' ); ?></a>
				<a class="iinsight-nav-tab" data-tab="log"            href="#tab-log"           role="tab"><?php esc_html_e( 'Debug Log', 'iinsight-notifications' ); ?></a>
			</div>

			<!-- Settings form wraps General + Email Content + Mail Method tabs -->
			<form method="post" action="options.php" novalidate>
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<!-- ══════════════════════════════════════════════════════
				     TAB 1 — GENERAL
				     ══════════════════════════════════════════════════════ -->
				<div id="iinsight-tab-general" class="iinsight-tab-panel">

					<table class="form-table" role="presentation">

						<tr>
							<th scope="row"><?php esc_html_e( 'Enable Notifications', 'iinsight-notifications' ); ?></th>
							<td>
								<label>
									<input type="checkbox"
										name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enable_notifications]"
										value="1" <?php checked( '1', $opts['enable_notifications'] ); ?> />
									<?php esc_html_e( 'Send acknowledgement + admin emails on successful form submission', 'iinsight-notifications' ); ?>
								</label>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="iinsight-admin-email"><?php esc_html_e( 'Admin Notification Email', 'iinsight-notifications' ); ?></label>
							</th>
							<td>
								<input type="email" id="iinsight-admin-email" class="regular-text"
									name="<?php echo esc_attr( self::OPTION_NAME ); ?>[admin_email_override]"
									value="<?php echo esc_attr( $opts['admin_email_override'] ); ?>"
									placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
								<p class="description">
									<?php esc_html_e( 'Leave blank to use the default WordPress admin email.', 'iinsight-notifications' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Debug Log', 'iinsight-notifications' ); ?></th>
							<td>
								<label>
									<input type="checkbox"
										name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enable_debug_log]"
										value="1" <?php checked( '1', $opts['enable_debug_log'] ); ?> />
									<?php esc_html_e( 'Write submission and email events to the plugin debug log', 'iinsight-notifications' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Log entries are visible under the "Debug Log" tab.', 'iinsight-notifications' ); ?>
								</p>
							</td>
						</tr>

					</table>
					<?php submit_button( __( 'Save General Settings', 'iinsight-notifications' ) ); ?>
				</div>

				<!-- ══════════════════════════════════════════════════════
				     TAB 2 — EMAIL CONTENT
				     ══════════════════════════════════════════════════════ -->
				<div id="iinsight-tab-email-content" class="iinsight-tab-panel">

					<p class="iinsight-section-note">
						<?php esc_html_e( 'Customise the subject and body for each email. Click any tag below a field to insert it at the cursor position.', 'iinsight-notifications' ); ?>
					</p>

					<!-- ── User / Acknowledgement ──────────────────────── -->
					<h3>📨 <?php esc_html_e( 'User Acknowledgement Email', 'iinsight-notifications' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Sent to the person who submitted the NDIS form.', 'iinsight-notifications' ); ?></p>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="iinsight_user_subject"><?php esc_html_e( 'Subject', 'iinsight-notifications' ); ?></label>
							</th>
							<td>
								<div class="iinsight-ph-group iinsight-ph-wrap" data-for="iinsight_user_subject"><?php echo $ph_tags; // phpcs:ignore ?></div>
								<input type="text" id="iinsight_user_subject" class="large-text"
									name="<?php echo esc_attr( self::OPTION_NAME ); ?>[user_email_subject]"
									value="<?php echo esc_attr( $opts['user_email_subject'] ?: Iinsight_Mailer::default_user_subject() ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="iinsight_user_body"><?php esc_html_e( 'Body', 'iinsight-notifications' ); ?></label>
							</th>
							<td>
								<div class="iinsight-ph-group iinsight-ph-wrap" data-for="iinsight_user_body"><?php echo $ph_tags; // phpcs:ignore ?></div>
								<?php wp_editor(
									$opts['user_email_body'] ?: Iinsight_Mailer::default_user_body(),
									'iinsight_user_body',
									[
										'textarea_name' => self::OPTION_NAME . '[user_email_body]',
										'textarea_rows' => 12,
										'media_buttons' => false,
										'teeny'         => false,
										'quicktags'     => true,
									]
								); ?>
								<p>
									<button type="button" class="button iinsight-reset-tpl" data-which="user">
										↺ <?php esc_html_e( 'Reset to Default', 'iinsight-notifications' ); ?>
									</button>
								</p>
							</td>
						</tr>
					</table>

					<hr />

					<!-- ── Admin notification ──────────────────────────── -->
					<h3>🔔 <?php esc_html_e( 'Admin Notification Email', 'iinsight-notifications' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Sent to the admin email address when a form is submitted.', 'iinsight-notifications' ); ?></p>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="iinsight_admin_subject"><?php esc_html_e( 'Subject', 'iinsight-notifications' ); ?></label>
							</th>
							<td>
								<div class="iinsight-ph-group iinsight-ph-wrap" data-for="iinsight_admin_subject"><?php echo $ph_tags; // phpcs:ignore ?></div>
								<input type="text" id="iinsight_admin_subject" class="large-text"
									name="<?php echo esc_attr( self::OPTION_NAME ); ?>[admin_email_subject]"
									value="<?php echo esc_attr( $opts['admin_email_subject'] ?: Iinsight_Mailer::default_admin_subject() ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="iinsight_admin_body"><?php esc_html_e( 'Body', 'iinsight-notifications' ); ?></label>
							</th>
							<td>
								<div class="iinsight-ph-group iinsight-ph-wrap" data-for="iinsight_admin_body"><?php echo $ph_tags; // phpcs:ignore ?></div>
								<?php wp_editor(
									$opts['admin_email_body'] ?: Iinsight_Mailer::default_admin_body(),
									'iinsight_admin_body',
									[
										'textarea_name' => self::OPTION_NAME . '[admin_email_body]',
										'textarea_rows' => 12,
										'media_buttons' => false,
										'teeny'         => false,
										'quicktags'     => true,
									]
								); ?>
								<p>
									<button type="button" class="button iinsight-reset-tpl" data-which="admin">
										↺ <?php esc_html_e( 'Reset to Default', 'iinsight-notifications' ); ?>
									</button>
								</p>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Save Email Content', 'iinsight-notifications' ) ); ?>
				</div>

				<!-- ══════════════════════════════════════════════════════
				     TAB 3 — MAIL METHOD
				     ══════════════════════════════════════════════════════ -->
				<div id="iinsight-tab-mail-method" class="iinsight-tab-panel">

					<p class="iinsight-section-note">
						<?php esc_html_e( '"WordPress Mail" uses your hosting\'s default mail system — no setup needed. "SMTP" routes mail through a dedicated server (Gmail, SendGrid, Mailgun, etc.).', 'iinsight-notifications' ); ?>
					</p>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Mail Method', 'iinsight-notifications' ); ?></th>
							<td>
								<fieldset>
									<label style="display:block;margin-bottom:10px;">
										<input type="radio"
											name="<?php echo esc_attr( self::OPTION_NAME ); ?>[mail_method]"
											value="wp_mail"
											<?php checked( 'wp_mail', $opts['mail_method'] ); ?> />
										<strong><?php esc_html_e( 'WordPress Mail', 'iinsight-notifications' ); ?></strong>
										&nbsp;—&nbsp;<?php esc_html_e( 'No configuration required', 'iinsight-notifications' ); ?>
									</label>
									<label style="display:block;">
										<input type="radio"
											name="<?php echo esc_attr( self::OPTION_NAME ); ?>[mail_method]"
											value="smtp"
											<?php checked( 'smtp', $opts['mail_method'] ); ?> />
										<strong><?php esc_html_e( 'SMTP', 'iinsight-notifications' ); ?></strong>
										&nbsp;—&nbsp;<?php esc_html_e( 'Custom mail server', 'iinsight-notifications' ); ?>
									</label>
								</fieldset>
							</td>
						</tr>
					</table>

					<!-- SMTP detail fields — shown only when SMTP is selected -->
					<table id="iinsight-smtp-table" class="form-table <?php echo $opts['mail_method'] === 'smtp' ? 'smtp-method-active' : ''; ?>" role="presentation">

						<tr class="iinsight-smtp-row">
							<th scope="row"><label for="iinsight-smtp-host"><?php esc_html_e( 'SMTP Host', 'iinsight-notifications' ); ?></label></th>
							<td>
								<input type="text" id="iinsight-smtp-host" class="regular-text"
									name="<?php echo esc_attr( self::OPTION_NAME ); ?>[smtp_host]"
									value="<?php echo esc_attr( $opts['smtp_host'] ); ?>"
									placeholder="smtp.gmail.com" autocomplete="off" />
								<p class="description">
									<?php esc_html_e( 'Examples: smtp.gmail.com · smtp.sendgrid.net · smtp.mailgun.org · smtp-mail.outlook.com', 'iinsight-notifications' ); ?>
								</p>
							</td>
						</tr>

						<tr class="iinsight-smtp-row">
							<th scope="row"><label for="iinsight-smtp-port"><?php esc_html_e( 'SMTP Port', 'iinsight-notifications' ); ?></label></th>
							<td>
								<input type="number" id="iinsight-smtp-port" class="small-text"
									name="<?php echo esc_attr( self::OPTION_NAME ); ?>[smtp_port]"
									value="<?php echo esc_attr( $opts['smtp_port'] ); ?>"
									min="1" max="65535" />
								<p class="description">
									<?php esc_html_e( 'TLS: 587 (recommended)  ·  SSL: 465  ·  No encryption: 25', 'iinsight-notifications' ); ?>
								</p>
							</td>
						</tr>

						<tr class="iinsight-smtp-row">
							<th scope="row"><?php esc_html_e( 'Encryption', 'iinsight-notifications' ); ?></th>
							<td>
								<fieldset style="display:flex;gap:24px;flex-wrap:wrap;">
									<?php foreach ( [ 'tls' => 'TLS / STARTTLS', 'ssl' => 'SSL', 'none' => 'None' ] as $v => $label ) : ?>
									<label>
										<input type="radio"
											name="<?php echo esc_attr( self::OPTION_NAME ); ?>[smtp_encryption]"
											value="<?php echo esc_attr( $v ); ?>"
											<?php checked( $v, $opts['smtp_encryption'] ); ?> />
										<?php echo esc_html( $label ); ?>
									</label>
									<?php endforeach; ?>
								</fieldset>
							</td>
						</tr>

						<tr class="iinsight-smtp-row">
							<th scope="row"><label for="iinsight-smtp-user"><?php esc_html_e( 'Username', 'iinsight-notifications' ); ?></label></th>
							<td>
								<input type="text" id="iinsight-smtp-user" class="regular-text"
									name="<?php echo esc_attr( self::OPTION_NAME ); ?>[smtp_username]"
									value="<?php echo esc_attr( $opts['smtp_username'] ); ?>"
									autocomplete="off" />
								<p class="description">
									<?php esc_html_e( 'Usually your full email address. Leave blank if authentication is not required.', 'iinsight-notifications' ); ?>
								</p>
							</td>
						</tr>

						<tr class="iinsight-smtp-row">
							<th scope="row"><label for="iinsight-smtp-pass"><?php esc_html_e( 'Password', 'iinsight-notifications' ); ?></label></th>
							<td>
								<input type="password" id="iinsight-smtp-pass" class="regular-text"
									name="<?php echo esc_attr( self::OPTION_NAME ); ?>[smtp_password]"
									value="<?php echo empty( $opts['smtp_password'] ) ? '' : '••••••••'; ?>"
									autocomplete="new-password" />
								<p class="description">
									<?php if ( ! empty( $opts['smtp_password'] ) ) : ?>
										<?php esc_html_e( 'Password is saved. Enter a new value only to change it.', 'iinsight-notifications' ); ?>
									<?php else : ?>
										<?php esc_html_e( 'Enter your SMTP password or app-specific password.', 'iinsight-notifications' ); ?>
									<?php endif; ?>
								</p>
							</td>
						</tr>

						<tr class="iinsight-smtp-row">
							<th scope="row"><label for="iinsight-smtp-from-email"><?php esc_html_e( 'From Email', 'iinsight-notifications' ); ?></label></th>
							<td>
								<input type="email" id="iinsight-smtp-from-email" class="regular-text"
									name="<?php echo esc_attr( self::OPTION_NAME ); ?>[smtp_from_email]"
									value="<?php echo esc_attr( $opts['smtp_from_email'] ); ?>"
									placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
								<p class="description">
									<?php esc_html_e( 'The "From" address shown to recipients. Must match your SMTP account for most providers.', 'iinsight-notifications' ); ?>
								</p>
							</td>
						</tr>

						<tr class="iinsight-smtp-row">
							<th scope="row"><label for="iinsight-smtp-from-name"><?php esc_html_e( 'From Name', 'iinsight-notifications' ); ?></label></th>
							<td>
								<input type="text" id="iinsight-smtp-from-name" class="regular-text"
									name="<?php echo esc_attr( self::OPTION_NAME ); ?>[smtp_from_name]"
									value="<?php echo esc_attr( $opts['smtp_from_name'] ); ?>"
									placeholder="<?php echo esc_attr( get_option( 'blogname' ) ); ?>" />
							</td>
						</tr>

					</table>

					<?php submit_button( __( 'Save Mail Settings', 'iinsight-notifications' ) ); ?>

					<!-- ── SMTP test — outside the save form ───────────── -->
					<?php if ( $opts['mail_method'] === 'smtp' ) : ?>
					<hr />
					<h3><?php esc_html_e( 'Test SMTP Connection', 'iinsight-notifications' ); ?></h3>
					<p class="description">
						<?php esc_html_e( 'Save your settings above first, then send a test email to confirm everything is working.', 'iinsight-notifications' ); ?>
					</p>
					<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:10px;">
						<input type="email" id="iinsight-test-to" class="regular-text"
							value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"
							placeholder="<?php esc_attr_e( 'Recipient email', 'iinsight-notifications' ); ?>" />
						<button type="button" id="iinsight-test-btn" class="button button-secondary">
							<?php esc_html_e( 'Send Test Email', 'iinsight-notifications' ); ?>
						</button>
					</div>
					<p id="iinsight-test-result" class="iinsight-test-result"></p>
					<?php endif; ?>

				</div>

			</form><!-- end settings form -->

			<!-- ══════════════════════════════════════════════════════
			     TAB 4 — DEBUG LOG  (outside settings form intentionally)
			     ══════════════════════════════════════════════════════ -->
			<div id="iinsight-tab-log" class="iinsight-tab-panel">

				<p class="iinsight-section-note">
					<?php
					printf(
						/* translators: path to log directory */
						esc_html__( 'Logs are stored in %s and are not publicly accessible. A new file is created each calendar month.', 'iinsight-notifications' ),
						'<code>' . esc_html( str_replace( ABSPATH, '', IINSIGHT_PLUGIN_DIR ) . 'logs/' ) . '</code>'
					);
					?>
				</p>

				<div class="iinsight-log-toolbar">

					<!-- Month selector -->
					<?php if ( ! empty( $log_files ) ) : ?>
					<form method="get">
						<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
						<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'iinsight_view_log' ) ); ?>" />
						<select name="log_file" onchange="this.form.submit()" style="max-width:220px;">
							<option value=""><?php esc_html_e( '— Current month —', 'iinsight-notifications' ); ?></option>
							<?php foreach ( $log_files as $file ) : ?>
								<option value="<?php echo esc_attr( basename( $file ) ); ?>"
									<?php selected( $selected_log, basename( $file ) ); ?>>
									<?php echo esc_html( basename( $file ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</form>
					<?php endif; ?>

					<!-- Clear log -->
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="iinsight_clear_log" />
						<?php wp_nonce_field( 'iinsight_clear_log' ); ?>
						<button type="submit" class="button button-secondary"
							onclick="return confirm('<?php echo esc_js( __( 'Clear the current log file? This cannot be undone.', 'iinsight-notifications' ) ); ?>');">
							🗑 <?php esc_html_e( 'Clear Log', 'iinsight-notifications' ); ?>
						</button>
					</form>

					<!-- Download log -->
					<?php
					$log_path = IINSIGHT_PLUGIN_DIR . 'logs/iinsight-' . gmdate( 'Y-m' ) . '.log';
					if ( file_exists( $log_path ) ) :
					?>
					<a class="button button-secondary"
						href="<?php echo esc_url( wp_nonce_url(
							add_query_arg( [ 'page' => self::MENU_SLUG, 'iinsight_dl' => '1' ], admin_url( 'options-general.php' ) ),
							'iinsight_dl'
						) ); ?>">
						⬇ <?php esc_html_e( 'Download Log', 'iinsight-notifications' ); ?>
					</a>
					<?php endif; ?>

				</div>

				<!-- Log content -->
				<div class="iinsight-log-box" aria-label="Debug log">
					<?php if ( empty( $log_contents ) ) : ?>
						<span style="color:#555;"><?php esc_html_e( '(Log is empty — submit the form to generate entries)', 'iinsight-notifications' ); ?></span>
					<?php else : ?>
						<?php echo self::colorize_log( esc_html( $log_contents ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php endif; ?>
				</div>

			</div><!-- #iinsight-tab-log -->

		</div><!-- .wrap -->
		<?php
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private static function placeholder_tags_html(): string {
		$tags = [ '{first_name}', '{last_name}', '{full_name}', '{email}', '{phone}', '{funding_type}', '{site_name}', '{date}', '{time}' ];
		$out  = '';
		foreach ( $tags as $tag ) {
			$out .= '<span class="iinsight-ph-tag" title="' . esc_attr__( 'Click to insert', 'iinsight-notifications' ) . '">'
				. esc_html( $tag ) . '</span>';
		}
		return $out;
	}

	private static function colorize_log( string $html ): string {
		$html = preg_replace( '/(\[INFO\])/',    '<span class="lvl-info">$1</span>',    $html );
		$html = preg_replace( '/(\[WARNING\])/', '<span class="lvl-warning">$1</span>', $html );
		$html = preg_replace( '/(\[ERROR\])/',   '<span class="lvl-error">$1</span>',   $html );
		$html = preg_replace( '/(\[DEBUG\])/',   '<span class="lvl-debug">$1</span>',   $html );
		return $html;
	}

	private static function stream_download(): void {
		$path = IINSIGHT_PLUGIN_DIR . 'logs/iinsight-' . gmdate( 'Y-m' ) . '.log';
		if ( ! file_exists( $path ) ) {
			return;
		}
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="iinsight-' . gmdate( 'Y-m' ) . '.log"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Cache-Control: no-cache' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		echo file_get_contents( $path );
		exit;
	}

	public static function get_settings(): array {
		return wp_parse_args(
			(array) get_option( self::OPTION_NAME, [] ),
			self::default_settings()
		);
	}
}
