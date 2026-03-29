# Peanut Suite Super Polish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix security issues, harden public endpoints, wire up half-built infrastructure (audit logging, API keys, permissions), and improve frontend error handling in Peanut Suite v4.1.6.

**Architecture:** PHP fixes in core services and module controllers, React frontend improvements in the SPA. No new tables — all infrastructure already exists, just needs wiring.

**Tech Stack:** WordPress PHP 8.0+, React 18, TypeScript, Tailwind CSS, Zustand, React Query

---

### Task 1: Add Rate Limiting to Public Tracking Endpoints

**Files:**
- Modify: `modules/visitors/api/class-visitors-controller.php`
- Modify: `modules/forms/class-forms-module.php`

The `Peanut_Security::check_rate_limit()` method exists but is never called on the public `/track`, `/track/identify`, and form submission endpoints. Add rate limiting to prevent abuse.

- [ ] **Step 1: Add rate limiting to track_event**

In `modules/visitors/api/class-visitors-controller.php`, find the `track_event` method. At the very top of the method body, add:

```php
        if (!Peanut_Security::check_rate_limit('track_event', 120, 60)) {
            return new \WP_REST_Response(['message' => 'Rate limit exceeded'], 429);
        }
```

This allows 120 tracking events per minute per IP.

- [ ] **Step 2: Add rate limiting to identify_visitor**

In the same file, find the `identify_visitor` method. At the top, add:

```php
        if (!Peanut_Security::check_rate_limit('identify_visitor', 30, 60)) {
            return new \WP_REST_Response(['message' => 'Rate limit exceeded'], 429);
        }
```

30 identify calls per minute per IP.

- [ ] **Step 3: Add rate limiting to form submissions**

In `modules/forms/class-forms-module.php`, find the public form submission callback (the method registered for the POST endpoint with `permission_callback => '__return_true'`). Add at the top:

```php
        if (!Peanut_Security::check_rate_limit('form_submit', 10, 60)) {
            return new \WP_REST_Response(['message' => 'Rate limit exceeded'], 429);
        }
```

10 form submissions per minute per IP.

- [ ] **Step 4: Commit**

```bash
cd /Users/nattyb/Documents/Peanut/PEANUT-SUITE
git add modules/visitors/api/class-visitors-controller.php modules/forms/class-forms-module.php
git commit -m "security: add rate limiting to public tracking and form endpoints"
```

---

### Task 2: Add IP Validation to Visitor Tracking

**Files:**
- Modify: `modules/visitors/api/class-visitors-controller.php`
- Modify: `modules/visitors/class-visitors-database.php`

- [ ] **Step 1: Validate IP before storing in track_event**

In `class-visitors-controller.php`, find where the IP address is captured (look for `$_SERVER['REMOTE_ADDR']` or a helper). Before storing, add validation:

```php
        $ip = Peanut_Security::get_client_ip();
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = '0.0.0.0';
        }
```

If `get_client_ip()` doesn't exist on the Security class, the IP is likely captured with `$_SERVER['REMOTE_ADDR']`. Wrap it either way.

- [ ] **Step 2: Add IP validation in visitors database insert**

In `class-visitors-database.php`, find the method that inserts visitor records. Before the `$wpdb->insert()` call, validate:

```php
        if (isset($data['ip_address']) && !filter_var($data['ip_address'], FILTER_VALIDATE_IP)) {
            $data['ip_address'] = '0.0.0.0';
        }
```

- [ ] **Step 3: Commit**

```bash
git add modules/visitors/api/class-visitors-controller.php modules/visitors/class-visitors-database.php
git commit -m "security: validate IP addresses before storing in visitor tracking"
```

---

### Task 3: Fix Unprepared SQL Queries

**Files:**
- Modify: `modules/monitor/class-monitor-database.php`
- Modify: `modules/security/class-security-module.php`
- Modify: `core/services/class-peanut-updater.php`

These queries use only table names (no user input), but WordPress coding standards require `$wpdb->prepare()` with `%i` for table names.

- [ ] **Step 1: Fix monitor cleanup queries**

In `modules/monitor/class-monitor-database.php`, replace `cleanup_old_records()` (lines 192-210):

