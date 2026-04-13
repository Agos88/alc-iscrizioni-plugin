# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

WordPress plugin (`alc-plugin`) for managing registrations to the **Agitazioni Letterarie Castelluccesi** literary contest. The plugin handles multi-category form submissions, manuscript uploads, manuscript verification (character/verse counting), PayPal payments, and admin CSV export.

Install by placing the `alc-plugin/` folder inside `/wp-content/plugins/` and activating it from the WordPress dashboard.

## Development

There is no build step — PHP and JS are plain files, no bundler or package manager. To develop:

- **Test locally**: set up a local WordPress environment (e.g. LocalWP or XAMPP), symlink or copy `alc-plugin/` into `wp-content/plugins/`, and activate the plugin.
- **PHP errors**: enable `WP_DEBUG` and `WP_DEBUG_LOG` in `wp-config.php` (`define('WP_DEBUG', true); define('WP_DEBUG_LOG', true);`).
- **JS debugging**: open browser DevTools console — the plugin logs PayPal SDK state via `console.debug`.
- **AJAX endpoints**: all requests go to `admin-ajax.php`; the nonce is `alc_nonce`.

## Architecture

All logic lives in two files:

- **`alc-plugin/includes/class-alc-plugin.php`** — the `ALC_Plugin` class. Single entry point for everything server-side:
  - CPT `alc_iscrizione`: stores each registration as a WordPress post with all fields as post meta.
  - Settings stored in a single `alc_settings` option (currency, per-category fees, file size limit, PayPal credentials, privacy/rules links).
  - AJAX handlers: `alc_verify` (manuscript character/verse count), `alc_submit` (form submission + file upload to CPT-specific directory), `alc_paypal_create_order` / `alc_paypal_capture_order` (PayPal REST API calls), `alc_export_csv` (admin only, gated by `manage_options`).
  - The shortcode `[alc_submission_form]` renders the HTML form inline via `render_form()`.

- **`alc-plugin/assets/js/form.js`** — jQuery frontend:
  - Dynamically shows/hides per-category panels when checkboxes are toggled.
  - Computes the total fee using `ALC_VARS.fees` (localized by PHP).
  - Validates the form before enabling the submit button.
  - Sends AJAX requests for manuscript verification and form submission via `fetch`.
  - Mounts the PayPal JS SDK buttons (`ALC_PAYPAL.enabled`); on `onApprove`, captures the order then auto-submits the form. Includes a retry/MutationObserver loop in case the SDK loads after the DOM is ready.

## Key constants & PHP globals

| Symbol | Value | Purpose |
|---|---|---|
| `ALC_VERSION` | `2.6.0` | Cache-busting for assets |
| `ALC_PLUGIN_DIR` | `plugin_dir_path(__FILE__)` | Absolute path to plugin root |
| `ALC_PLUGIN_URL` | `plugin_dir_url(__FILE__)` | URL to plugin root |
| `ALC_Plugin::CPT` | `alc_iscrizione` | Custom post type slug |
| `ALC_Plugin::OPT` | `alc_settings` | WordPress option key |

## Categories

The four contest categories used as array keys throughout the code:

- `romanzo_inedito`, `romanzo_edito`, `racconto_inedito`, `poesia_inedita`

Only `poesia_inedita` also counts verse lines (all others count characters only).

## PayPal flow

1. User selects categories → JS computes total.
2. PayPal button `createOrder` calls `alc_paypal_create_order` (server computes authoritative total, creates PayPal order via REST API, returns `orderID`).
3. After user approves in PayPal overlay, `onApprove` calls `alc_paypal_capture_order` (server captures the payment).
4. On success, hidden fields (`paypal_order_id`, `paypal_capture_id`, `paypal_payer_email`, `paypal_amount`) are populated and the form is auto-submitted to `alc_submit`.
5. If `paypal_capture_required` is disabled in settings, the submit button is always enabled and PayPal is optional.

## File storage

Uploaded manuscripts are moved out of the default WordPress uploads folder into a plugin-managed directory per submission (`alc_storage_dir($submission_id)`). File paths are stored as post meta on the CPT record.
