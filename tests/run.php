#!/usr/bin/env php
<?php

/**
 * Standalone test runner for the jethro-sms package.
 *
 * Usage (from jethro-sms/):
 *   php tests/run.php               # every test
 *   php tests/run.php statusline    # files whose path contains "statusline"
 *
 * The package is meant to be buildable and testable on its own; this runner and
 * tests/support/ are its only test dependencies. A file declaring
 * `@isolated-process` in its header runs in a child process with a clean
 * constant table (tests that define SMS_* constants would otherwise leak).
 *
 * Jethro's own suite still runs these tests too (tests/unit/sms is a symlink to
 * this directory, and tests/unit/run.php picks up this directory's test_*.php).
 */

declare(strict_types=1);

$testsDir = __DIR__;
require_once dirname(__DIR__) . '/vendor/autoload.php';  // jethro/jethro-utils: \Result
require_once dirname(__DIR__) . '/src/load.php';
require_once $testsDir . '/support/entries.php';
require_once $testsDir . '/support/helpers.php';

// ---------------------------------------------------------------------------
// Child mode: one @isolated-process file, fresh process.
// ---------------------------------------------------------------------------
if (($argv[1] ?? null) === '--isolated') {
	$file = $argv[2] ?? '';
	if ($file === '' || !is_file($file)) {
		fwrite(STDERR, "--isolated requires a test file path\n");
		exit(2);
	}
	require_once $file;
	exit(\Test\run_all());
}

$filter = $argv[1] ?? null;

$inProcess = [];
$isolated = [];
$iterator = new \RecursiveIteratorIterator(
	new \RecursiveDirectoryIterator($testsDir, \FilesystemIterator::SKIP_DOTS),
);
foreach ($iterator as $file) {
	if (!$file->isFile() || $file->getExtension() !== 'php') {
		continue;
	}
	$name = $file->getBasename();
	if (!str_starts_with($name, 'test_')) {
		continue;
	}
	$path = $file->getPathname();
	if (str_contains($path, '/support/')) {
		continue;
	}
	if ($filter !== null && !str_contains($path, $filter)) {
		continue;
	}
	$head = (string) file_get_contents($path, false, null, 0, 2048);
	if (str_contains($head, '@isolated-process')) {
		$isolated[] = $path;
	} else {
		$inProcess[] = $path;
	}
}

if ($inProcess === [] && $isolated === []) {
	echo $filter !== null ? "No test files matching '$filter'\n" : "No test files found.\n";
	exit(1);
}

foreach ($inProcess as $path) {
	try {
		require_once $path;
	} catch (\Throwable $e) {
		\Test\load_failure(str_replace($testsDir . '/', '', $path), $e);
		echo 'failed to load ' . $path . ': ' . $e->getMessage() . "\n";
	}
}

$exit = $inProcess !== [] ? \Test\run_all() : 0;

$isoPassed = $isoFailed = 0;
foreach ($isolated as $path) {
	$output = [];
	$status = 0;
	exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --isolated ' . escapeshellarg($path) . ' 2>&1', $output, $status);
	if (preg_match('/(\d+) passed, (\d+) failed, \d+ total/', implode("\n", $output), $m)) {
		$isoPassed += (int) $m[1];
		$isoFailed += (int) $m[2];
	} else {
		echo implode("\n", $output) . "\n";
		$isoFailed++;
	}
	if ($status !== 0) {
		$exit = 1;
	}
}

if ($isolated !== []) {
	$passed = \Test\Registry::$passCount + $isoPassed;
	$failed = count(\Test\Registry::$failures) + count(\Test\Registry::$loadFailures) + $isoFailed;
	echo "\n=== Grand total (including isolated processes) ===\n";
	echo $passed . ' passed, ' . $failed . ' failed, ' . ($passed + $failed) . " total\n";
	if ($isoFailed > 0) {
		$exit = 1;
	}
}

exit($exit);
