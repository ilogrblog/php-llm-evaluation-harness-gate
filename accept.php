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
 * Writes a candidate baseline for a reviewed run. It never touches the
 * accepted baseline: a person reviews the candidate's diff, then replaces the
 * baseline with it and commits, so every accepted change to a case, a grader
 * version or a known failure has a name and a review attached to it.
 *
 * Any earlier candidate, including a symlink, is removed before anything else
 * happens, even before the arguments are checked. The new candidate is written
 * to a temporary file and renamed into place only after acceptance succeeds.
 * On any refusal, including wrong arguments, an unpinned suite or a case that
 * was never measured, no candidate is left behind and the accepted baseline is
 * unchanged. If filesystem permissions prevent that cleanup, the script says
 * so: the remaining file is stale and must not be used.
 *
 * Exit codes: 0 candidate written, 2 refused or harness error. Continue to
 * review and replacement only after exit 0.
 *
 * Usage:
 *   php accept.php suite/support-reply.outputs.json run-a
 *   diff -u suite/support-reply.baseline.json suite/support-reply.baseline.candidate.json
 *   mv suite/support-reply.baseline.candidate.json suite/support-reply.baseline.json
 */

$dir = getenv('EVAL_SUITE_DIR') ?: __DIR__ . '/suite';
$candidate = $dir . '/support-reply.baseline.candidate.json';

// A candidate left from an earlier run must not be mistaken for this one, so it
// goes first, before any other check can exit. is_link() also catches a
// dangling symlink, which is_file() reports as absent.
if ((is_file($candidate) || is_link($candidate)) && !@unlink($candidate)) {
    fwrite(STDERR, 'harness error: cannot_remove_stale_candidate: '
        . basename($candidate) . ' is stale and must not be used' . PHP_EOL);
    exit(2);
}

if ($argc !== 3) {
    fwrite(STDERR, 'usage: php accept.php OUTPUTS.json RUN_ID' . PHP_EOL);
    exit(2);
}

try {
    $read = static fn(string $path): array => json_decode(
        (string) @file_get_contents($path), true, 32, JSON_THROW_ON_ERROR
    );
    $suite = $read($dir . '/support-reply.golden.json')['cases'] ?? [];
    $outputs = $read($argv[1])['outputs'] ?? [];
    $policy = $read($dir . '/support-reply.policy.json');
    unset($policy['note']);

    $report = EvalGate::decide($suite, $outputs, Graders::registry(), $policy);
    $baseline = EvalGate::acceptBaseline($report, $argv[2]);
    $json = json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

    $tmp = $candidate . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (@file_put_contents($tmp, $json) !== strlen($json) || !@rename($tmp, $candidate)) {
        @unlink($tmp);
        throw new RuntimeException('cannot_write_candidate');
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'harness error: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}

fwrite(STDOUT, 'candidate written: ' . basename($candidate) . PHP_EOL
    . 'review the diff against ' . basename($dir . '/support-reply.baseline.json')
    . ', then replace it and commit.' . PHP_EOL);
