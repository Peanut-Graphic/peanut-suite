#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const RANK = { low: 0, moderate: 1, high: 2, critical: 3 };
const DAY_MS = 24 * 60 * 60 * 1000;

function readJson(file) {
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch (error) {
    throw new Error(`cannot read JSON ${file}: ${error.message}`);
  }
}

function advisoryId(url) {
  const match = String(url || '').match(/GHSA-[a-z0-9-]+/i);
  return match ? match[0].toUpperCase() : null;
}

function manifestException(manifest, id) {
  const escaped = id.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const start = new RegExp(`^  - id: ${escaped}\\s*$`, 'm').exec(manifest);
  if (!start) return null;
  const rest = manifest.slice(start.index + start[0].length);
  const end = rest.search(/^  - id: |^verification:/m);
  const block = end >= 0 ? rest.slice(0, end) : rest;
  const value = (field) => {
    const match = new RegExp(`^    ${field}: ["']?([^\\n"']+)["']?\\s*$`, 'm').exec(block);
    return match ? match[1].trim() : null;
  };
  return { status: value('status'), review_date: value('review_date') };
}

export function verifyAudit(audit, policy, manifest, now = new Date()) {
  if (audit.auditReportVersion !== 2 || typeof audit.vulnerabilities !== 'object') {
    throw new Error('npm audit JSON is missing auditReportVersion 2 vulnerabilities');
  }
  if (policy.schema_version !== 1 || !(policy.severity_floor in RANK)) {
    throw new Error('unsupported dependency audit exception policy');
  }
  if (!Number.isInteger(policy.maximum_review_days) || policy.maximum_review_days < 1) {
    throw new Error('maximum_review_days must be a positive integer');
  }

  const exceptions = new Map();
  for (const exception of policy.exceptions || []) {
    const key = `${exception.package}:${String(exception.advisory).toUpperCase()}`;
    if (exceptions.has(key)) throw new Error(`duplicate dependency exception ${key}`);
    if (!(exception.severity in RANK)) throw new Error(`${exception.id} has invalid severity`);
    const declared = manifestException(manifest, exception.id);
    if (!declared || declared.status !== 'accepted' || declared.review_date !== exception.review_date) {
      throw new Error(`${exception.id} is not accepted with the same review date in .peanut/platform.yml`);
    }
    const review = new Date(`${exception.review_date}T23:59:59Z`);
    const days = Math.ceil((review - now) / DAY_MS);
    if (!Number.isFinite(review.getTime()) || review < now || days > policy.maximum_review_days) {
      throw new Error(`${exception.id} review date is expired or outside the ${policy.maximum_review_days}-day window`);
    }
    exceptions.set(key, { ...exception, used: false });
  }

  const floor = RANK[policy.severity_floor];
  const advisories = [];
  for (const vulnerability of Object.values(audit.vulnerabilities)) {
    for (const via of vulnerability.via || []) {
      if (!via || typeof via !== 'object' || RANK[via.severity] < floor) continue;
      const advisory = advisoryId(via.url);
      if (!advisory) throw new Error(`actionable ${via.name || vulnerability.name} advisory has no GHSA identity`);
      advisories.push({ package: via.name || via.dependency || vulnerability.name, advisory, severity: via.severity });
    }
  }

  const counts = audit.metadata?.vulnerabilities || {};
  const actionableCount = Object.entries(RANK)
    .filter(([, rank]) => rank >= floor)
    .reduce((total, [severity]) => total + Number(counts[severity] || 0), 0);
  if (actionableCount > 0 && advisories.length === 0) {
    throw new Error(`${actionableCount} actionable package nodes have no resolvable advisory identity`);
  }

  for (const advisory of advisories) {
    const key = `${advisory.package}:${advisory.advisory}`;
    const exception = exceptions.get(key);
    if (!exception) throw new Error(`unexcepted ${advisory.severity} advisory ${advisory.advisory} in ${advisory.package}`);
    if (exception.severity !== advisory.severity) {
      throw new Error(`${exception.id} severity changed from ${exception.severity} to ${advisory.severity}`);
    }
    exception.used = true;
  }
  for (const exception of exceptions.values()) {
    if (!exception.used) throw new Error(`${exception.id} no longer matches an actionable advisory; retire or review it`);
  }
  return { actionableCount, advisories: advisories.length, exceptions: [...exceptions.values()].filter((item) => item.used).length };
}

function main(argv) {
  if (argv.length !== 3) {
    console.error('usage: verify-dependency-audit.mjs <npm-audit.json> <exceptions.json> <platform.yml>');
    return 2;
  }
  try {
    const result = verifyAudit(readJson(argv[0]), readJson(argv[1]), fs.readFileSync(argv[2], 'utf8'));
    console.log(`dependency audit accepted: ${result.actionableCount} affected package nodes, ${result.advisories} advisory, ${result.exceptions} current exception`);
    return 0;
  } catch (error) {
    console.error(`dependency audit rejected: ${error.message}`);
    return 1;
  }
}

if (path.resolve(process.argv[1] || '') === fileURLToPath(import.meta.url)) {
  process.exitCode = main(process.argv.slice(2));
}
