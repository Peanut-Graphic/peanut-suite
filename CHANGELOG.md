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
