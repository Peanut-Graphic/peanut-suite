## Unreleased

### Upgrade notes
- **Signed webhooks need a signed timestamp.** A webhook from a source that has a signing secret must now carry a timestamp its signature covers, within 5 minutes of the server clock: either sign `<unix ts>.<raw body>` and send the same `<unix ts>` as `X-Peanut-Timestamp`, or include a `timestamp` field (Unix seconds or ISO 8601) in the signed JSON body. FormFlow Lite and FormFlow Pro already put a signed `timestamp` in every body, so they need no change. A custom sender that signs only the body and has no `timestamp` field is now rejected (401).
- **Per-source webhook URL.** Point senders at `POST /wp-json/peanut/v1/webhooks/receive/{source}`. `POST /webhooks/receive` still works and still reads the source from `X-Webhook-Source` or the body `source` field, but it is deprecated.

### Security
- **Webhook replay protection.** `POST /webhooks/receive`, `POST /formflow/event` and `POST /webhooks/stripe` checked a signature but would accept the same signed delivery again any number of times. `/webhooks/receive` and `/formflow/event` also had no timestamp check, so a captured delivery stayed valid forever. A signed delivery to these endpoints must now carry a signed timestamp no more than 300 seconds old or ahead of the server clock (filter `peanut_webhook_timestamp_tolerance`, clamped to 30 to 3600 seconds). Each delivery is accepted once. `/webhooks/receive` answers a replay with `200 {"duplicate": true}` and does not store or dispatch it, `/formflow/event` answers `409`, and Stripe events are deduped by their signed event id for 24 hours and answered with `200`. Unsigned headers such as `X-FFFL-Timestamp` and `X-ISF-Timestamp` are never trusted. Dedupe claims are `peanut_whr_*` rows in `wp_options`, written with `INSERT IGNORE` so two concurrent copies of a delivery cannot both get through. Expired claims are purged daily, and uninstall removes them all. A claim is released if storing or handling the delivery fails, so the sender's retry still goes through. If a claim cannot be written (database error), the request is refused with a retryable 503 (400 for Stripe) instead of being acknowledged. A Stripe request with no `Stripe-Signature` header now returns 400 instead of a fatal error.
- **Webhook source bound to the route.** The source of an inbound webhook picks its signing secret and its `peanut_webhook_{source}` handlers, and it came from request input: the `X-Webhook-Source` header, which no signature covers, or the body `source` field. `POST /webhooks/receive/{source}` takes the source from the URL only. A request that names a different source in its header or body is refused (400), and a signature made with one source's secret does not verify on another source's route. On the deprecated `POST /webhooks/receive`, a request whose header and body name different sources is refused (400), so an unsigned header can no longer relabel a body-signed payload. The signing status (`GET /webhooks/signing`) now also returns `source_endpoint_url` and `timestamp_tolerance`. The admin Webhooks page shows the per-source URL, and its Send test button posts to `/webhooks/receive/test`. Sources with no secret are still accepted unsigned, on either route.

## 4.3.0

### Upgrade notes
- **FormFlow events need a signature.** `POST /formflow/event` now rejects unsigned requests unless the FormFlow Lite secret (`fffl_peanut_webhook_secret`) is set, or the site opts in to loopback-only unsigned events with the `peanut_formflow_allow_unsigned_loopback` option. Sites that send FormFlow events with no secret configured stop receiving them until one of the two is set.
- **Narrower access for non-admins.** Calendar, ML lead-scoring and segmentation data, and the webhooks admin routes now require `manage_options`. Short-link creation requires `peanut_access`. Peanut accounts are no longer auto-created for non-admins, so users who reached the Suite app only through that auto-creation need a team admin to add them.
- **Rotate exposed keys on open-registration sites.** Before this release any logged-in user could read the Stripe secret key and webhook secret, the GA4 API secret and the Mailchimp and ConvertKit keys through `GET /peanut/v1/settings`. Rotate them on any site where untrusted users can register.

### Security
- **Settings API no longer exposes credentials to non-admins.** `GET /peanut/v1/settings` only required a logged-in user with a REST nonce, so any Subscriber or customer account could read the whole `peanut_settings` option, including the Stripe secret key and webhook secret, the GA4 Measurement Protocol API secret, and the Mailchimp and ConvertKit keys. The read route now requires `manage_options`, the same as writes. Those six secrets are also write-only over REST, even for admins: responses return `""` plus a `<key>_set` flag, and a blank submitted value keeps the stored secret, so a load-then-save cannot wipe a live key. Encrypting them at rest is a follow-up, because each integration still reads them as plaintext.

