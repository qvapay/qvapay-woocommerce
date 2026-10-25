# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

WordPress plugin: WooCommerce payment gateway for [QvaPay](https://www.qvapay.com). Single-flow integration (invoice + redirect) using QvaPay's merchants API v2. USD-only.

Plain PHP loaded by WordPress — no `composer.json`, no `package.json`, no unit-test framework. Don't introduce one unless asked. Tooling: `build.sh` (produces the release zip), `.github/workflows/release.yml` (attaches the zip to a GitHub Release when a `v*` tag is pushed), and `tests/e2e/run.sh` — end-to-end webhook scenarios against a throwaway WordPress + WooCommerce in Docker (checkout, WooCommerce's real "Pay for this order" handler, signed webhooks over HTTP; QvaPay's `create_invoice` is stubbed by `tests/e2e/mu-plugins/`). Run it after touching `process_payment()` or the webhook; it exits non-zero on any failed expectation. `tests/` is not shipped (not in `SHIP`, `export-ignore`d).

## Architecture

Entry point `qvapay-for-woocommerce.php` defines plugin constants and, on `plugins_loaded`, requires these classes under `includes/` in this order (the order matters — later classes reference earlier ones):

1. `QvaPay_Logger` — wraps `wc_get_logger()`, source `qvapay`. Non-error messages are dropped unless the gateway's `debug` setting is `yes`; errors always log.
2. `QvaPay_Api_Client` — static HTTP client. POSTs JSON to `QVAPAY_WC_API_BASE` (`https://qvapay.com/api/v2`) with `app-id` / `app-secret` headers. Returns decoded array or `WP_Error`.
3. `QvaPay_Gateway extends WC_Payment_Gateway` — classic-checkout gateway (id `qvapay`). Owns settings UI, `is_available()` guards (enabled + currency=USD + credentials present), and `process_payment()` which calls `create_invoice` and redirects to `QVAPAY_WC_CHECKOUT_BASE` + uuid.
4. `QvaPay_Webhook` — registers `POST /wp-json/qvapay/v1/webhook` (open `permission_callback`; auth is done by HMAC verification inside `handle()`).
5. `QvaPay_Blocks_Integration` — wires the Blocks payment method type into the registry. The actual `AbstractPaymentMethodType` subclass (`QvaPay_Blocks_Method`) lives in its own file and is `require_once`'d **inside** `register()`, **not** at plugins_loaded — see "Blocks checkout" below.
6. `QvaPay_Plugin` (singleton) — wires the gateway into `woocommerce_payment_gateways`, registers REST routes on `rest_api_init`, and hooks `QvaPay_Blocks_Integration::register` to `woocommerce_blocks_payment_method_type_registration` at priority 5.

HPOS (custom order tables) and `cart_checkout_blocks` compatibility are both declared via `FeaturesUtil::declare_compatibility` on `before_woocommerce_init` in the main plugin file — keep order access going through `wc_get_order()` / `$order->get_meta()`, never direct postmeta.

### Blocks checkout

The classic gateway registration alone does **not** make the method appear in the WooCommerce Cart/Checkout block — Blocks ignores `WC_Payment_Gateway` subclasses unless a matching `AbstractPaymentMethodType` is also registered. `QvaPay_Blocks_Integration::register` is hooked **directly** to `woocommerce_blocks_payment_method_type_registration` (priority 5) — going through `woocommerce_blocks_loaded` is unreliable because Blocks may have already fired the registration phase by the time our `woocommerce_blocks_loaded` callback runs.

`QvaPay_Blocks_Method` is in its own file `class-qvapay-blocks-method.php` and is `require_once`'d from inside `register()`, **not** at plugins_loaded. The abstract base class `Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType` may not exist yet when the main bootstrap runs at plugins_loaded — defining the subclass eagerly there silently no-ops and the entire Blocks integration disappears with no PHP error.

The Blocks JS (`assets/js/checkout-blocks.js`) is intentionally minimal: no `onPaymentSetup` callback and no custom `paymentMethodData` — QvaPay doesn't need per-checkout data, just "pick QvaPay → submit → redirect". `canMakePayment` returns `true` unconditionally; gateway visibility is decided server-side by `QvaPay_Blocks_Method::is_active()` which mirrors `QvaPay_Gateway::is_available()`'s checks (enabled + USD + credentials).

Required script deps: `wc-blocks-registry`, `wc-settings`, `wp-element`, `wp-html-entities`, `wp-i18n`. Missing `wc-settings` is what makes `getSetting('qvapay_data', {})` silently return empty. The data key is `<name>_data` derived from `protected $name = 'qvapay'`.

Settings live in the single option `woocommerce_qvapay_settings` (deleted by `uninstall.php`). Per-order state is two meta keys: `_qvapay_invoices` (`QvaPay_Gateway::ORDER_META_INVOICES`), a `uuid => amount` map of every invoice issued for the order, and `_qvapay_transaction_uuid` (`QvaPay_Gateway::ORDER_META_TXN_UUID`), the latest one. WooCommerce's "Pay for this order" calls `process_payment()` again on a pending order, so one order can have several live invoices.

### Webhook URL resolution

Both the admin display field and the `webhook` field in the `create_invoice` payload route through `QvaPay_Gateway::get_webhook_url()`. It returns:

- `rest_url('qvapay/v1/webhook')` when the `webhook_url_override` setting is empty (default).
- `normalize_webhook_url($override)` otherwise — accepts a bare host (`mi-tienda.trycloudflare.com`) or full URL, prefixes `https://` if no scheme, strips trailing slash, and appends `WEBHOOK_PATH` (`/wp-json/qvapay/v1/webhook`) if not already present.

The readonly `webhook_url` field exists only for display. `validate_webhook_url_field()` discards whatever the browser submits and re-derives the value from the override, so the stored `webhook_url` always matches what `get_webhook_url()` would return — there's no way for it to drift out of sync with the override.

The override exists because `rest_url()` resolves against `home_url()`, which is wrong when the admin browses the site through a different host than the public one (typical with Cloudflare Tunnel / ngrok / reverse proxy without proper `X-Forwarded-Host`).

## Webhook contract (critical)

The webhook handler in `includes/class-qvapay-webhook.php` enforces, in order:

- Presence of body + `x-qvapay-signature` + `x-qvapay-timestamp` headers. WP normalizes header names, so `WP_REST_Request::get_header()` is called with underscores (`x_qvapay_signature`, `x_qvapay_timestamp`) — don't change these to dashes.
- `|now - timestamp| ≤ TIMESTAMP_TOLERANCE` (300 s) — anti-replay window.
- Signature must be `sha256=` + `hash_hmac('sha256', raw_body, app_secret)`, compared with `hash_equals` (timing-safe). Signature is computed over the **raw** body — never re-encode the JSON before hashing.
- Serialization: everything that reads or changes the order runs in `apply_to_order()` under a per-order MySQL named lock (`GET_LOCK`, `ORDER_LOCK_TIMEOUT` = 10 s); the order is loaded only after the lock is held. Lock names are server-wide, so `order_lock_name()` builds them from the database name + table prefix (hashed, to stay under 64 chars) + order id — two stores on one MySQL server can share the prefix and order ids. Two invoices of one order (or a redelivery) can arrive together, and without the lock a stale short payment could pass the "already paid?" check and put a just-paid order back `on-hold`. Lock timeout (`GET_LOCK` = 0) → 503 `order_busy` so QvaPay retries. `GET_LOCK` returning NULL or erroring (databases without named locks: some clusters and proxies) → logged and processed unserialized — never turn that into a 503, or no payment would ever complete on those hosts. Keep any new status change inside `apply_to_order()`.
- Order lookup via `remote_id` (= WC order id). The incoming uuid must be one of the invoices issued for the order (`_qvapay_invoices`, plus `_qvapay_transaction_uuid` for orders from before the map existed); anything else is `uuid_mismatch` (400). Never narrow this back to "the latest uuid": the customer may pay an earlier invoice, and QvaPay would retry the rejection forever.
- Idempotency: if the order `is_paid()` or status is `processing` / `completed` / `refunded`, the handler returns 200 with `already_processed=true` and does **not** call `payment_complete()` again. Preserve this — QvaPay retries.
- A `paid` webhook for an already-paid order with a different uuid (both invoices paid) adds an order note so the merchant can refund one — once per uuid (`_qvapay_extra_payments`), since QvaPay can redeliver.
- Only `status === 'paid'` triggers `$order->payment_complete($uuid)`, and only if the paid amount covers the current order total. The amount is the one stored in `_qvapay_invoices` when the invoice was issued; for a pre-map (v1.0.0) invoice, whose stored amount is `null`, it falls back to the signed webhook's `amount`. Short → `on-hold` + note, 200 `amount_short`; no amount at all → `on-hold` + note, 200 `amount_unverified`. Never let an unverifiable amount complete an order. Other statuses are logged and acknowledged with 200.

The qpweb backend signs the webhook via `sendAppWebhook(url, payload, { app_id, secret })` — the `secret` arg is what triggers `x-qvapay-signature` + `x-qvapay-timestamp` headers in `scripts/webhook.js`. Callers must pass `secret: transaction.App.secret`. Adding the headers is backward-compatible: existing merchants that don't validate them simply ignore the extra headers.

Rejections return non-2xx via `self::reject()` so QvaPay will retry. Webhooks for unknown orders return 200 (no point retrying) but log an error.

## Constraints to preserve

- **USD only.** `is_available()` and the admin notice both check `get_woocommerce_currency() === 'USD'`. The QvaPay API only accepts USD.
- **HTTPS required** for webhook delivery — there's an admin notice but no hard block.
- **Amount range:** `0 < total ≤ 100000` enforced in `process_payment()`.
- **No refunds, no subscriptions, no sandbox** in v1 — don't add `supports` flags (`refunds`, `subscriptions`) without an underlying implementation; QvaPay has no refund endpoint.
- Product names sent to QvaPay are stripped of `< > ' " ( ) ;` and truncated to 150 chars; description to 255. Keep this sanitization when changing payload shape.

## Text domain & i18n

Text domain `qvapay-for-woocommerce`, loaded from `/languages` (directory exists, empty). All user-facing strings go through `__()` / `esc_html__()` — match the existing Spanish source strings rather than introducing English ones.

## Layout

```
qvapay-for-woocommerce.php      Entry point. Constants, bootstrap, HPOS declaration.
uninstall.php                   Removes woocommerce_qvapay_settings on uninstall.
includes/                       All PHP classes (flat — WP convention, do not nest).
assets/images/icon.svg          Checkout icon. Path is referenced from class-qvapay-gateway.php.
languages/                      .mo/.po catalogs (text-domain: qvapay-for-woocommerce).
readme.txt                      WordPress.org-style manifest.
build.sh                        Release packager (SHIP array is the source of truth).
.gitattributes                  export-ignore list — keep aligned with build.sh's SHIP.
.github/workflows/release.yml   Tag-triggered release: validates header version, runs build.sh.
.wordpress-org/                 WP.org banners/icons. Not shipped in the zip — SVN-only.
dist/                           Build output. Gitignored.
```

## Release

- **Package:** `bash build.sh` → `dist/qvapay-for-woocommerce-<version>.zip` with `qvapay-for-woocommerce/` as the top-level dir. The `SHIP` array at the top of the script is the authoritative include list — anything missing from it does **not** ship. When adding new runtime files/dirs, update `SHIP` *and* `.gitattributes` so `git archive` stays consistent with the zip.
- **GitHub Release:** push a tag `vX.Y.Z` that matches the plugin `Version:` header; the workflow fails fast on mismatch.
- **Three places to bump on a version change** (must move together): `Version:` header in `qvapay-for-woocommerce.php`, `QVAPAY_WC_VERSION` constant in the same file, and `Stable tag:` in `readme.txt`.
- **`.wordpress-org/`** holds the WP.org plugin directory assets (banner-1544x500.png, banner-772x250.png, icon-256x256.png, icon-128x128.png). These do **not** ship with the plugin — they go into the SVN repo's separate `/assets/` path. The README inside that folder documents expected dimensions.
