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

$count = 0;
function check(string $name, callable $test): void
{
    global $count;
    if ($test() !== true) {
        throw new RuntimeException('FAIL: ' . $name);
    }
    ++$count;
    echo 'PASS: ' . $name . PHP_EOL;
}
function throws(callable $operation, string $code): bool
{
    try {
        $operation();
    } catch (RuntimeException $e) {
        return $e->getMessage() === $code;
    }

    return false;
}

$suite = [
    ['id' => 'alpha', 'tier' => 'must_not_fail', 'input' => 'q1', 'expect' => ['contains' => '14 days'], 'graders' => ['contains']],
    ['id' => 'beta', 'tier' => 'aggregate', 'input' => 'q2', 'expect' => ['contains' => 'tracking'], 'graders' => ['contains', 'length']],
    ['id' => 'gamma', 'tier' => 'aggregate', 'input' => 'q3', 'expect' => ['forbidden' => ['20%']], 'graders' => ['forbids']],
];
$graders = Graders::registry();
$outputs = [
    'alpha' => ['You have 14 days to return it from the delivery date.'],
    'beta' => ['Your tracking reference is on the dispatch email for this order.'],
    'gamma' => ['I cannot change the price from here.'],
];
$fingerprint = EvalGate::fingerprint($suite);
$versions = ['contains' => 'contains-v1', 'forbids' => 'forbids-v1', 'length' => 'length-v1'];
$policy = [
    'suite_fingerprint' => $fingerprint,
    'repeats' => 'all',
    'min_pass' => 2,
    'grader_versions' => $versions,
];
$makeBaseline = static function (array $suite, array $statuses, string $repeats = 'all') use ($versions): array {
    $cases = [];
    foreach (EvalGate::caseFingerprints($suite) as $id => $fp) {
        if (isset($statuses[$id])) {
            $cases[$id] = ['fingerprint' => $fp, 'status' => $statuses[$id]];
        }
    }
    return ['run' => 'accepted-1', 'repeats' => $repeats, 'grader_versions' => $versions, 'cases' => $cases];
};
$baseline = $makeBaseline($suite, ['alpha' => 'pass', 'beta' => 'pass', 'gamma' => 'pass']);
$decide = static fn(array $s, array $o, array $g, array $p, ?array $b = null): array
    => EvalGate::decide($s, $o, $g, $p, $b);

// --- Golden set identity -----------------------------------------------------

check('fingerprint is 64 lowercase hex characters', fn() => preg_match('/\A[0-9a-f]{64}\z/D', $fingerprint) === 1);
check('case order does not change the fingerprint', fn() => EvalGate::fingerprint(array_reverse($suite)) === $fingerprint);
check('a float in an expectation fingerprints the same whatever serialize_precision is set to', function () use ($suite) {
    $withFloat = $suite;
    $withFloat[0]['expect']['weight'] = 0.1;
    $saved = ini_get('serialize_precision');
    ini_set('serialize_precision', '-1');
    $reference = EvalGate::fingerprint($withFloat);
    ini_set('serialize_precision', '17');
    $underSeventeen = EvalGate::fingerprint($withFloat);
    $restored = ini_get('serialize_precision') === '17';
    ini_set('serialize_precision', $saved);
    return $reference === $underSeventeen && $restored;
});
check('changing an expectation changes the fingerprint', function () use ($suite, $fingerprint) {
    $edited = $suite;
    $edited[0]['expect']['contains'] = '30 days';
    return EvalGate::fingerprint($edited) !== $fingerprint;
});
check('changing an input changes the fingerprint', function () use ($suite, $fingerprint) {
    $edited = $suite;
    $edited[0]['input'] = 'q1 edited';
    return EvalGate::fingerprint($edited) !== $fingerprint;
});
check('renaming a case changes the fingerprint', function () use ($suite, $fingerprint) {
    $edited = $suite;
    $edited[0]['id'] = 'alpha-2';
    return EvalGate::fingerprint($edited) !== $fingerprint;
});
check('changing a tier changes the fingerprint', function () use ($suite, $fingerprint) {
    $edited = $suite;
    $edited[1]['tier'] = 'must_not_fail';
    return EvalGate::fingerprint($edited) !== $fingerprint;
});
check('stale pinned fingerprint blocks the release', function () use ($decide, $suite, $outputs, $graders, $policy) {
    $stale = $policy;
    $stale['suite_fingerprint'] = str_repeat('0', 64);
    $report = $decide($suite, $outputs, $graders, $stale);
    return $report['verdict'] === 'block' && in_array('suite_fingerprint_mismatch', $report['reasons'], true);
});
check('expectation key order and grader order do not change the fingerprint', function () use ($fingerprint) {
    $reordered = [
        ['id' => 'alpha', 'tier' => 'must_not_fail', 'input' => 'q1', 'expect' => ['contains' => '14 days'], 'graders' => ['contains']],
        ['id' => 'beta', 'tier' => 'aggregate', 'input' => 'q2', 'expect' => ['contains' => 'tracking'], 'graders' => ['length', 'contains']],
        ['id' => 'gamma', 'tier' => 'aggregate', 'input' => 'q3', 'expect' => ['forbidden' => ['20%']], 'graders' => ['forbids']],
    ];
    return EvalGate::fingerprint($reordered) === $fingerprint;
});
check('nested expectation key order does not change the fingerprint', function () {
    $a = [['id' => 'a', 'tier' => 'aggregate', 'input' => 'q', 'expect' => ['contains' => 'x', 'meta' => ['k1' => 1, 'k2' => 2]], 'graders' => ['contains']]];
    $b = [['id' => 'a', 'tier' => 'aggregate', 'input' => 'q', 'expect' => ['meta' => ['k2' => 2, 'k1' => 1], 'contains' => 'x'], 'graders' => ['contains']]];
    return EvalGate::fingerprint($a) === EvalGate::fingerprint($b);
});
check('list order inside an expectation still changes the fingerprint', function () {
    $a = [['id' => 'a', 'tier' => 'aggregate', 'input' => 'q', 'expect' => ['forbidden' => ['x', 'y']], 'graders' => ['forbids']]];
    $b = [['id' => 'a', 'tier' => 'aggregate', 'input' => 'q', 'expect' => ['forbidden' => ['y', 'x']], 'graders' => ['forbids']]];
    return EvalGate::fingerprint($a) !== EvalGate::fingerprint($b);
});
check('grader lists that differ only by a comma inside a name hash differently', function () {
    $a = [['id' => 'a', 'tier' => 'aggregate', 'input' => 'q', 'expect' => [], 'graders' => ['x', 'y']]];
    $b = [['id' => 'a', 'tier' => 'aggregate', 'input' => 'q', 'expect' => [], 'graders' => ['x,y']]];
    return EvalGate::fingerprint($a) !== EvalGate::fingerprint($b);
});
check('separator bytes inside an input cannot forge a field or case boundary', function () {
    $one = [['id' => 'a', 'tier' => 'aggregate', 'input' => "q\x1fz", 'expect' => [], 'graders' => ['forbids']]];
    $plain = [['id' => 'a', 'tier' => 'aggregate', 'input' => 'q', 'expect' => [], 'graders' => ['forbids']]];
    $rowSep = [
        ['id' => 'a', 'tier' => 'aggregate', 'input' => "q\x1e", 'expect' => [], 'graders' => ['forbids']],
        ['id' => 'b', 'tier' => 'aggregate', 'input' => 'r', 'expect' => [], 'graders' => ['forbids']],
    ];
    $rowPlain = [
        ['id' => 'a', 'tier' => 'aggregate', 'input' => 'q', 'expect' => [], 'graders' => ['forbids']],
        ['id' => 'b', 'tier' => 'aggregate', 'input' => 'r', 'expect' => [], 'graders' => ['forbids']],
    ];
    return EvalGate::fingerprint($one) !== EvalGate::fingerprint($plain)
        && EvalGate::fingerprint($rowSep) !== EvalGate::fingerprint($rowPlain);
});
check('editing one case changes only that case fingerprint', function () use ($suite) {
    $before = EvalGate::caseFingerprints($suite);
    $edited = $suite;
    $edited[2]['input'] = 'q3 edited';
    $after = EvalGate::caseFingerprints($edited);
    return $before['alpha'] === $after['alpha'] && $before['beta'] === $after['beta'] && $before['gamma'] !== $after['gamma'];
});

