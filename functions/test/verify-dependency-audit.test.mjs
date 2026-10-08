import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

import { verifyAudit } from '../scripts/verify-dependency-audit.mjs';

const NOW = new Date('2026-08-22T12:00:00Z');
const manifest = `exceptions:
  - id: ADV-001
    concern: One upstream advisory
    review_date: "2026-09-20"
    status: accepted
verification:
  status: source-audited
`;
const policy = {
  schema_version: 1,
  severity_floor: 'moderate',
  maximum_review_days: 30,
  exceptions: [{
    id: 'ADV-001', package: 'uuid', advisory: 'GHSA-w5hq-g745-h8pq', severity: 'moderate', review_date: '2026-09-20',
  }],
};
const audit = {
  auditReportVersion: 2,
  vulnerabilities: {
    uuid: { name: 'uuid', severity: 'moderate', via: [{ name: 'uuid', severity: 'moderate', url: 'https://github.com/advisories/GHSA-w5hq-g745-h8pq' }] },
    firebaseAdmin: { name: 'firebase-admin', severity: 'moderate', via: ['uuid'] },
  },
  metadata: { vulnerabilities: { low: 0, moderate: 2, high: 0, critical: 0 } },
};

test('accepts only the exact current manifest-backed advisory', () => {
  assert.deepEqual(verifyAudit(audit, policy, manifest, NOW), { actionableCount: 2, advisories: 1, exceptions: 1 });
});

test('rejects a new advisory even when its package is transitive', () => {
  const changed = structuredClone(audit);
  changed.vulnerabilities.other = { name: 'other', severity: 'high', via: [{ name: 'other', severity: 'high', url: 'https://github.com/advisories/GHSA-aaaa-bbbb-cccc' }] };
  changed.metadata.vulnerabilities.high = 1;
  assert.throws(() => verifyAudit(changed, policy, manifest, NOW), /unexcepted high advisory/);
});

test('rejects an expired or overlong review window', () => {
  assert.throws(() => verifyAudit(audit, policy, manifest, new Date('2026-09-21T00:00:00Z')), /expired or outside/);
});

test('rejects a manifest exception that is not accepted', () => {
  assert.throws(() => verifyAudit(audit, policy, manifest.replace('status: accepted', 'status: proposed'), NOW), /not accepted/);
});

test('rejects a stale exception after the advisory clears', () => {
  const clean = { auditReportVersion: 2, vulnerabilities: {}, metadata: { vulnerabilities: { low: 0, moderate: 0, high: 0, critical: 0 } } };
  assert.throws(() => verifyAudit(clean, policy, manifest, NOW), /no longer matches/);
});

test('keeps qs above both audited security floors', () => {
  const packageJson = JSON.parse(fs.readFileSync(new URL('../package.json', import.meta.url)));
  const packageLock = JSON.parse(fs.readFileSync(new URL('../package-lock.json', import.meta.url)));

  assert.equal(packageJson.overrides.qs, '^6.16.0');
  assert.ok(
    packageLock.packages['node_modules/qs'].version.localeCompare('6.16.0', undefined, { numeric: true }) >= 0,
  );
});

test('keeps @grpc/grpc-js at or above the GHSA-m9gg-hp2v-232j fix', () => {
  const packageLock = JSON.parse(fs.readFileSync(new URL('../package-lock.json', import.meta.url)));

  // 1.14.4 leaked handler error messages to clients and failed the production
  // audit floor on main; 1.14.5 is the first fixed release.
  assert.ok(
    packageLock.packages['node_modules/@grpc/grpc-js'].version.localeCompare('1.14.5', undefined, { numeric: true }) >= 0,
  );
});

test('keeps @fastify/busboy at or above the GHSA-gxm5-99cw-xjw9 fix', () => {
  const packageLock = JSON.parse(fs.readFileSync(new URL('../package-lock.json', import.meta.url)));

  // 3.2.1 allowed CRLF injection through multipart Content-Disposition
  // filename and name, and failed the production audit floor on main;
  // 3.2.2 is the first fixed release.
  assert.ok(
    packageLock.packages['node_modules/@fastify/busboy'].version.localeCompare('3.2.2', undefined, { numeric: true }) >= 0,
  );
});

test('keeps proxy-addr at or above the GHSA-jqcg-44mw-7w3h fix', () => {
  const packageLock = JSON.parse(fs.readFileSync(new URL('../package-lock.json', import.meta.url)));

  // 2.0.7 let an IPv4-mapped IPv6 address spoof a trusted subnet (critical)
  // and failed the production audit floor on main; 2.0.8 is the first fixed
  // release. Every proxy-addr in the tree must be fixed, not just the hoisted one.
  for (const [path, entry] of Object.entries(packageLock.packages)) {
    if (path.endsWith('node_modules/proxy-addr')) {
      assert.ok(entry.version.localeCompare('2.0.8', undefined, { numeric: true }) >= 0, path);
    }
  }
});
