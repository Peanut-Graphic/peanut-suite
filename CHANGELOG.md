## Unreleased

### Testing

- Corrected the standalone WordPress mocks and assertions to match real `absint`, `esc_attr`, and `sanitize_email` semantics, while adding an adversarial check that the plugin security service still rejects invalid email syntax.

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

## [4.2.9] - 2026-08-21

### Security
- **Hide-login now actually blocks `wp-login.php`.** The blocker was
  registered on `init@1` from within the plugin's own `init@10` boot — a
  priority that had already run — so `hide_login_init` never executed on any
  web request and every site that enabled hide-login still served the login
  page to anyone. The login-URL rewriting filters did register, which made
  the feature look half-alive. Guarded by a real-WordPress regression test
  that intercepts the block of an unauthorised login request and is proven
  to fail on the unfixed code.

## [4.2.8] - 2026-08-21

### Security
- **Dependency refresh clearing the critical vitest advisory chain**
  (vitest 2.1.9 → 3.2.7, dropping the nested vite 5.4.21 / esbuild 0.21.5
  duplicates; dompurify → 3.4.14) and the high minimatch advisory via
  typescript-eslint ^8. The `functions/` webhook signature check now uses a
  top-level `crypto` import.
- **The dependency audit gates are now blocking** for both composer and npm
  (npm at `--audit-level=moderate`, since npm grades most frontend XSS
  advisories moderate), each verified passing before the flip.

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