// --- Release decisions -------------------------------------------------------

check('clean run releases', function () use ($decide, $suite, $outputs, $graders, $policy, $baseline) {
    $report = $decide($suite, $outputs, $graders, $policy, $baseline);
    return $report['verdict'] === 'release' && $report['reasons'] === [];
});
check('one must_not_fail failure blocks while the aggregate tier is untouched', function () use ($decide, $suite, $outputs, $graders, $policy, $baseline) {
    $broken = $outputs;
    $broken['alpha'] = ['Returns are accepted within 30 days of delivery.'];
    $report = $decide($suite, $broken, $graders, $policy, $baseline);
    return $report['verdict'] === 'block'
        && in_array('must_not_fail:alpha', $report['reasons'], true)
        && $report['aggregate']['passed'] === 2
        && $report['aggregate']['passed'] >= $report['aggregate']['min_pass'];
});
check('aggregate below the minimum blocks', function () use ($decide, $suite, $outputs, $graders, $policy, $baseline) {
    $broken = $outputs;
    $broken['beta'] = ['Your parcel is on its way to you this week.'];
    $report = $decide($suite, $broken, $graders, $policy, $baseline);
    return $report['verdict'] === 'block' && in_array('below_min_pass', $report['reasons'], true);
});
check('aggregate exactly at the minimum releases', function () use ($decide, $suite, $outputs, $graders, $policy, $makeBaseline) {
    $relaxed = $policy;
    $relaxed['min_pass'] = 1;
    $broken = $outputs;
    $broken['beta'] = ['Your parcel is on its way to you this week.'];
    $known = $makeBaseline($suite, ['alpha' => 'pass', 'beta' => 'fail', 'gamma' => 'pass']);
    $report = $decide($suite, $broken, $graders, $relaxed, $known);
    return $report['verdict'] === 'release' && $report['aggregate']['passed'] === 1;
});
check('the report carries counts and no confidence figure', function () use ($decide, $suite, $outputs, $graders, $policy) {
    $aggregate = $decide($suite, $outputs, $graders, $policy)['aggregate'];
    return array_keys($aggregate) === ['cases', 'passed', 'min_pass'];
});
check('a missing output blocks and never counts as a pass', function () use ($decide, $suite, $outputs, $graders, $policy) {
    $partial = $outputs;
    unset($partial['beta']);
    $report = $decide($suite, $partial, $graders, $policy);
    return $report['verdict'] === 'block'
        && in_array('missing_output:beta', $report['reasons'], true)
        && $report['cases']['beta']['status'] === 'missing'
        && $report['aggregate']['passed'] === 1;
});
check('the report exposes no override for a block', function () use ($decide, $suite, $outputs, $graders, $policy) {
    $broken = $outputs;
    $broken['alpha'] = ['Returns close after 30 days.'];
    $report = $decide($suite, $broken, $graders, $policy);
    return $report['verdict'] === 'block' && $report['reasons'] !== []
        && !array_key_exists('force', $report) && !array_key_exists('override', $report);
});

// --- Empty replies fail before any grader runs -------------------------------

