'use strict';

const { execFileSync } = require('node:child_process');
const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');

const repositoryRoot = resolve(__dirname, '..');
const expected = Object.freeze({ node: '20.20.0', npm: '10.8.2' });
const declarationsOnly = process.argv.includes('--declarations-only');

function read(path) {
  return readFileSync(resolve(repositoryRoot, path), 'utf8');
}

function readJson(path) {
  return JSON.parse(read(path));
}

function assertEqual(actual, wanted, label) {
  if (actual !== wanted) {
    throw new Error(`${label}: expected ${wanted}, received ${actual}`);
  }
}

for (const [packagePath, lockPath, expectedNode] of [
  ['package.json', 'package-lock.json', expected.node],
  ['frontend/package.json', 'frontend/package-lock.json', expected.node],
  ['functions/package.json', 'functions/package-lock.json', '20'],
]) {
  const packageJson = readJson(packagePath);
  const lockedRoot = readJson(lockPath).packages?.[''];
  assertEqual(lockedRoot?.version, packageJson.version, `${lockPath} version`);
  assertEqual(packageJson.engines?.node, expectedNode, `${packagePath} engines.node`);
  assertEqual(packageJson.engines?.npm, expected.npm, `${packagePath} engines.npm`);
  assertEqual(packageJson.packageManager, `npm@${expected.npm}`, `${packagePath} packageManager`);
  assertEqual(lockedRoot?.engines?.node, expectedNode, `${lockPath} engines.node`);
  assertEqual(lockedRoot?.engines?.npm, expected.npm, `${lockPath} engines.npm`);
}

assertEqual(read('.nvmrc').trim(), expected.node, '.nvmrc');

const workflowExpectations = new Map([
  ['.github/workflows/accessibility.yml', 1],
  ['.github/workflows/tests.yml', 2],
]);
let runtimeAssertions = 0;
for (const [path, expectedPins] of workflowExpectations) {
  const workflow = read(path);
  const pins = [...workflow.matchAll(/node-version:\s*['"]?([^'"\s]+)/g)].map((match) => match[1]);
  assertEqual(pins.length, expectedPins, `${path} Node declaration count`);
  pins.forEach((pin, index) => assertEqual(pin, expected.node, `${path} node-version ${index + 1}`));
  runtimeAssertions += workflow.match(/npm --version/g)?.length ?? 0;
}
assertEqual(runtimeAssertions, 3, 'workflow npm assertion count');

if (!declarationsOnly) {
  assertEqual(process.versions.node, expected.node, 'active Node runtime');
  const npmVersion = execFileSync('npm', ['--version'], { encoding: 'utf8' }).trim();
  assertEqual(npmVersion, expected.npm, 'active npm runtime');
}

console.log(
  declarationsOnly
    ? `Runtime declarations are pinned to Node ${expected.node} and npm ${expected.npm}.`
    : `Runtime contract verified on Node ${expected.node} and npm ${expected.npm}.`,
);