```php
    public static function cleanup_old_records(): void {
        global $wpdb;

        $health_log_table = self::health_log_table();
        $wpdb->query($wpdb->prepare(
            "DELETE FROM %i WHERE checked_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
            $health_log_table
        ));

        $uptime_table = self::uptime_table();
        $wpdb->query($wpdb->prepare(
            "DELETE FROM %i WHERE checked_at < DATE_SUB(NOW(), INTERVAL 90 DAY)",
            $uptime_table
        ));

        $analytics_table = self::analytics_table();
        $wpdb->query($wpdb->prepare(
            "DELETE FROM %i WHERE period_start < DATE_SUB(NOW(), INTERVAL 12 MONTH)",
            $analytics_table
        ));

        $webvitals_table = self::webvitals_table();
        $wpdb->query($wpdb->prepare(
            "DELETE FROM %i WHERE checked_at < DATE_SUB(NOW(), INTERVAL 90 DAY)",
            $webvitals_table
        ));
    }
```

Also fix `drop_tables()` (line 185):

```php
        foreach ($tables as $table) {
            $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %i", $table));
        }
```

- [ ] **Step 2: Fix security module cleanup queries**

In `modules/security/class-security-module.php`, replace `cleanup_old_data()` (lines 798-812):

```php
    public function cleanup_old_data(): void {
        global $wpdb;

        $attempts_table = $wpdb->prefix . 'peanut_login_attempts';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM %i WHERE attempt_time < DATE_SUB(NOW(), INTERVAL 30 DAY)",
            $attempts_table
        ));

        $lockouts_table = $wpdb->prefix . 'peanut_lockouts';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM %i WHERE lockout_until < NOW()",
            $lockouts_table
        ));
    }
```

- [ ] **Step 3: Fix updater cache clear queries**

In `core/services/class-peanut-updater.php`, replace `clear_update_cache()` lines 282-287:

```php
        $wpdb->query($wpdb->prepare(
            "DELETE FROM %i WHERE option_name LIKE %s",
            $wpdb->options,
            '_transient_peanut_update_check_%'
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM %i WHERE option_name LIKE %s",
            $wpdb->options,
            '_transient_timeout_peanut_update_check_%'
        ));
```

- [ ] **Step 4: Commit**

```bash
git add modules/monitor/class-monitor-database.php modules/security/class-security-module.php core/services/class-peanut-updater.php
git commit -m "security: use prepared statements for all database queries"
```

---

### Task 4: Add JSON Validation for Custom Fields

**Files:**
- Modify: `modules/contacts/api/class-contacts-controller.php`
- Modify: `modules/visitors/api/class-visitors-controller.php`

- [ ] **Step 1: Validate custom_fields JSON in contacts controller**

In `class-contacts-controller.php`, find the create/update methods. Before saving `custom_fields`, validate:

```php
        if (isset($data['custom_fields'])) {
            if (is_string($data['custom_fields'])) {
                $decoded = json_decode($data['custom_fields'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return $this->error('Invalid JSON in custom_fields', 400);
                }
                $data['custom_fields'] = wp_json_encode($decoded);
            } elseif (is_array($data['custom_fields'])) {
                $data['custom_fields'] = wp_json_encode($data['custom_fields']);
            }
        }
```

- [ ] **Step 2: Validate metadata JSON in visitor activities**

Apply the same pattern to any `metadata` field in `class-visitors-controller.php` or `class-visitors-database.php`:

```php
        if (isset($data['metadata'])) {
            if (is_string($data['metadata'])) {
                $decoded = json_decode($data['metadata'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $data['metadata'] = null;
                } else {
                    $data['metadata'] = wp_json_encode($decoded);
                }
            } elseif (is_array($data['metadata'])) {
                $data['metadata'] = wp_json_encode($data['metadata']);
            }
        }
```

- [ ] **Step 3: Commit**

```bash
git add modules/contacts/api/class-contacts-controller.php modules/visitors/api/class-visitors-controller.php
git commit -m "security: validate JSON before storing in custom_fields and metadata"
```

---

### Task 5: Wire Up Audit Logging

**Files:**
- Modify: `modules/utm/api/class-utm-controller.php`
- Modify: `modules/links/api/class-links-controller.php`
- Modify: `modules/contacts/api/class-contacts-controller.php`
- Modify: `modules/popups/api/class-popups-controller.php`