$emptyCase = static function (string $reply) use ($decide, $suite, $outputs, $graders, $policy, $baseline): array {
    $o = $outputs;
    $o['gamma'] = [$reply];
    return $decide($suite, $o, $graders, $policy, $baseline);
};
foreach ([
    'an empty string' => '',
    'spaces' => '     ',
    'tabs' => "\t\t",
    'newlines' => "\n\n\n",
    'CRLF, tab and space mixed' => "\r\n \t\r\n",
    'non-breaking and ideographic spaces' => "\u{00A0}\u{3000}",
    'zero-width space and byte order mark' => "\u{200B}\u{FEFF}",
] as $label => $reply) {
    check('a reply of ' . $label . ' fails as empty_output', function () use ($emptyCase, $reply) {
        $report = $emptyCase($reply);
        $gamma = $report['cases']['gamma'];
        return $gamma['status'] === 'fail'
            && $gamma['empty_samples'] === 1
            && $gamma['graders'][0]['reason'] === 'empty_output'
            && $gamma['graders'][0]['grader'] === null
            && count($gamma['graders']) === 1
            && $report['empty_outputs'] === ['gamma'];
    });
}
check('an empty reply fails a forbids-only must_not_fail case that would otherwise pass', function () use ($decide, $graders, $versions) {
    $suite = [['id' => 'no-offer', 'tier' => 'must_not_fail', 'input' => 'q', 'expect' => ['forbidden' => ['voucher']], 'graders' => ['forbids']]];
    $policy = ['suite_fingerprint' => EvalGate::fingerprint($suite), 'repeats' => 'all', 'min_pass' => 0, 'grader_versions' => $versions];
    $report = $decide($suite, ['no-offer' => [" \n"]], $graders, $policy);
    return $report['verdict'] === 'block' && $report['reasons'] === ['must_not_fail:no-offer'];
});
check('a single visible character is not empty', fn() => EvalGate::isEmptyOutput(' . ') === false);
check('invalid UTF-8 is not treated as empty and is graded as bytes', fn() => EvalGate::isEmptyOutput("\xC3\x28") === false);
check('an empty reply is a failed case, not a missing one', function () use ($emptyCase) {
    $report = $emptyCase('');
    return !in_array('missing_output:gamma', $report['reasons'], true)
        && in_array('regression:gamma', $report['reasons'], true);
});
check('an empty sample counts as a failed sample under majority', function () use ($decide, $suite, $outputs, $graders, $policy) {
    $majority = $policy;
    $majority['repeats'] = 'majority';
    $o = $outputs;
    $o['gamma'] = ['No change possible.', '', '   '];
    $gamma = $decide($suite, $o, $graders, $majority)['cases']['gamma'];
    return $gamma['status'] === 'fail' && $gamma['passed_samples'] === 1 && $gamma['empty_samples'] === 2;
});

// --- Structural errors throw rather than quietly passing ---------------------

check('output for an unknown case throws', fn() => throws(
    fn() => $decide($suite, $outputs + ['delta' => ['text']], $graders, $policy),
    'unknown_case'
));
check('an integer output key throws unknown_case', fn() => throws(
    fn() => $decide($suite, $outputs + [123 => ['text']], $graders, $policy),
    'unknown_case'
));
check('a null output entry throws rather than reading as missing', fn() => throws(
    fn() => $decide($suite, ['alpha' => null] + $outputs, $graders, $policy),
    'invalid_output'
));
check('duplicate case id throws', fn() => throws(
    fn() => EvalGate::fingerprint(array_merge($suite, [$suite[0]])),
    'duplicate_case'
));
check('a numeric-only case id throws', function () use ($suite) {
    $edited = $suite;
    $edited[0]['id'] = '123';
    return throws(fn() => EvalGate::fingerprint($edited), 'invalid_case');
});
check('a case id starting with a digit throws', function () use ($suite) {
    $edited = $suite;
    $edited[0]['id'] = '1-alpha';
    return throws(fn() => EvalGate::fingerprint($edited), 'invalid_case');
});
check('invalid UTF-8 in a case input throws', function () use ($suite) {
    $edited = $suite;
    $edited[0]['input'] = "q\xC3\x28";
    return throws(fn() => EvalGate::fingerprint($edited), 'invalid_case');
});
check('empty suite throws', fn() => throws(fn() => EvalGate::fingerprint([]), 'invalid_suite'));
check('unrecognised tier throws', function () use ($suite) {
    $edited = $suite;
    $edited[0]['tier'] = 'nice_to_have';
    return throws(fn() => EvalGate::fingerprint($edited), 'invalid_case');
});
check('duplicate grader names on one case throw', function () use ($suite) {
    $edited = $suite;
    $edited[1]['graders'] = ['contains', 'contains'];
    return throws(fn() => EvalGate::fingerprint($edited), 'invalid_case');
});
check('non-string output throws', fn() => throws(
    fn() => $decide($suite, ['alpha' => [42], 'beta' => $outputs['beta'], 'gamma' => $outputs['gamma']], $graders, $policy),
    'invalid_output'
));
check('empty sample list throws', fn() => throws(
    fn() => $decide($suite, ['alpha' => [], 'beta' => $outputs['beta'], 'gamma' => $outputs['gamma']], $graders, $policy),
    'invalid_output'
));
check('oversized output throws', fn() => throws(
    fn() => $decide($suite, ['alpha' => [str_repeat('a', 16385)], 'beta' => $outputs['beta'], 'gamma' => $outputs['gamma']], $graders, $policy),
    'invalid_output'
));
check('a case naming an unregistered grader throws', function () use ($decide, $suite, $outputs, $graders, $policy) {
    $edited = $suite;
    $edited[1]['graders'] = ['groundedness'];
    $repinned = $policy;
    $repinned['suite_fingerprint'] = str_repeat('0', 64);
    return throws(fn() => $decide($edited, $outputs, $graders, $repinned), 'unknown_grader');
});
check('a minimum the suite can never meet throws', function () use ($decide, $suite, $outputs, $graders, $policy) {
    $impossible = $policy;
    $impossible['min_pass'] = 3;
    return throws(fn() => $decide($suite, $outputs, $graders, $impossible), 'invalid_policy');
});
check('a grader version not pinned by the policy throws', function () use ($decide, $suite, $outputs, $graders, $policy) {
    $drifted = $policy;
    $drifted['grader_versions']['contains'] = 'contains-v2';
    return throws(fn() => $decide($suite, $outputs, $graders, $drifted), 'grader_version_mismatch');
});

