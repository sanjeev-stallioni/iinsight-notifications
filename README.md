# iinsight Form Notifications — WordPress Plugin

Sends acknowledgement and admin notification HTML emails when any iinsight
external form is successfully submitted. Works with multiple forms on
different pages. Includes SMTP configuration, WordPress visual editor for
email templates, and a dedicated debug log viewer.

---

## Installation

1. Upload the `iinsight-notifications/` folder to `/wp-content/plugins/`
2. Activate the plugin via **WordPress Admin → Plugins**
3. Configure via **Settings → iinsight Notify**

---

## How It Works

1. JS loads on every page, polls until an iinsight form appears
2. XHR intercept patches `XMLHttpRequest` to watch for `api_referral` calls
3. User clicks Submit → form values captured, `submitClicked` flag set
4. iinsight makes API call → intercepted → checks required fields are filled → if valid, sends notification via `sendBeacon` to `admin-ajax.php`
5. WordPress AJAX handler validates nonce, sanitises data, calls mailer
6. Mailer sends two HTML emails: user acknowledgement + admin notification

If required fields are empty or validation errors exist, notification is
skipped and resets for retry. Conditional field changes (e.g. toggling
dropdowns) are ignored because the submit button hasn't been clicked.

---

## Multi-Form Support

The plugin works with any iinsight external form embedded via
`api_referral.php`. Each form can be on a separate page. Validation is
dynamic — it scans for `[required]` fields and `be_ext_form_error` classes
in the DOM, so it adapts to any form layout automatically.

---

## File Structure

```
iinsight-notifications/
├── iinsight-notifications.php          # Plugin bootstrap, constants, activation hooks
├── includes/
│   ├── class-iinsight-admin.php        # Admin settings page (4 tabs: General, Email, SMTP, Log)
│   ├── class-iinsight-ajax.php         # AJAX endpoint (nonce, rate limit, sanitisation)
│   ├── class-iinsight-assets.php       # Enqueues JS + passes PHP vars via wp_localize_script
│   ├── class-iinsight-logger.php       # Dedicated logger (writes to /logs/, separate from WP)
│   ├── class-iinsight-mailer.php       # Email composition & dispatch with placeholder support
│   ├── class-iinsight-smtp.php         # Optional SMTP configuration for wp_mail()
│   └── index.php                       # Directory access guard
├── assets/
│   └── js/
│       └── iinsight-listener.js        # Front-end: XHR intercept + sendBeacon + validation
├── logs/                               # Auto-created on activation, protected by .htaccess
│   ├── .htaccess
│   ├── index.php
│   └── iinsight-YYYY-MM.log           # Monthly rotating log files
├── index.php
└── README.md
```

---

## Admin Settings

Located at **Settings → iinsight Notify** with four tabs:

| Tab | Options |
|---|---|
| **General** | Enable/disable notifications, admin email override, debug log toggle |
| **Email Content** | WordPress visual editor (wp_editor) for both emails. Placeholders: `{first_name}` `{last_name}` `{full_name}` `{email}` `{phone}` `{funding_type}` `{site_name}` `{date}` `{time}` |
| **Mail Method** | WordPress default mail or SMTP (host, port, encryption, auth, from address) with live test button |
| **Debug Log** | View, download, clear monthly log files |

---

## Security

| Feature | Detail |
|---|---|
| Nonce verification | `wp_create_nonce` / `check_ajax_referer` on every AJAX request |
| Input sanitisation | `sanitize_text_field`, `sanitize_email`, `wp_kses_post` on all values |
| Rate limiting | Max 200 submissions per IP per hour via WordPress transients |
| Capability checks | All admin actions require `manage_options` |
| Directory protection | `/logs/` has `.htaccess` (Deny from all) + `index.php` guard |
| ABSPATH check | Every PHP file exits immediately if loaded directly |
