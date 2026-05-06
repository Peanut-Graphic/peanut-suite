# Changelog

All notable changes to Peanut Suite will be documented in this file.

## [4.1.9] - 2026-04-28

### Fixed
- Fatal `Class "ML_Lead_Scoring_Controller" not found` during `rest_api_init` that 500'd every Peanut Suite REST endpoint after upgrading from 4.1.5. The contacts module was instantiating the controller with a bare class name from the global namespace, but the class lives in `PeanutSuite\Contacts`. Now uses the fully-qualified name.

## [4.1.8] - 2026-04-28

### Fixed
- License Settings panel was placeholder UI: status, tier, and expiry were hardcoded ("Pro Tier" / "Expires Dec 31, 2025"), and the "Activate License" button had no `onClick` handler so license keys could never be saved through the UI. The panel now fetches real license data from `/license`, wires the activate flow to `/license/activate`, and adds a Deactivate button when active.
- Frontend `activateLicense` was sending `{ key }` but the REST endpoint expects `{ license_key }`, so even if the button had been wired up the request would have been rejected. Now sends the correct payload.

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