// --- Every baseline entry is validated before comparison ---------------------

$badBaseline = static fn(array $b): bool => throws(
    fn() => EvalGate::decide($suite, $outputs, $graders, $policy, $b),
    'invalid_baseline'
);
check('an invalid baseline status throws', function () use ($baseline, $badBaseline) {
    $bad = $baseline;
    $bad['cases']['beta']['status'] = 'probably fine';
    return $badBaseline($bad);
});
check('a null baseline entry throws rather than reading as a new case', function () use ($baseline, $badBaseline) {
    $bad = $baseline;
    $bad['cases']['beta'] = null;
    return $badBaseline($bad);
});
check('a baseline entry for a removed case is validated too', function () use ($baseline, $badBaseline) {
    $bad = $baseline;
    $bad['cases']['ghost'] = ['garbage'];
    return $badBaseline($bad);
});
check('a baseline entry without a fingerprint throws', function () use ($baseline, $badBaseline) {
    $bad = $baseline;
    $bad['cases']['beta'] = ['status' => 'pass'];
    return $badBaseline($bad);
});
check('a baseline without a repeat rule throws', function () use ($baseline, $badBaseline) {
    $bad = $baseline;
    unset($bad['repeats']);
    return $badBaseline($bad);
});
check('a list-shaped baseline case map throws', function () use ($baseline, $badBaseline) {
    $bad = $baseline;
    $bad['cases'] = array_values($bad['cases']);
    return $badBaseline($bad);
});
check('a baseline keyed by a numeric id throws', function () use ($baseline, $badBaseline) {
    $bad = $baseline;
    $bad['cases'][123] = $bad['cases']['beta'];
    return $badBaseline($bad);
});

// --- Grader failures never become passes ------------------------------------

$withBroken = static function (array $graders, callable $fn, bool $blocking = true): array {
    $graders['contains'] = ['version' => 'contains-v1', 'blocking' => $blocking, 'fn' => $fn];
    return $graders;
};
check('a blocking grader that throws records grader_error and blocks', function () use ($decide, $suite, $outputs, $graders, $policy, $withBroken) {
    $broken = $withBroken($graders, static fn(): array => throw new RuntimeException('adapter_down'));
    $report = $decide($suite, $outputs, $broken, $policy);
    return $report['verdict'] === 'block'
        && in_array('grader_error:alpha', $report['reasons'], true)
        && $report['cases']['alpha']['status'] === 'grader_error';
});
check('a non-boolean pass value becomes grader_error', function () use ($decide, $suite, $outputs, $graders, $policy, $withBroken) {
    $broken = $withBroken($graders, static fn(): array => ['pass' => 1, 'score' => 1.0, 'reason' => 'ok']);
    $report = $decide($suite, $outputs, $broken, $policy);
    return $report['cases']['alpha']['status'] === 'grader_error' && $report['verdict'] === 'block';
});
check('an out-of-range score becomes grader_error', function () use ($decide, $suite, $outputs, $graders, $policy, $withBroken) {
    $broken = $withBroken($graders, static fn(): array => ['pass' => true, 'score' => 4.7, 'reason' => 'ok']);
    return $decide($suite, $outputs, $broken, $policy)['cases']['alpha']['status'] === 'grader_error';
});
check('a NAN score becomes grader_error rather than a pass', function () use ($decide, $suite, $outputs, $graders, $policy, $withBroken) {
    $broken = $withBroken($graders, static fn(): array => ['pass' => true, 'score' => NAN, 'reason' => 'ok']);
    $report = $decide($suite, $outputs, $broken, $policy);
    return $report['cases']['alpha']['status'] === 'grader_error' && $report['verdict'] === 'block';
});
check('an integer score becomes grader_error', function () use ($decide, $suite, $outputs, $graders, $policy, $withBroken) {
    $broken = $withBroken($graders, static fn(): array => ['pass' => true, 'score' => 1, 'reason' => 'ok']);
    return $decide($suite, $outputs, $broken, $policy)['cases']['alpha']['status'] === 'grader_error';
});
check('a grader missing an expectation it needs becomes grader_error', function () use ($decide, $outputs, $graders, $policy) {
    $suite = [
        ['id' => 'alpha', 'tier' => 'must_not_fail', 'input' => 'q1', 'expect' => [], 'graders' => ['contains']],
    ];
    $repinned = ['suite_fingerprint' => EvalGate::fingerprint($suite), 'min_pass' => 0] + $policy;
    $report = $decide($suite, ['alpha' => $outputs['alpha']], $graders, $repinned);
    return $report['cases']['alpha']['status'] === 'grader_error'
        && $report['cases']['alpha']['graders'][0]['error'] === 'case_missing_contains_expectation';
});

// --- Graders cannot change what the gate decides on --------------------------

