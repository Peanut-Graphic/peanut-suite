#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
GATE="$ROOT/coverage-gate.php"
WORKFLOW="$ROOT/.github/workflows/tests.yml"

fail() { echo "FAIL: $1" >&2; exit 1; }

[ -f "$GATE" ] || fail "coverage gate is missing"
[ -f "$WORKFLOW" ] || fail "tests workflow is missing"

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

write_clover() {
  local file="$1"
  local statements="$2"
  local covered="$3"
  printf '%s\n' \
    '<?xml version="1.0" encoding="UTF-8"?>' \
    '<coverage><project><metrics statements="'"$statements"'" coveredstatements="'"$covered"'"/></project></coverage>' \
    > "$file"
}

write_clover "$tmp/pass.xml" 100 80
php "$GATE" "$tmp/pass.xml" 80 >/dev/null \
  || fail "coverage gate rejects a threshold-equal report"

if php "$GATE" "$tmp/pass.xml" 80.01 >/dev/null 2>&1; then
  fail "coverage gate accepts a report below threshold"
fi

write_clover "$tmp/empty.xml" 0 0
if php "$GATE" "$tmp/empty.xml" 0.80 >/dev/null 2>&1; then
  fail "coverage gate accepts a zero-statement report"
fi

if php "$GATE" "$tmp/missing.xml" 0.80 >/dev/null 2>&1; then
  fail "coverage gate accepts a missing report"
fi

printf '%s\n' '<not-clover>' > "$tmp/malformed.xml"
if php "$GATE" "$tmp/malformed.xml" 0.80 >/dev/null 2>&1; then
  fail "coverage gate accepts malformed XML"
fi

grep -q 'coverage: pcov' "$WORKFLOW" \
  || fail "workflow does not enable PCOV"
grep -q 'MIN_PHP_COVERAGE: "0.80"' "$WORKFLOW" \
  || fail "workflow does not declare the reviewed coverage floor"
grep -q -- '--testsuite=Property,Regression,Legacy --coverage-clover=coverage/clover.xml' "$WORKFLOW" \
  || fail "workflow does not collect the maintained mock-backed suites together"
grep -q 'php coverage-gate.php coverage/clover.xml "$MIN_PHP_COVERAGE"' "$WORKFLOW" \
  || fail "workflow does not enforce the generated Clover report"
grep -q 'bash scripts/tests/php-coverage-gate.test.sh' "$WORKFLOW" \
  || fail "workflow does not execute this adversarial contract"

echo "PEANUT SUITE PHP COVERAGE GATE CONTRACT PASSED"