- Constrain the Firebase Functions `qs` transitive to 6.16.0 or newer so both current parser/denial-of-service advisories remain closed, with a lock-floor regression.
- Reclassify the remaining Functions `uuid` advisory as a coupled Node 22 and dependency-major migration after current upstream packages removed the old `uuid` edge but raised their runtime floor.
- Secrets stored through `Peanut_Encryption` (GA4 Reports OAuth access and refresh tokens, Monitor site keys) are now encrypted with authenticated encryption (XChaCha20-Poly1305, `$PS_ENC$v2:` format, HKDF-SHA256 subkey) instead of unauthenticated AES-256-CBC, so tampered, truncated, or wrong-key values fail closed. Existing CBC values still decrypt; GA4 tokens are rewritten in the new format on their next refresh, and `needs_reencrypt()` reports values that are still legacy.
- The GA4 Reports OAuth client secret (`peanut_settings['ga4_reports_client_secret']`) is now encrypted at rest with the same `$PS_ENC$v2:` format as the tokens it guards, instead of plaintext. `GET`/`POST /peanut/v1/settings` no longer return it (the field comes back blank with a `ga4_reports_client_secret_set` flag), a blank submitted value keeps the stored secret, a legacy plaintext value keeps working and is encrypted by the next settings save and by a one-time upgrade on `plugins_loaded` (flag `peanut_settings_secrets_version`), and a value that fails decryption counts as not configured so no token exchange or refresh request is sent to Google.
- The GA4 Reports integration (`Peanut_Integration_GA4_Reports`) now uses the same OAuth `state` standard as the GA Integration module. Before this, the state was a `wp_create_nonce()` value kept in one site-wide transient (`peanut_ga4_reports_oauth_state`) and compared with `!==`, so a state minted in one user's session was accepted in any other user's session and each new consent link overwrote everyone else's, and `exchange_code()` had no capability check. Each consent link now carries 32 random bytes of state, bound to the current user and stored hashed in a 10-minute transient. `exchange_code()` and the new `process_oauth_callback()` require `manage_options` and a matching (`hash_equals`), unexpired state, and the state is deleted on first use, so a missing, wrong, expired, reused or other-user state is rejected before any request reaches Google. The old site-wide transient is no longer honored. A new `maybe_handle_oauth_callback()` `admin_init` handler redirects back with only a status. No production code constructs this class yet, so the handler is not registered; the integration is not wired into the plugin.
- REST authorization hardening. The base `permission_callback()` is login + REST nonce only, so any Subscriber passed it:
  - The webhooks admin routes (`GET /webhooks`, `/webhooks/{id}`, `/webhooks/stats`, `/webhooks/filters`, `POST /webhooks/{id}/reprocess`) now require `manage_options`. They exposed every stored inbound webhook (payload, sender IP, signature) and could re-fire `peanut_webhook_*` actions.
  - `POST /webhooks/receive` now rejects a request with a missing or invalid signature (401, nothing stored) whenever a secret is configured for its source. Previously omitting the signature header skipped verification. Sources with no configured secret are still accepted unsigned, as before.
  - `POST /formflow/event` now requires a valid `X-FFFL-Signature` HMAC whenever the FormFlow Lite secret (`fffl_peanut_webhook_secret`) is set, and no longer trusts a same-host `Referer`, `REMOTE_ADDR == SERVER_ADDR`, or the `X-FFFL-Source` header. With no secret configured the endpoint rejects requests unless the site opts in with the `peanut_formflow_allow_unsigned_loopback` option, which accepts loopback requests only.
  - The ML lead-scoring and visitor-segmentation data routes (`/contacts/lead-score`, `/contacts/lead-scores`, `/contacts/top-leads`, `/analytics/segments`, `/analytics/visitor-profile/{id}`, `/analytics/segmentation-stats`) now require `manage_options` instead of `read`. The `*/health` routes are unchanged.
  - All `/calendar/*` routes now require `manage_options` instead of `edit_posts`, matching the Content Calendar admin page and its AJAX save handler. Contributors could read, edit and delete every calendar entry and see other authors' draft titles.
  - Peanut accounts are no longer auto-created for users without `manage_options`. `GET /accounts/current` and `/accounts/features` now return 404 for a user nobody has added to a team. `peanut_access`, which opens the Suite app, is granted only to administrators and to users a team admin added to an account anchored to an administrator. Before this, any Subscriber could self-grant it by calling `/accounts/current`.
  - `POST /links`, `POST /links/from-utm` and `PUT /links/{id}` now require `peanut_access`. Before this, any Subscriber could mint site-domain redirects to arbitrary URLs.