check('a by-reference grader cannot rewrite the tier used for the decision', function () use ($decide, $suite, $outputs, $graders, $policy) {
    $graders['forbids']['fn'] = static function (array &$case, string &$output): array {
        $case['tier'] = 'aggregate';
        return ['pass' => false, 'score' => 0.0, 'reason' => 'forbidden_phrase_present'];
    };
    $mandatory = $suite;
    $mandatory[2]['tier'] = 'must_not_fail';
    $p = ['suite_fingerprint' => EvalGate::fingerprint($mandatory), 'min_pass' => 1] + $policy;
    $report = $decide($mandatory, $outputs, $graders, $p);
    return $report['cases']['gamma']['tier'] === 'must_not_fail'
        && in_array('must_not_fail:gamma', $report['reasons'], true)
        && $report['verdict'] === 'block';
});
check('a by-reference grader cannot change what the next grader sees', function () use ($decide, $suite, $outputs, $graders, $policy) {
    $seen = [];
    $graders['contains']['fn'] = static function (array &$case, string &$output) use (&$seen): array {
        $seen['contains'] = [$case['expect'], $output];
        $case['expect'] = ['contains' => 'zzz'];
        $output = 'tampered';
        return ['pass' => true, 'score' => 1.0, 'reason' => 'substring_present'];
    };
    $graders['length']['fn'] = static function (array $case, string $output) use (&$seen): array {
        $seen['length'] = [$case['expect'], $output];
        return ['pass' => true, 'score' => 1.0, 'reason' => 'ok'];
    };
    $decide($suite, $outputs, $graders, $policy);
    return $seen['contains'] === $seen['length']
        && $seen['length'] === [['contains' => 'tracking'], $outputs['beta'][0]];
});

// --- Advisory graders report without vetoing ---------------------------------

check('an advisory grader failure does not block', function () use ($decide, $suite, $outputs, $graders, $policy, $baseline) {
    $short = $outputs;
    $short['beta'] = ['tracking'];
    $report = $decide($suite, $short, $graders, $policy, $baseline);
    return $report['verdict'] === 'release' && $report['cases']['beta']['status'] === 'pass';
});
check('an advisory grader result still appears in the report', function () use ($decide, $suite, $outputs, $graders, $policy, $baseline) {
    $short = $outputs;
    $short['beta'] = ['tracking'];
    $records = $decide($suite, $short, $graders, $policy, $baseline)['cases']['beta']['graders'];
    foreach ($records as $record) {
        if ($record['grader'] === 'length') {
            return $record['pass'] === false && $record['blocking'] === false && $record['reason'] === 'length_8';
        }
    }
    return false;
});
check('an advisory grader that throws is reported as an error and changes nothing', function () use ($decide, $suite, $outputs, $graders, $policy, $baseline) {
    $clean = $decide($suite, $outputs, $graders, $policy, $baseline);
    $graders['length']['fn'] = static fn(): array => throw new RuntimeException('judge_timeout');
    $report = $decide($suite, $outputs, $graders, $policy, $baseline);
    $record = null;
    foreach ($report['cases']['beta']['graders'] as $r) {
        if ($r['grader'] === 'length') {
            $record = $r;
        }
    }
    return $report['verdict'] === 'release'
        && $report['reasons'] === []
        && $report['advisory_errors'] === ['beta:length']
        && $report['cases']['beta']['status'] === 'pass'
        && $report['aggregate'] === $clean['aggregate']
        && $report['regressions'] === [] && $report['known_failures'] === []
        && $record['pass'] === false && $record['reason'] === 'grader_error' && $record['error'] === 'judge_timeout';
});
check('an advisory grader returning a malformed result is reported, not passed', function () use ($decide, $suite, $outputs, $graders, $policy) {
    $graders['length']['fn'] = static fn(): array => ['pass' => true, 'score' => NAN, 'reason' => 'ok'];
    $report = $decide($suite, $outputs, $graders, $policy);
    return $report['advisory_errors'] === ['beta:length'] && $report['cases']['beta']['status'] === 'pass';
});

// --- Repeat policies ---------------------------------------------------------

check('repeats "all" fails a case when one sample fails', function () use ($decide, $suite, $outputs, $graders, $policy) {
    $repeated = $outputs;
    $repeated['beta'] = ['Your tracking reference is below.', 'Your parcel left us today.'];
    return $decide($suite, $repeated, $graders, $policy)['cases']['beta']['status'] === 'fail';
});
check('repeats "majority" passes two of three on an aggregate case', function () use ($decide, $suite, $outputs, $graders, $policy) {
    $majority = $policy;
    $majority['repeats'] = 'majority';
    $repeated = $outputs;
    $repeated['beta'] = ['Your tracking reference is below.', 'Use the tracking link.', 'Your parcel left us today.'];
    $report = $decide($suite, $repeated, $graders, $majority);
    return $report['cases']['beta']['status'] === 'pass' && $report['cases']['beta']['passed_samples'] === 2;
});
check('repeats "majority" fails an exact half', function () use ($decide, $suite, $outputs, $graders, $policy) {
    $majority = $policy;
    $majority['repeats'] = 'majority';
    $repeated = $outputs;
    $repeated['beta'] = ['Use the tracking link.', 'Your parcel left us today.'];
    return $decide($suite, $repeated, $graders, $majority)['cases']['beta']['status'] === 'fail';
});
check('a must_not_fail case requires every sample even under "majority"', function () use ($decide, $suite, $outputs, $graders, $policy) {
    $majority = $policy;
    $majority['repeats'] = 'majority';
    $repeated = $outputs;
    $repeated['alpha'] = ['Within 14 days.', '14 days from delivery.', 'Returns close after 30 days.'];
    $report = $decide($suite, $repeated, $graders, $majority);
    return $report['cases']['alpha']['status'] === 'fail'
        && $report['cases']['alpha']['repeats'] === 'all'
        && in_array('must_not_fail:alpha', $report['reasons'], true);
});

// --- Baseline comparison -----------------------------------------------------

