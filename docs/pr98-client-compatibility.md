# PR #98 client compatibility

Pending release; do not enable hardware locks merely because this client is updated.
The `peanut_license_hardware_id` WordPress filter receives an empty default and the product slug (`peanut-booker`, `peanut-suite`, or `formflow`). Return the exact fingerprint already recorded by the license administrator. Empty defaults retain existing unrestricted behavior. This is a supplied identity, not hardware attestation. Never derive a replacement fingerprint from the domain, IP, path, hostname, or a random UUID; it would not match existing bindings and can change on migration.

IP locks use the HTTP request's observed egress address; clients cannot select it. Domain locks use `home_url()` and need matching apex/subdomain/staging entries. Saved values apply even when the corresponding enforce flag is false. Missing, incorrect or unreadable restrictions continue to fail closed.

Server header support must deploy before these header-using clients. Deploy fingerprint configuration before enforcing a non-empty hardware lock; verify matching and mismatching requests on staging. Do not restore email-based ownership or grandfather existing activations around restrictions.

Download URLs still need separate review: WordPress package downloads cannot inherit headers from an earlier update-check request. Keep existing signed-token packages working; never remove a key from a legacy download URL without implementing scoped download authorization.

## October 16 continuation

Refreshed onto released/active Suite 4.4.0; this candidate is 4.4.1. License Server 1.5.5 header support is released and its watch passed; bundled SDK release 1.5.6 is separately merged and qualifying. Production WooCommerce remains inactive.

The request contract now checks both update-check and plugin-info header credentials and key-free URLs, exact opt-in hardware identity, retained site identity, and explicit restriction denial. The new updater assertion fails against clean main's updater and passes with this candidate. It runs in the existing Regression gate. Full Suite suites, exact packaged runtime/licensing, signed update delivery and fresh CI remain release requirements. No production customer records, bindings, ownership, webhook secrets or commerce state were changed.

PHP 8.4.25 full Property/Regression/Legacy qualification passed: 377 tests / 5,868 assertions. Hosted checks at `ecc1059` all passed. A current full frontend audit additionally found development-only Vitest/Tinypool and source-map-js advisories; these are not treated as cleared by the production-only audit gate. source-map-js 1.2.2 clean install, 45 frontend tests and typecheck pass on the exact pinned Node 20.20.0/npm 10.8.2. The Vitest upgrade currently triggers npm Arborist `edgesOut` resolver crashes; normal peer validation is retained, and the candidate remains held pending remediation and packaged qualification.

Vitest remediation completed with 4.1.12: rebuilt the stale eight-entry Vitest subgraph in an isolated resolver fixture, then applied the resulting 27-entry tooling dependency change. A normal clean install on pinned Node 20.20.0/npm 10.8.2, all 45 frontend tests, typecheck/build and full audit pass with zero advisories. Peer validation and runtime pins were retained; no production application dependency changed. Earlier resolver failures are retained as historical diagnostics, not current blockers. Fresh CI and exact packaged runtime/update qualification remain.