- The GA Integration module's Google OAuth client secret and access/refresh tokens (option `peanut_ga_credentials`) were stored in plaintext, and the module's admin page printed the client secret into the password input's `value` attribute, so it appeared in page source. All three are now encrypted at rest (`$PS_ENC$v2:`) and decrypted only where the module calls Google. The settings form renders an empty secret field with a "secret is saved" note, and a blank submit keeps the stored secret. Legacy plaintext keeps working and is encrypted by the next save and by a one-time upgrade on `plugins_loaded` (flag `peanut_ga_credentials_secrets_version`). A value that fails decryption counts as not configured, so no consent flow, token exchange or refresh reaches Google.
- The GA Integration module's Google OAuth connect flow now uses a `state` parameter. Before this, the consent URL carried no `state` and the Google Analytics admin page exchanged any `?action=oauth_callback&code=...` it was loaded with, so an attacker could make a signed-in admin's browser finish the flow with the attacker's authorization code and link the attacker's Google account to the site (login / account-linking CSRF), and a callback URL could be replayed. Each consent link now carries 32 random bytes of state, bound to the current user and stored hashed in a 10-minute transient. The callback runs on `admin_init` instead of while the page renders. It requires `manage_options` and a matching (`hash_equals`), unexpired state, and the state is deleted on first use, so a missing, wrong, expired, reused or other-user state is rejected before any request reaches Google. The admin is then redirected back with only a status, so the code does not stay in the URL or browser history.
- Webhook signing secrets can now be configured. Nothing called `Webhooks_Signature::set_secret()` and there was no UI, so `POST /webhooks/receive` accepted unsigned webhooks on every site that had not hand-edited `peanut_webhook_secrets`. The Webhooks page has a new Signing secrets card, backed by admin-only routes (`manage_options` plus REST nonce): `GET /webhooks/signing` returns per-source status and never a secret, `POST /webhooks/signing` generates a 256-bit secret that is shown once (or stores a secret issued by the sender without echoing it back), and `DELETE /webhooks/signing/{source}` clears it. Secrets are encrypted at rest. Legacy plaintext values still verify and are encrypted by a one-time upgrade (flag `peanut_webhook_secrets_version`). A configured secret that cannot be decrypted now fails closed (401) instead of reading as "no secret". Sources with no secret are still accepted unsigned, and the page now lists which sources are delivering unsigned.
- The webhook receiver now reads the headers FormFlow actually signs with: `X-FFFL-Signature` (FormFlow Lite) and `X-ISF-Signature` (FormFlow Pro). Without this, configuring a FormFlow secret would have rejected every genuine FormFlow webhook.
- Uninstall now deletes `peanut_ga_credentials` and `peanut_webhook_secrets`, along with their upgrade flags.

### Fixed

- Adding a site in the Monitor module no longer fatals after the site row is inserted. `Monitor_Sites` called `Peanut_Encryption::encrypt()` and `decrypt()` statically, which is an `Error` on PHP 8, so no site key was ever stored and requests to child sites went out without their bearer key. A value that fails decryption is now treated as no key.

### Testing

- Pinned every JavaScript package root and CI lane to the existing Firebase-compatible Node 20.20.0/npm 10.8.2 runtime with a fail-closed cross-file verifier; this does not authorize or perform the held Node 22 Firebase transition.
- The maintained PHP property, regression, and WordPress-mock suites now emit one Clover report and enforce an honest 0.80% statement-coverage floor across the existing broad `core/` + `modules/` denominator. The gate fails closed on missing, malformed, empty, or below-floor reports and does not narrow the source scope to make the baseline look larger.
- Corrected the standalone WordPress mocks and assertions to match real `absint`, `esc_attr`, and `sanitize_email` semantics, while adding an adversarial check that the plugin security service still rejects invalid email syntax.
- The 47 legacy WordPress-mock tests are now blocking in both the PHP 8.3 and declared-minimum PHP 8.1 CI lanes; the standalone bootstrap mirrors the API namespace and table-prefix constants that production defines, removing the final two skips.

## 4.2.9

### Security
- **Hide-login now actually blocks `wp-login.php`.** The blocker was
  registered on `init@1` from within the plugin's own `init@10` boot — a
  priority that had already run — so `hide_login_init` never executed on any
  web request and every site that enabled hide-login still served the login
  page to anyone. The login-URL rewriting filters did register, which made
  the feature look half-alive. Guarded by a real-WordPress regression test
  that intercepts the block of an unauthorised login request and is proven
  to fail on the unfixed code.

## 4.2.8

