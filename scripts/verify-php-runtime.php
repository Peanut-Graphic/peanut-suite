<?php

declare(strict_types=1);

/**
 * Fail closed when Peanut Suite's PHP minimum drifts across public metadata,
 * dependency resolution, documentation, the lockfile, or required CI.
 */

$root = dirname(__DIR__);
$failures = [];

$read = static function (string $relative) use ($root, &$failures): string {
    $path = $root . '/' . $relative;
    $contents = is_file($path) ? file_get_contents($path) : false;

    if ($contents === false) {
        $failures[] = sprintf('%s is missing or unreadable', $relative);

        return '';
    }

    return $contents;
};

$composer = json_decode($read('composer.json'), true);
$lock = json_decode($read('composer.lock'), true);

if (!is_array($composer)) {
    $failures[] = 'composer.json is not valid JSON';
} else {
    if (($composer['require']['php'] ?? null) !== '>=8.1') {
        $failures[] = 'composer.json require.php must be >=8.1';
    }
    if (($composer['config']['platform']['php'] ?? null) !== '8.1.0') {
        $failures[] = 'composer.json config.platform.php must be 8.1.0';
    }
}

if (!is_array($lock)) {
    $failures[] = 'composer.lock is not valid JSON';
} else {
    if (($lock['platform']['php'] ?? null) !== '>=8.1') {
        $failures[] = 'composer.lock platform.php must be >=8.1';
    }
    if (($lock['platform-overrides']['php'] ?? null) !== '8.1.0') {
        $failures[] = 'composer.lock platform-overrides.php must be 8.1.0';
    }
}

if (!preg_match('/^\s*\* Requires PHP:\s*8\.1\s*$/m', $read('peanut-suite.php'))) {
    $failures[] = 'peanut-suite.php must declare PHP 8.1';
}

if (!preg_match('/WordPress 6\.0 or newer and PHP 8\.1 or newer/', $read('README.md'))) {
    $failures[] = 'README.md must document WordPress 6.0+ and PHP 8.1+';
}

$workflow = $read('.github/workflows/tests.yml');
$minimumJob = '';

if (preg_match('/^  php-minimum-tests:\s*$\R(?<body>(?:(?!^  [a-zA-Z0-9_-]+:\s*$).)*)/ms', $workflow, $match)) {
    $minimumJob = $match[0];
} else {
    $failures[] = '.github/workflows/tests.yml is missing required php-minimum-tests job';
}

$requiredWorkflowPatterns = [
    '/php-version:\s*["\']8\.1["\']/' => 'PHP 8.1 setup',
    '/composer install --no-interaction --prefer-dist/' => 'locked dependency installation',
    '/phpunit --testsuite=Property/' => 'property tests',
    '/phpunit --testsuite=Regression/' => 'regression guards',
    '/php scripts\/verify-php-runtime\.php --expect-runtime=8\.1/' => 'runtime identity assertion',
];

foreach ($requiredWorkflowPatterns as $pattern => $description) {
    if (!preg_match($pattern, $minimumJob)) {
        $failures[] = sprintf('php-minimum-tests is missing %s', $description);
    }
}

if (($argv[1] ?? '') === '--expect-runtime=8.1' && PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION !== '8.1') {
    $failures[] = sprintf('expected the PHP 8.1 runtime, got %s', PHP_VERSION);
}

if ($failures !== []) {
    fwrite(STDERR, "PHP runtime declaration contract failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "PHP runtime declaration contract passed (minimum 8.1).\n");