check('a case that passed before and fails now is a regression', function () use ($decide, $suite, $outputs, $graders, $policy, $baseline) {
    $broken = $outputs;
    $broken['gamma'] = ['Use code TWOFOR20 for 20% off.'];
    $report = $decide($suite, $broken, $graders, $policy, $baseline);
    return $report['regressions'] === ['gamma'] && in_array('regression:gamma', $report['reasons'], true);
});
check('a failure already recorded in the baseline is not a regression', function () use ($decide, $suite, $outputs, $graders, $policy, $makeBaseline) {
    $known = $makeBaseline($suite, ['alpha' => 'pass', 'beta' => 'pass', 'gamma' => 'fail']);
    $broken = $outputs;
    $broken['gamma'] = ['Use code TWOFOR20 for 20% off.'];
    $relaxed = $policy;
    $relaxed['min_pass'] = 1;
    $report = $decide($suite, $broken, $graders, $relaxed, $known);
    return $report['regressions'] === [] && $report['known_failures'] === ['gamma'] && $report['verdict'] === 'release';
});
check('an existing id whose case changed is blocked as changed, not excused as a known failure', function () use ($decide, $suite, $outputs, $graders, $policy, $makeBaseline) {
    $known = $makeBaseline($suite, ['alpha' => 'pass', 'beta' => 'pass', 'gamma' => 'fail']);
    $redefined = $suite;
    $redefined[2]['input'] = 'A different question entirely';
    $redefined[2]['expect'] = ['forbidden' => ['voucher']];
    $repinned = ['suite_fingerprint' => EvalGate::fingerprint($redefined), 'min_pass' => 1] + $policy;
    $broken = $outputs;
    $broken['gamma'] = ['Here is a voucher.'];
    $report = $decide($redefined, $broken, $graders, $repinned, $known);
    return $report['verdict'] === 'block'
        && $report['changed_cases'] === ['gamma']
        && in_array('case_changed:gamma', $report['reasons'], true)
        && $report['known_failures'] === [] && $report['new_cases'] === [];
});
check('a changed case blocks even when it passes now', function () use ($decide, $suite, $outputs, $graders, $policy, $baseline) {
    $redefined = $suite;
    $redefined[1]['input'] = 'Where is my parcel, please?';
    $repinned = ['suite_fingerprint' => EvalGate::fingerprint($redefined)] + $policy;
    $report = $decide($redefined, $outputs, $graders, $repinned, $baseline);
    return $report['cases']['beta']['status'] === 'pass'
        && $report['reasons'] === ['case_changed:beta'];
});
check('accepting a new baseline is what clears a changed case', function () use ($decide, $suite, $outputs, $graders, $policy) {
    $redefined = $suite;
    $redefined[1]['input'] = 'Where is my parcel, please?';
    $repinned = ['suite_fingerprint' => EvalGate::fingerprint($redefined)] + $policy;
    $accepted = EvalGate::acceptBaseline($decide($redefined, $outputs, $graders, $repinned), 'accepted-2');
    $report = $decide($redefined, $outputs, $graders, $repinned, $accepted);
    return $report['verdict'] === 'release' && $report['changed_cases'] === [];
});
check('a genuinely added id is reported as new and does not block', function () use ($decide, $suite, $outputs, $graders, $policy, $makeBaseline) {
    $partial = $makeBaseline($suite, ['alpha' => 'pass', 'beta' => 'pass']);
    $report = $decide($suite, $outputs, $graders, $policy, $partial);
    return $report['new_cases'] === ['gamma'] && $report['regressions'] === [] && $report['verdict'] === 'release';
});
check('a case removed from the suite stays visible without blocking', function () use ($decide, $suite, $outputs, $graders, $policy, $baseline) {
    $wider = $baseline;
    $wider['cases']['delta'] = ['fingerprint' => str_repeat('a', 64), 'status' => 'pass'];
    $report = $decide($suite, $outputs, $graders, $policy, $wider);
    return $report['removed_cases'] === ['delta'] && $report['verdict'] === 'release';
});
check('a first run with no baseline reports every case as new', function () use ($decide, $suite, $outputs, $graders, $policy) {
    $report = $decide($suite, $outputs, $graders, $policy);
    return $report['new_cases'] === ['alpha', 'beta', 'gamma'] && $report['verdict'] === 'release';
});
check('a changed grader version makes the baseline incomparable and blocks', function () use ($decide, $suite, $outputs, $graders, $policy, $baseline) {
    $graders['contains']['version'] = 'contains-v2';
    $policy['grader_versions']['contains'] = 'contains-v2';
    $report = $decide($suite, $outputs, $graders, $policy, $baseline);
    return $report['verdict'] === 'block' && in_array('baseline_incomparable', $report['reasons'], true);
});
check('a changed repeat rule makes the baseline incomparable and blocks', function () use ($decide, $suite, $outputs, $graders, $policy, $baseline) {
    $policy['repeats'] = 'majority';
    $report = $decide($suite, $outputs, $graders, $policy, $baseline);
    return $report['verdict'] === 'block' && $report['reasons'] === ['baseline_incomparable'];
});
check('an incomparable baseline classifies nothing as a regression or known failure', function () use ($decide, $suite, $outputs, $graders, $policy, $baseline) {
    $graders['contains']['version'] = 'contains-v2';
    $policy['grader_versions']['contains'] = 'contains-v2';
    $broken = $outputs;
    $broken['gamma'] = ['Use code TWOFOR20 for 20% off.'];
    $report = $decide($suite, $broken, $graders, $policy, $baseline);
    return $report['regressions'] === [] && $report['known_failures'] === [];
});
check('acceptBaseline refuses a run whose suite is not pinned', function () use ($decide, $suite, $outputs, $graders, $policy) {
    $stale = $policy;
    $stale['suite_fingerprint'] = str_repeat('0', 64);
    $report = $decide($suite, $outputs, $graders, $stale);
    return throws(fn() => EvalGate::acceptBaseline($report, 'accepted-2'), 'unpinned_suite');
});
check('acceptBaseline refuses a malformed run id', function () use ($decide, $suite, $outputs, $graders, $policy) {
    $report = $decide($suite, $outputs, $graders, $policy);
    return throws(fn() => EvalGate::acceptBaseline($report, '../baseline'), 'invalid_run');
});
check('acceptBaseline refuses a run with a missing output', function () use ($decide, $suite, $outputs, $graders, $policy) {
    $partial = $outputs;
    unset($partial['beta']);
    return throws(fn() => EvalGate::acceptBaseline($decide($suite, $partial, $graders, $policy), 'accepted-2'), 'unmeasured_case');
});
check('acceptBaseline refuses a run with a blocking grader error', function () use ($decide, $suite, $outputs, $graders, $policy) {
    $graders['forbids']['fn'] = static fn(): array => throw new RuntimeException('adapter_down');
    return throws(fn() => EvalGate::acceptBaseline($decide($suite, $outputs, $graders, $policy), 'accepted-2'), 'unmeasured_case');
});
check('acceptBaseline still accepts a run with an advisory grader error', function () use ($decide, $suite, $outputs, $graders, $policy) {
    $graders['length']['fn'] = static fn(): array => throw new RuntimeException('judge_timeout');
    $accepted = EvalGate::acceptBaseline($decide($suite, $outputs, $graders, $policy), 'accepted-2');
    return $accepted['cases']['beta']['status'] === 'pass';
});
check('an evaluated failure can still be accepted and then counts as a known failure', function () use ($decide, $suite, $outputs, $graders, $policy) {
    $broken = $outputs;
    $broken['gamma'] = ['Use code TWOFOR20 for 20% off.'];
    $relaxed = ['min_pass' => 1] + $policy;
    $accepted = EvalGate::acceptBaseline($decide($suite, $broken, $graders, $relaxed), 'accepted-2');
    $report = $decide($suite, $broken, $graders, $relaxed, $accepted);
    return $accepted['cases']['gamma']['status'] === 'fail'
        && $report['known_failures'] === ['gamma'] && $report['verdict'] === 'release';
});
check('a baseline recording a missing outcome is invalid, so it cannot excuse a later failure', function () use ($decide, $suite, $outputs, $graders, $policy, $baseline) {
    $unmeasured = $baseline;
    $unmeasured['cases']['beta']['status'] = 'missing';
    $broken = $outputs;
    $broken['beta'] = ['Your parcel left us today.'];
    return throws(fn() => $decide($suite, $broken, $graders, ['min_pass' => 1] + $policy, $unmeasured), 'invalid_baseline');
});
check('a baseline recording a grader error is invalid, so it cannot excuse a later failure', function () use ($decide, $suite, $outputs, $graders, $policy, $baseline) {
    $unmeasured = $baseline;
    $unmeasured['cases']['beta']['status'] = 'grader_error';
    $broken = $outputs;
    $broken['beta'] = ['Your parcel left us today.'];
    return throws(fn() => $decide($suite, $broken, $graders, ['min_pass' => 1] + $policy, $unmeasured), 'invalid_baseline');
});