### Security
- **Dependency refresh clearing the critical vitest advisory chain**
  (vitest 2.1.9 → 3.2.7, dropping the nested vite 5.4.21 / esbuild 0.21.5
  duplicates; dompurify → 3.4.14) and the high minimatch advisory via
  typescript-eslint ^8. The `functions/` webhook signature check now uses a
  top-level `crypto` import.
- **The dependency audit gates are now blocking** for both composer and npm
  (npm at `--audit-level=moderate`, since npm grades most frontend XSS
  advisories moderate), each verified passing before the flip.

## 4.2.7

### Fixed
- **Enrol in WordPress auto-updates once, so releases actually land.** Sites were
  not picking up published releases automatically.

## 4.2.6

### Fixed
- **Licence checks survive a license-server outage.** Added a grace period so an
  unreachable server does not immediately de-licence a working site, with
  executable tests for the behaviour.

## 4.2.5

### Fixed
- **Licence check failures are cached with backoff, and the plugin never HTTPs
  itself.** A failing check previously retried hard, and a site could end up
  making requests to its own host.

## 4.2.4

### Security
- **Verify the signed entitlement — client half.** The licence grant is now
  checked against the server's Ed25519 signature, so a tier cached in a
  licensee-writable option can no longer be forged or replayed. Pairs with
  peanut-license-server 1.4.3. (#27, audit C1b)
- **The dev-licence shortcut is gated to non-production.** A `PEANUT-DEV-*` key
  was accepted in production — found as a live incident. (#26, audit W0)

## 4.2.3

### Security
- **Verify the Ed25519 signature of an update package before installing it.**
  `Peanut_Updater` now fetches the `.manifest.json` sidecar, compares the sha256
  with `hash_equals`, and refuses anything unsigned or unverifiable rather than
  installing it. (#25)

## 4.2.2

### Security (microscope remediation)

- **Locked down the Hub export tool.** `cli/export-to-hub.php` now refuses to run over the web (WP-CLI only) and the export directory is protected, closing an unauthenticated full-database dump (contacts, user emails).
- **Popup lead-capture hardening.** An unauthenticated popup submission can no longer overwrite an existing contact's details; convert/view are rate-limited; captured form data is sanitized; the popup management REST routes are admin-gated (they previously referenced an undefined permission method).
- **Short-link redirects validate their target** (block `javascript:`/malformed schemes) while keeping legitimate external links working.
- **Restored + gated the monitor and tracking endpoints** (fixed an undefined permission method and a fatal reference) and added an SSRF guard on monitor fetches.
- **Trusted-proxy client-IP resolution** so the rate limiter can't be bypassed with spoofed forwarded headers.

## 4.2.1

### Fixed
- Migration self-heal now triggers on actual schema drift, not just a stale version option. A production site whose `peanut_db_version` had been recorded ahead of the shipped `DB_VERSION` constant skipped the migration entirely, so `contacts.source_detail` (and `sequences.from_email`/`from_name`) were never added to existing tables and writes silently failed. `maybe_upgrade()` now runs when EITHER the version is stale OR a column the build expects is missing, and adds any drifted columns via idempotent SHOW-COLUMNS-guarded `ALTER TABLE ... ADD COLUMN`. Passing schema checks are cached in a transient so the introspection stays cheap. Mirrors peanut-connect's `check_db_version()` self-heal. `DB_VERSION` bumped to 2.6.0 (above production's 2.5.0) so version-gated installs also trigger.

## 4.2.0

- Schema-drift & reliability fixes (popups source_detail, calendar/sequences columns), self-healing migration-on-upgrade, bounded SEO cron, CI schema-drift guard (#14).

# Changelog

All notable changes to Peanut Suite will be documented in this file.

## [4.1.9] - 2026-05-15

### Fixed
- Fix fatal "Class not found" — fully-qualify namespaced `ML_Lead_Scoring_Controller` / `Visitors_Database` call sites (caused REST + WP-CLI failure). Prod was hotfixed 2026-05-15; this is the durable release.

## [4.1.7] - 2026-03-29

### Security
- Add rate limiting to public tracking and form submission endpoints
- Validate IP addresses before storing in visitor tracking
- Use prepared statements for all database queries
- Validate JSON before storing in custom_fields and metadata columns
- Validate URL length before database insert

### Added
- Audit logging wired to UTM, Links, Contacts, and Popups mutations
- Loading skeleton components for frontend pages
- Global React Query error handling and retry configuration

### Fixed
- PHP version check in diagnostics now correctly requires 8.0+ (was 7.4)
- Reduced aggressive analytics auto-refresh from 30-60s to 5 minutes

## [4.1.6] - Previous release

- See git history for earlier changes.
