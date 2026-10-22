<?php
declare(strict_types=1);

// Stop with a plain message, and exit 2, instead of a fatal error halfway through.
if (PHP_VERSION_ID < 80100 || !extension_loaded('mbstring')) {
    fwrite(STDERR, 'harness error: needs PHP 8.1 or later with the mbstring extension (found PHP '
        . PHP_VERSION . (extension_loaded('mbstring') ? '' : ', mbstring missing') . ')' . PHP_EOL);
    exit(2);
}

require __DIR__ . '/EvalGate.php';
require __DIR__ . '/Graders.php';

/**
 * Release gate runner. Reads the golden set, the reviewed policy, a recorded
 * run and the last accepted baseline, then prints a decision.
 *
 * Exit codes: 0 release, 1 block, 2 harness or configuration error.
 *
 * Usage:
 *   php gate.php                      # the passing recorded run
 *   php gate.php suite/support-reply.outputs.regressed.json
 */

function loadJson(string $path): array
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('cannot_read:' . basename($path));
    }
    $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('not_an_object:' . basename($path));
    }

    return $data;
}

$dir = getenv('EVAL_SUITE_DIR') ?: __DIR__ . '/suite';

try {
    $suite = loadJson($dir . '/support-reply.golden.json')['cases'] ?? [];
    $outputs = loadJson($argv[1] ?? $dir . '/support-reply.outputs.json')['outputs'] ?? [];
    $baseline = loadJson($dir . '/support-reply.baseline.json');
    // The pinned fingerprint is read from the reviewed policy file, never
    // computed from the suite being judged.
    $policy = loadJson($dir . '/support-reply.policy.json');
    unset($policy['note']);

    $report = EvalGate::decide($suite, $outputs, Graders::registry(), $policy, $baseline);
} catch (Throwable $e) {
    fwrite(STDERR, 'harness error: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}

$list = static fn(array $items): string => $items === [] ? 'none' : implode(', ', $items);

printf('suite fingerprint: %s%s', $report['suite_fingerprint'], PHP_EOL);
if (in_array('suite_fingerprint_mismatch', $report['reasons'], true)) {
    printf('pinned in policy:  %s%s', $policy['suite_fingerprint'], PHP_EOL);
}
printf(
    'aggregate tier: %d of %d passed (min %d)%s',
    $report['aggregate']['passed'],
    $report['aggregate']['cases'],
    $report['aggregate']['min_pass'],
    PHP_EOL
);
foreach ($report['cases'] as $id => $case) {
    printf('  %-22s %-14s %s%s', $id, $case['tier'], $case['status'], PHP_EOL);
}
printf('regressions: %s%s', $list($report['regressions']), PHP_EOL);
printf('known failures: %s%s', $list($report['known_failures']), PHP_EOL);
printf('changed since baseline: %s%s', $list($report['changed_cases']), PHP_EOL);
printf('new cases: %s%s', $list($report['new_cases']), PHP_EOL);
printf('removed cases: %s%s', $list($report['removed_cases']), PHP_EOL);
printf('empty outputs: %s%s', $list($report['empty_outputs']), PHP_EOL);
printf('advisory grader errors: %s%s', $list($report['advisory_errors']), PHP_EOL);
printf('verdict: %s%s', strtoupper($report['verdict']), PHP_EOL);
if ($report['reasons'] !== []) {
    printf('reasons: %s%s', implode(', ', $report['reasons']), PHP_EOL);
}

exit($report['verdict'] === 'release' ? 0 : 1);
