# iinsight Form Notifications — WordPress Plugin

Sends acknowledgement and admin notification emails when the iinsight NDIS
external form is successfully submitted. Includes a dedicated debug log
viewer inside WordPress admin.

---

## Installation

1. Upload the `iinsight-notifications/` folder to `/wp-content/plugins/`
2. Activate the plugin via **WordPress Admin → Plugins**
3. Configure via **Settings → iinsight Notify**

---

## File Structure

```
iinsight-notifications/
│
├── iinsight-notifications.php      # Main bootstrap file (plugin header, constants, activation hooks)
│
├── includes/
│   ├── class-iinsight-logger.php   # Dedicated debug logger (writes to /logs/, separate from WP debug.log)
│   ├── class-iinsight-mailer.php   # Email composition & dispatch (user ack + admin notification)
│   ├── class-iinsight-ajax.php     # AJAX endpoint (nonce check, rate limiting, input sanitisation)
│   ├── class-iinsight-assets.php   # Enqueues JS + passes PHP vars to front end via wp_localize_script
│   ├── class-iinsight-admin.php    # Admin settings page + log viewer (month selector, clear, download)
│   └── index.php                   # Directory access guard
│
├── assets/
│   ├── index.php
│   └── js/
│       └── iinsight-listener.js    # Front-end: MutationObserver + click fallback, fires AJAX on success
│
├── logs/                           # Auto-created on activation, protected by .htaccess
│   ├── .htaccess                   # Deny from all
│   ├── index.php                   # Directory access guard
│   └── iinsight-YYYY-MM.log        # Monthly rotating log files
│
├── index.php                       # Root directory access guard
└── README.md
```

---

## Security Features

| Feature | Detail |
|---|---|
| Nonce verification | `wp_create_nonce` / `check_ajax_referer` on every AJAX request |
| Input sanitisation | `sanitize_text_field`, `sanitize_email`, `wp_unslash` on all `$_POST` values |
| Rate limiting | Max 5 submissions per IP per hour via WordPress transients |
| Capability checks | All admin actions require `manage_options` |
| Directory protection | `/logs/` has `.htaccess` (Deny from all) + `index.php` guard |
| ABSPATH check | Every PHP file exits immediately if loaded directly |
| `wp_safe_redirect` | Used instead of `header()` for redirects |

---

## Debug Log

- Stored in `iinsight-notifications/logs/iinsight-YYYY-MM.log`
- **Completely separate** from WordPress's `debug.log`
- Monthly rotation — a new file is created each calendar month
- Viewable, clearable, and downloadable from **Settings → iinsight Notify**
- Protected from public access via `.htaccess`
- Logging can be toggled on/off from the settings page

---

## How Notifications Are Triggered

The front-end JS uses two complementary methods to ensure emails are sent
**only after iinsight's own validation passes**:

1. **MutationObserver** (primary): watches `#phase_1` and `#phase_2` style
   attributes. iinsight hides phase_1 and shows phase_2 only on successful
   submission — this is the most reliable trigger.

2. **Click fallback** (secondary): listens for the submit button click, then
   re-checks phase visibility after 800 ms. Only fires if the phase transition
   has already occurred. Acts as a safety net for future iinsight DOM changes.

A `notificationSent` guard prevents double-firing if both methods trigger.