The `Peanut_Audit_Log_Service` exists with a `log()` method. Wire it into all data-modifying endpoints.

- [ ] **Step 1: Add audit logging to UTM mutations**

In `class-utm-controller.php`, find the create, update, and delete methods. After each successful operation, add:

```php
        Peanut_Audit_Log_Service::log('utm_created', [
            'utm_id' => $id,
            'name' => $data['name'] ?? '',
        ]);
```

For update:
```php
        Peanut_Audit_Log_Service::log('utm_updated', ['utm_id' => $id]);
```

For delete:
```php
        Peanut_Audit_Log_Service::log('utm_deleted', ['utm_id' => $id]);
```

- [ ] **Step 2: Add audit logging to Links mutations**

Same pattern in `class-links-controller.php`:

```php
        Peanut_Audit_Log_Service::log('link_created', ['link_id' => $id, 'url' => $data['url'] ?? '']);
        Peanut_Audit_Log_Service::log('link_updated', ['link_id' => $id]);
        Peanut_Audit_Log_Service::log('link_deleted', ['link_id' => $id]);
```

- [ ] **Step 3: Add audit logging to Contacts mutations**

Same pattern in `class-contacts-controller.php`:

```php
        Peanut_Audit_Log_Service::log('contact_created', ['contact_id' => $id, 'email' => $data['email'] ?? '']);
        Peanut_Audit_Log_Service::log('contact_updated', ['contact_id' => $id]);
        Peanut_Audit_Log_Service::log('contact_deleted', ['contact_id' => $id]);
```

- [ ] **Step 4: Add audit logging to Popups mutations**

Same pattern in `class-popups-controller.php`:

```php
        Peanut_Audit_Log_Service::log('popup_created', ['popup_id' => $id]);
        Peanut_Audit_Log_Service::log('popup_updated', ['popup_id' => $id]);
        Peanut_Audit_Log_Service::log('popup_deleted', ['popup_id' => $id]);
```

- [ ] **Step 5: Commit**

```bash
git add modules/utm/api/class-utm-controller.php modules/links/api/class-links-controller.php modules/contacts/api/class-contacts-controller.php modules/popups/api/class-popups-controller.php
git commit -m "feat: wire up audit logging to all data-modifying endpoints"
```

---

### Task 6: Add URL Length Validation

**Files:**
- Modify: `modules/utm/api/class-utm-controller.php`
- Modify: `modules/links/api/class-links-controller.php`

- [ ] **Step 1: Validate URL length in UTM creation**

In `class-utm-controller.php`, find where the full URL is constructed or validated. Before saving, add:

```php
        $full_url = $data['full_url'] ?? '';
        if (strlen($full_url) > 2048) {
            return $this->error('URL exceeds maximum length of 2048 characters', 400);
        }
```

- [ ] **Step 2: Validate URL length in Links creation**

In `class-links-controller.php`, same pattern:

```php
        $url = $data['url'] ?? $data['destination_url'] ?? '';
        if (strlen($url) > 2048) {
            return $this->error('URL exceeds maximum length of 2048 characters', 400);
        }
```

- [ ] **Step 3: Commit**

```bash
git add modules/utm/api/class-utm-controller.php modules/links/api/class-links-controller.php
git commit -m "security: validate URL length before database insert"
```

---

### Task 7: Fix PHP Version Check Inconsistency

**Files:**
- Modify: `peanut-suite.php`

- [ ] **Step 1: Fix the diagnostics function version check**

In `peanut-suite.php`, find `peanut_run_diagnostics()` (around line 226). Find the PHP version check that says `7.4` and change it to match the plugin header requirement of `8.0`:

```php
        // PHP Version
        $checks['php_version'] = [
            'label' => 'PHP Version',
            'value' => PHP_VERSION,
            'status' => version_compare(PHP_VERSION, '8.0', '>=') ? 'good' : 'error',
            'message' => version_compare(PHP_VERSION, '8.0', '>=')
                ? 'PHP ' . PHP_VERSION . ' meets minimum requirement'
                : 'PHP 8.0+ required, current: ' . PHP_VERSION,
        ];
```

- [ ] **Step 2: Commit**