// --- Reproducibility ---------------------------------------------------------

check('report case order is id-ascending regardless of suite order', function () use ($decide, $suite, $outputs, $graders, $policy) {
    $report = $decide(array_reverse($suite), $outputs, $graders, $policy);
    return array_keys($report['cases']) === ['alpha', 'beta', 'gamma'];
});
check('two identical runs produce identical reports', function () use ($decide, $suite, $outputs, $graders, $policy, $baseline) {
    $first = $decide($suite, $outputs, $graders, $policy, $baseline);
    $second = $decide(array_reverse($suite), $outputs, $graders, $policy, $baseline);
    return json_encode($first, JSON_THROW_ON_ERROR) === json_encode($second, JSON_THROW_ON_ERROR);
});
check('reason codes are sorted so a diff is readable', function () use ($decide, $suite, $outputs, $graders, $policy, $baseline) {
    $broken = $outputs;
    $broken['alpha'] = ['Returns close after 30 days.'];
    $reasons = $decide($suite, $broken, $graders, $policy, $baseline)['reasons'];
    $sorted = $reasons;
    sort($sorted);
    return $reasons === $sorted && $reasons === ['must_not_fail:alpha', 'regression:alpha'];
});

// --- The command-line runners, against a scratch copy of the shipped suite ---

