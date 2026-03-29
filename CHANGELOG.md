# Changelog

All notable changes to Peanut Suite will be documented in this file.

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