```bash
git add peanut-suite.php
git commit -m "fix: align diagnostics PHP version check with plugin requirements (8.0+)"
```

---

### Task 8: Frontend — Add Loading States and Error Handling

**Files:**
- Modify: `frontend/src/App.tsx`
- Modify: `frontend/src/pages/Dashboard.tsx` (as the template for other pages)

- [ ] **Step 1: Add global React Query error defaults**

Find where React Query's `QueryClient` is configured (likely in `App.tsx` or an index file). Add default error handling and retry config:

```typescript
const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            retry: 2,
            retryDelay: (attemptIndex) => Math.min(1000 * 2 ** attemptIndex, 10000),
            staleTime: 5 * 60 * 1000, // 5 minutes
            refetchInterval: false, // Disable aggressive refetch by default
        },
        mutations: {
            retry: 1,
            onError: (error: any) => {
                const message = error?.response?.data?.message || error?.message || 'An error occurred';
                console.error('[Peanut Suite]', message);
            },
        },
    },
});
```

- [ ] **Step 2: Reduce aggressive refetch intervals**

Search for `refetchInterval: 30000` and `refetchInterval: 60000` across all page files. Change to `refetchInterval: 300000` (5 minutes) or remove entirely:

```typescript
// Before
refetchInterval: 30000,

// After
refetchInterval: 5 * 60 * 1000, // 5 minutes
```

- [ ] **Step 3: Add a loading skeleton component if not present**

Check if a loading skeleton exists in `frontend/src/components/common/`. If not, create `frontend/src/components/common/Skeleton.tsx`:

```tsx
export function Skeleton({ className = '' }: { className?: string }) {
    return (
        <div className={`animate-pulse bg-gray-200 dark:bg-gray-700 rounded ${className}`} />
    );
}

export function SkeletonTable({ rows = 5 }: { rows?: number }) {
    return (
        <div className="space-y-3">
            <Skeleton className="h-8 w-full" />
            {Array.from({ length: rows }).map((_, i) => (
                <Skeleton key={i} className="h-12 w-full" />
            ))}
        </div>
    );
}
```

- [ ] **Step 4: Use loading skeleton in Dashboard**

In `Dashboard.tsx`, find where data is loading. Replace any empty/flash state with:

```tsx
if (isLoading) {
    return <SkeletonTable rows={5} />;
}
```

- [ ] **Step 5: Commit**

```bash
cd /Users/nattyb/Documents/Peanut/PEANUT-SUITE
git add frontend/src/
git commit -m "feat: add loading skeletons, fix aggressive refetch intervals, improve error handling"
```

---

### Task 9: Add CHANGELOG.md

**Files:**
- Create: `CHANGELOG.md`

- [ ] **Step 1: Create CHANGELOG.md**

```markdown
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
```

- [ ] **Step 2: Bump version to 4.1.7**

In `peanut-suite.php`, update the version in the plugin header (line 6) and the constant (line 29-30):

```php
 * Version:           4.1.7
```

```php
define('PEANUT_VERSION', '4.1.7');
```

- [ ] **Step 3: Commit**

```bash
git add CHANGELOG.md peanut-suite.php
git commit -m "chore: add CHANGELOG.md, bump version to 4.1.7"
```

---

### Task 10: Build Frontend and Deploy

- [ ] **Step 1: Build frontend assets**

```bash
cd /Users/nattyb/Documents/Peanut/PEANUT-SUITE/frontend && npm run build
```

- [ ] **Step 2: Push to GitHub**

```bash
cd /Users/nattyb/Documents/Peanut/PEANUT-SUITE
git push origin main
```

- [ ] **Step 3: Create release**

```bash
cd /Users/nattyb/Documents/Peanut/PEANUT-SUITE
find . -type d -exec chmod 755 {} \; && find . -type f -exec chmod 644 {} \;
zip -r /tmp/peanut-suite-4.1.7.zip . -x "*.DS_Store" -x "*.git*" -x "node_modules/*" -x "frontend/node_modules/*"
gh release create v4.1.7 --title "Peanut Suite v4.1.7" /tmp/peanut-suite-4.1.7.zip
```

- [ ] **Step 4: Update version on license server**

```bash
ssh peanutgraphic "cd public_html && wp option update peanut_peanut-suite_version 4.1.7"
```