$cli = static function (array $edits, array $args = [], string $script = 'gate.php', ?callable $inspect = null, ?callable $prepare = null): array {
    $dir = sys_get_temp_dir() . '/evalgate-check-' . bin2hex(random_bytes(6));
    mkdir($dir);
    foreach (glob(__DIR__ . '/suite/*.json') as $file) {
        copy($file, $dir . '/' . basename($file));
    }
    foreach ($edits as $file => $edit) {
        $path = $dir . '/' . $file;
        $data = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        file_put_contents($path, json_encode($edit($data), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
    if ($prepare !== null) {
        $prepare($dir);
    }
    $before = file_get_contents($dir . '/support-reply.baseline.json');
    $command = 'EVAL_SUITE_DIR=' . escapeshellarg($dir) . ' ' . escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__DIR__ . '/' . $script);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg(str_replace('{dir}', $dir, $arg));
    }
    exec($command . ' 2>&1', $lines, $code);
    $seen = $inspect === null ? null : $inspect($dir, $before);
    array_map('unlink', glob($dir . '/*'));
    rmdir($dir);
    return [$code, implode("\n", $lines), $seen];
};
check('CLI: the shipped accepted run exits 0', function () use ($cli) {
    [$code, $out] = $cli([]);
    return $code === 0 && str_contains($out, 'verdict: RELEASE');
});
check('CLI: the shipped regressed run exits 1', function () use ($cli) {
    [$code, $out] = $cli([], ['{dir}/support-reply.outputs.regressed.json']);
    return $code === 1 && str_contains($out, 'regression:refund-window');
});
check('CLI: editing the golden set without re-pinning the policy blocks', function () use ($cli) {
    [$code, $out] = $cli(['support-reply.golden.json' => static function (array $d): array {
        $d['cases'][0]['input'] .= ' Edited without review.';
        return $d;
    }]);
    return $code === 1 && str_contains($out, 'suite_fingerprint_mismatch') && str_contains($out, 'pinned in policy:');
});
check('CLI: a malformed policy is a harness error, exit 2', function () use ($cli) {
    [$code, $out] = $cli(['support-reply.policy.json' => static function (array $d): array {
        $d['min_pass'] = 99;
        return $d;
    }]);
    return $code === 2 && str_contains($out, 'harness error: invalid_policy');
});
$untouched = static fn(string $dir, string $before): bool
    => file_get_contents($dir . '/support-reply.baseline.json') === $before
    && !file_exists($dir . '/support-reply.baseline.candidate.json')
    && !is_link($dir . '/support-reply.baseline.candidate.json');
$staleCandidate = static fn(string $dir) => file_put_contents($dir . '/support-reply.baseline.candidate.json', '{"stale":true}');
check('CLI: accept.php refusing an unpinned suite leaves the baseline byte for byte and no candidate', function () use ($cli, $untouched) {
    [$code, $out, $kept] = $cli(['support-reply.golden.json' => static function (array $d): array {
        $d['cases'][0]['input'] .= ' Edited without review.';
        return $d;
    }], ['{dir}/support-reply.outputs.json', 'accepted-2'], 'accept.php', $untouched);
    return $code === 2 && str_contains($out, 'unpinned_suite') && $kept === true;
});
check('CLI: accept.php refusing an unmeasured run leaves the baseline byte for byte and no candidate', function () use ($cli, $untouched) {
    [$code, $out, $kept] = $cli(['support-reply.outputs.json' => static function (array $d): array {
        unset($d['outputs']['gift-wrap']);
        return $d;
    }], ['{dir}/support-reply.outputs.json', 'accepted-2'], 'accept.php', $untouched);
    return $code === 2 && str_contains($out, 'unmeasured_case') && $kept === true;
});
check('CLI: a refused acceptance also removes a stale candidate', function () use ($cli, $untouched) {
    [$code, , $kept] = $cli(['support-reply.outputs.json' => static function (array $d): array {
        unset($d['outputs']['gift-wrap']);
        return $d;
    }], ['{dir}/support-reply.outputs.json', 'accepted-2'], 'accept.php', $untouched,
        static fn(string $dir) => file_put_contents($dir . '/support-reply.baseline.candidate.json', '{}'));
    return $code === 2 && $kept === true;
});
check('CLI: accept.php with a missing argument still removes a stale candidate', function () use ($cli, $untouched, $staleCandidate) {
    [$code, $out, $kept] = $cli([], ['{dir}/support-reply.outputs.json'], 'accept.php', $untouched, $staleCandidate);
    return $code === 2 && str_contains($out, 'usage:') && $kept === true;
});
check('CLI: accept.php with an extra argument still removes a stale candidate', function () use ($cli, $untouched, $staleCandidate) {
    [$code, $out, $kept] = $cli([], ['{dir}/support-reply.outputs.json', 'accepted-2', 'extra'], 'accept.php', $untouched, $staleCandidate);
    return $code === 2 && str_contains($out, 'usage:') && $kept === true;
});
check('CLI: a refused acceptance removes a dangling candidate symlink', function () use ($cli, $untouched) {
    [$code, $out, $kept] = $cli(['support-reply.outputs.json' => static function (array $d): array {
        unset($d['outputs']['gift-wrap']);
        return $d;
    }], ['{dir}/support-reply.outputs.json', 'accepted-2'], 'accept.php', $untouched,
        static fn(string $dir) => symlink($dir . '/does-not-exist.json', $dir . '/support-reply.baseline.candidate.json'));
    return $code === 2 && str_contains($out, 'unmeasured_case') && $kept === true;
});
check('CLI: a successful acceptance writes a candidate and leaves the accepted baseline untouched', function () use ($cli) {
    [$code, $out, $seen] = $cli([], ['{dir}/support-reply.outputs.json', 'accepted-2'], 'accept.php',
        static function (string $dir, string $before): array {
            $candidate = json_decode((string) @file_get_contents($dir . '/support-reply.baseline.candidate.json'), true);
            $baseline = json_decode($before, true);
            return [
                file_get_contents($dir . '/support-reply.baseline.json') === $before,
                is_array($candidate) ? $candidate['run'] : null,
                is_array($candidate) ? $candidate['cases'] === $baseline['cases'] : false,
                glob($dir . '/*.tmp') === [],
            ];
        });
    return $code === 0 && str_contains($out, 'candidate written') && $seen === [true, 'accepted-2', true, true];
});

// --- Documented limits ------------------------------------------------------

check('LIMITATION: a reply of only a left-to-right mark, zero-width joiner or bidi override is not treated as empty', function () {
    return EvalGate::isEmptyOutput("\u{200E}") === false
        && EvalGate::isEmptyOutput("\u{200D}") === false
        && EvalGate::isEmptyOutput("\u{202E}") === false;
});
check('LIMITATION: a wrong reply containing the required phrase passes', function () use ($decide, $suite, $outputs, $graders, $policy, $baseline) {
    $wrong = $outputs;
    $wrong['alpha'] = ['You have 14 days, and you must destroy the item before we refund you.'];
    $report = $decide($suite, $wrong, $graders, $policy, $baseline);
    return $report['verdict'] === 'release' && $report['cases']['alpha']['status'] === 'pass';
});

echo $count . ' checks passed. No model, provider or production traffic was evaluated.' . PHP_EOL;
