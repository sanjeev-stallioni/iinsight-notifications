# iinsight Form Notifications — WordPress Plugin

Sends acknowledgement and admin notification emails when the iinsight NDIS
external form is successfully submitted. Includes SMTP configuration and a
dedicated debug log viewer inside WordPress admin.

---

## Installation

1. Upload the `iinsight-notifications/` folder to `/wp-content/plugins/`
2. Activate the plugin via **WordPress Admin → Plugins**
3. Configure via **Settings → iinsight Notify**

---

## How It Works

The iinsight external form (embedded via `api_referral.php` script) does not
provide email notifications. This plugin adds them by detecting successful
form submissions and sending emails via WordPress.

1. **JS listener** intercepts the iinsight form's own XHR/fetch API calls to
   `api_referral.php` and captures form field values on submit button click.
2. Only fires after the **submit button is clicked** — conditional field
   changes (e.g. "Do you have NDIS funding?" toggling extra fields) are ignored.
3. Uses `navigator.sendBeacon()` to send the notification request, which
   survives the page redirect to iinsight's `completion_url`.
4. A **MutationObserver** on `phase_1`/`phase_2` visibility acts as a fallback.
5. WordPress AJAX handler validates the nonce, rate-limits, sanitises input,
   and dispatches two emails via `wp_mail()`:
   - **User acknowledgement** — "Thank you for your enquiry" to the submitter
   - **Admin notification** — submission details to the configured admin email

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
│       └── iinsight-listener.js        # Front-end: XHR intercept + sendBeacon + MutationObserver
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
| **Email Content** | Editable subject & body for both emails. Placeholders: `{first_name}` `{last_name}` `{full_name}` `{email}` `{phone}` `{site_name}` `{date}` `{time}` |
| **Mail Method** | WordPress default mail or SMTP (host, port, encryption, auth, from address) with live test button |
| **Debug Log** | View, download, clear monthly log files |

---

## Security

| Feature | Detail |
|---|---|
| Nonce verification | `wp_create_nonce` / `check_ajax_referer` on every AJAX request |
| Input sanitisation | `sanitize_text_field`, `sanitize_email`, `wp_unslash` on all POST values |
| Rate limiting | Max 5 submissions per IP per hour via WordPress transients |
| Capability checks | All admin actions require `manage_options` |
| Directory protection | `/logs/` has `.htaccess` (Deny from all) + `index.php` guard |
| ABSPATH check | Every PHP file exits immediately if loaded directly |
