<?php
declare(strict_types=1);

/**
 * Educational release gate for an AI feature, PHP 8.1+.
 *
 * Grades recorded outputs against a versioned golden set and returns a release
 * decision. It performs no model calls, no network access and no CMS writes.
 * It measures what its graders measure and nothing else.
 */
final class EvalGate
{
    private const MAX_CASES = 500;
    private const MAX_SAMPLES = 20;
    private const MAX_OUTPUT_BYTES = 16384;
    private const TIERS = ['must_not_fail', 'aggregate'];
    private const REPEAT_RULES = ['all', 'majority'];
    /**
     * Only evaluated outcomes can be recorded in a baseline. A case with no
     * output or a blocking grader error was never measured, so it cannot become
     * the known failure that excuses a later failing run.
     */
    private const BASELINE_STATUSES = ['pass', 'fail'];

    /** Ids start with a letter so PHP never turns one into an integer array key. */
    private const ID_PATTERN = '/\A[a-z][a-z0-9-]{0,63}\z/D';

    /**
     * A sample is empty when nothing remains after removing Unicode whitespace
     * (spaces, tabs, newlines, carriage returns, non-breaking and other Unicode
     * spaces) and three zero-width characters: zero-width space, word joiner and
     * byte order mark. With the u modifier PHP enables Unicode properties, so \s
     * is Unicode-aware. Other invisible format characters, such as the
     * left-to-right mark, zero-width joiner or bidirectional overrides, are not
     * in this set and are graded like any other text.
     */
    private const EMPTY_PATTERN = '/\A[\s\x{200B}\x{2060}\x{FEFF}]*\z/u';

    /**
     * Canonical fingerprint of a golden set.
     *
     * Hashes canonical JSON of every case's id, tier, input, expectation and
     * sorted grader list, ordered by id. Case order, expectation key order and
     * grader order do not affect the result. Any change to a covered field does.
     * Callers pin this value in the release policy so an edited golden set
     * cannot quietly satisfy an older gate.
     */
    public static function fingerprint(array $suite): string
    {
        $rows = [];
        foreach (self::normaliseSuite($suite) as $id => $case) {
            $rows[] = self::canonicalCase($id, $case);
        }

        return hash('sha256', self::json($rows));
    }

    /**
     * Per-case fingerprints over the same canonical fields. A baseline stores
     * these so an existing id whose meaning changed is not compared as though
     * it were the same case.
     *
     * @return array<string, string>
     */
    public static function caseFingerprints(array $suite): array
    {
        $out = [];
        foreach (self::normaliseSuite($suite) as $id => $case) {
            $out[$id] = hash('sha256', self::json(self::canonicalCase($id, $case)));
        }

        return $out;
    }

    /**
     * @param array         $suite    Golden set cases, owned by the repository.
     * @param array         $outputs  case id => list of recorded output strings.
     * @param array         $graders  name => ['version' => string, 'blocking' => bool, 'fn' => callable].
     * @param array         $policy   Release policy, including the pinned fingerprint.
     * @param array|null    $baseline Last explicitly accepted run, or null on a first run.
     *
     * @return array Release report. There is no parameter that turns a block into a release.
     */
    public static function decide(
        array $suite,
        array $outputs,
        array $graders,
        array $policy,
        ?array $baseline = null
    ): array {
        $cases = self::normaliseSuite($suite);
        self::assertGraders($graders, $cases);
        self::assertPolicy($policy, $graders, $cases);
        if ($baseline !== null) {
            self::assertBaseline($baseline);
        }

        $fingerprint = self::fingerprint($suite);
        $caseFingerprints = self::caseFingerprints($suite);
        $reasons = [];
        if (!hash_equals($policy['suite_fingerprint'], $fingerprint)) {
            $reasons[] = 'suite_fingerprint_mismatch';
        }

        foreach (array_keys($outputs) as $id) {
            if (!is_string($id) || !isset($cases[$id])) {
                throw new RuntimeException('unknown_case');
            }
        }

        $results = [];
        $advisoryErrors = [];
        $emptyOutputs = [];
        foreach ($cases as $id => $case) {
            // Present-but-null is malformed input, not a missing case.
            if (array_key_exists($id, $outputs) && !is_array($outputs[$id])) {
                throw new RuntimeException('invalid_output');
            }
            $result = self::gradeCase($id, $case, $outputs[$id] ?? null, $graders, $policy['repeats']);
            $result['fingerprint'] = $caseFingerprints[$id];
            $results[$id] = $result;
            foreach ($result['graders'] as $record) {
                if ($record['reason'] === 'grader_error' && $record['blocking'] === false) {
                    $advisoryErrors[] = $id . ':' . $record['grader'];
                }
            }
            if ($result['empty_samples'] > 0) {
                $emptyOutputs[] = $id;
            }
        }

        $aggregateTotal = 0;
        $aggregatePassed = 0;
        foreach ($results as $result) {
            if ($result['tier'] !== 'aggregate') {
                continue;
            }
            ++$aggregateTotal;
            if ($result['status'] === 'pass') {
                ++$aggregatePassed;
            }
        }

        foreach ($results as $id => $result) {
            if ($result['tier'] === 'must_not_fail' && $result['status'] !== 'pass') {
                $reasons[] = 'must_not_fail:' . $id;
            }
            if ($result['status'] === 'grader_error') {
                $reasons[] = 'grader_error:' . $id;
            }
            if ($result['status'] === 'missing') {
                $reasons[] = 'missing_output:' . $id;
            }
        }

        if ($aggregatePassed < $policy['min_pass']) {
            $reasons[] = 'below_min_pass';
        }

        $comparison = self::compareBaseline($results, $graders, $policy['repeats'], $baseline);
        foreach ($comparison['regressions'] as $id) {
            $reasons[] = 'regression:' . $id;
        }
        foreach ($comparison['changed_cases'] as $id) {
            $reasons[] = 'case_changed:' . $id;
        }
        if ($comparison['incomparable']) {
            $reasons[] = 'baseline_incomparable';
        }

        sort($reasons, SORT_STRING);
        sort($advisoryErrors, SORT_STRING);

        return [
            'verdict' => $reasons === [] ? 'release' : 'block',
            'reasons' => $reasons,
            'suite_fingerprint' => $fingerprint,
            'grader_versions' => self::graderVersions($graders),
            'repeats' => $policy['repeats'],
            'aggregate' => [
                'cases' => $aggregateTotal,
                'passed' => $aggregatePassed,
                'min_pass' => $policy['min_pass'],
            ],
            'regressions' => $comparison['regressions'],
            'known_failures' => $comparison['known_failures'],
            'changed_cases' => $comparison['changed_cases'],
            'new_cases' => $comparison['new_cases'],
            'removed_cases' => $comparison['removed_cases'],
            'advisory_errors' => $advisoryErrors,
            'empty_outputs' => $emptyOutputs,
            'cases' => $results,
        ];
    }

    /**
     * Builds the baseline document a person commits after reviewing a run.
     * Accepting a baseline is how a changed case, a new grader version or a
     * known failure becomes the reference for later runs. It refuses a report
     * whose suite was not pinned, because that baseline would describe a set
     * nobody reviewed, and a report with any missing output or blocking grader
     * error, because an outcome that was never measured must not become a
     * known failure. Genuinely evaluated failures can still be accepted.
     */
    public static function acceptBaseline(array $report, string $run): array
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $run) !== 1) {
            throw new RuntimeException('invalid_run');
        }
        if (in_array('suite_fingerprint_mismatch', $report['reasons'] ?? [], true)) {
            throw new RuntimeException('unpinned_suite');
        }
        foreach ($report['cases'] as $case) {
            if (!in_array($case['status'], self::BASELINE_STATUSES, true)) {
                throw new RuntimeException('unmeasured_case');
            }
        }

        $cases = [];
        foreach ($report['cases'] as $id => $case) {
            $cases[$id] = ['fingerprint' => $case['fingerprint'], 'status' => $case['status']];
        }

        $baseline = [
            'run' => $run,
            'repeats' => $report['repeats'],
            'grader_versions' => $report['grader_versions'],
            'cases' => $cases,
        ];
        self::assertBaseline($baseline);

        return $baseline;
    }

    /**
     * Returns true when a sample contains only the whitespace and zero-width
     * characters listed at EMPTY_PATTERN. It does not claim to detect every
     * character a reader cannot see. Invalid UTF-8 is not treated as empty; it
     * is graded as bytes.
     */
    public static function isEmptyOutput(string $sample): bool
    {
        return preg_match(self::EMPTY_PATTERN, $sample) === 1;
    }

    /**
     * Recursively key-sort associative arrays so an expectation serialises the
     * same way however a JSON editor happened to order its keys. Lists keep
     * their order because position can be meaningful.
     */
    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = self::canonical($item);
        }
        if (!array_is_list($out)) {
            ksort($out, SORT_STRING);
        }

        return $out;
    }

    /** One case as a structure, so no field boundary depends on a separator byte. */
    private static function canonicalCase(string $id, array $case): array
    {
        $graders = $case['graders'];
        sort($graders, SORT_STRING);

        return [
            'expect' => self::canonical($case['expect']),
            'graders' => $graders,
            'id' => $id,
            'input' => $case['input'],
            'tier' => $case['tier'],
        ];
    }

    private static function json(mixed $value): string
    {
        // json_encode() writes floats with the serialize_precision setting, so a
        // php.ini that changes it would change the fingerprint of any case that
        // has a float in its expectation. Pin it to the shortest round-trip form.
        $precision = ini_set('serialize_precision', '-1');
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
            );
        } catch (JsonException) {
            // Invalid UTF-8 or a value JSON cannot represent.
            throw new RuntimeException('invalid_case');
        } finally {
            if ($precision !== false) {
                ini_set('serialize_precision', $precision);
            }
        }
    }

    /** @return array<string, array{tier: string, input: string, expect: array, graders: list<string>}> */
    private static function normaliseSuite(array $suite): array
    {
        if ($suite === [] || count($suite) > self::MAX_CASES) {
            throw new RuntimeException('invalid_suite');
        }

        $cases = [];
        foreach ($suite as $case) {
            if (!is_array($case)
                || !is_string($case['id'] ?? null)
                || preg_match(self::ID_PATTERN, $case['id']) !== 1
                || !in_array($case['tier'] ?? null, self::TIERS, true)
                || !is_string($case['input'] ?? null)
                || $case['input'] === ''
                || !is_array($case['expect'] ?? null)
                || !is_array($case['graders'] ?? null)
                || $case['graders'] === []
                || array_is_list($case['graders']) !== true
            ) {
                throw new RuntimeException('invalid_case');
            }
            foreach ($case['graders'] as $name) {
                if (!is_string($name) || $name === '') {
                    throw new RuntimeException('invalid_case');
                }
            }
            if (count($case['graders']) !== count(array_unique($case['graders']))) {
                throw new RuntimeException('invalid_case');
            }
            if (isset($cases[$case['id']])) {
                throw new RuntimeException('duplicate_case');
            }
            $cases[$case['id']] = [
                'tier' => $case['tier'],
                'input' => $case['input'],
                'expect' => $case['expect'],
                'graders' => $case['graders'],
            ];
        }

        // Stable, id-ordered reports so two runs produce a comparable diff.
        uksort($cases, static fn(string $a, string $b): int => strcmp($a, $b));

        return $cases;
    }

    private static function assertGraders(array $graders, array $cases): void
    {
        if ($graders === []) {
            throw new RuntimeException('invalid_graders');
        }
        foreach ($graders as $name => $grader) {
            if (!is_string($name)
                || !is_array($grader)
                || !is_string($grader['version'] ?? null)
                || $grader['version'] === ''
                || !is_bool($grader['blocking'] ?? null)
                || !is_callable($grader['fn'] ?? null)
            ) {
                throw new RuntimeException('invalid_graders');
            }
        }
        foreach ($cases as $case) {
            foreach ($case['graders'] as $name) {
                if (!isset($graders[$name])) {
                    throw new RuntimeException('unknown_grader');
                }
            }
        }
    }

    private static function assertPolicy(array $policy, array $graders, array $cases): void
    {
        $aggregateCases = 0;
        foreach ($cases as $case) {
            if ($case['tier'] === 'aggregate') {
                ++$aggregateCases;
            }
        }
        // A threshold the suite can never meet is a configuration defect, not a
        // release decision, so it throws instead of blocking every run.
        if (!is_string($policy['suite_fingerprint'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/D', $policy['suite_fingerprint']) !== 1
            || !in_array($policy['repeats'] ?? null, self::REPEAT_RULES, true)
            || !is_int($policy['min_pass'] ?? null)
            || $policy['min_pass'] < 0
            || $policy['min_pass'] > $aggregateCases
            || !is_array($policy['grader_versions'] ?? null)
        ) {
            throw new RuntimeException('invalid_policy');
        }

        $pinned = $policy['grader_versions'];
        ksort($pinned);
        $actual = self::graderVersions($graders);
        if ($pinned !== $actual) {
            throw new RuntimeException('grader_version_mismatch');
        }
    }

    /**
     * Every baseline entry is validated, including entries for cases that are
     * no longer in the suite, before any comparison is made.
     */
    private static function assertBaseline(array $baseline): void
    {
        if (!is_string($baseline['run'] ?? null)
            || $baseline['run'] === ''
            || !in_array($baseline['repeats'] ?? null, self::REPEAT_RULES, true)
            || !is_array($baseline['grader_versions'] ?? null)
            || $baseline['grader_versions'] === []
            || !is_array($baseline['cases'] ?? null)
            || ($baseline['cases'] !== [] && array_is_list($baseline['cases']))
        ) {
            throw new RuntimeException('invalid_baseline');
        }
        foreach ($baseline['grader_versions'] as $name => $version) {
            if (!is_string($name) || !is_string($version) || $version === '') {
                throw new RuntimeException('invalid_baseline');
            }
        }
        foreach ($baseline['cases'] as $id => $entry) {
            $keys = is_array($entry) ? array_keys($entry) : [];
            sort($keys, SORT_STRING);
            if (!is_string($id)
                || preg_match(self::ID_PATTERN, $id) !== 1
                || $keys !== ['fingerprint', 'status']
                || !is_string($entry['fingerprint'])
                || preg_match('/\A[0-9a-f]{64}\z/D', $entry['fingerprint']) !== 1
                || !in_array($entry['status'], self::BASELINE_STATUSES, true)
            ) {
                throw new RuntimeException('invalid_baseline');
            }
        }
    }

    /** @return array<string, string> */
    private static function graderVersions(array $graders): array
    {
        $versions = [];
        foreach ($graders as $name => $grader) {
            $versions[$name] = $grader['version'];
        }
        ksort($versions);

        return $versions;
    }

    private static function gradeCase(
        string $id,
        array $case,
        ?array $samples,
        array $graders,
        string $repeats
    ): array {
        // A mandatory case cannot be satisfied by a majority that includes a failure.
        $rule = $case['tier'] === 'must_not_fail' ? 'all' : $repeats;

        if ($samples === null) {
            return [
                'tier' => $case['tier'],
                'repeats' => $rule,
                'status' => 'missing',
                'samples' => 0,
                'passed_samples' => 0,
                'empty_samples' => 0,
                'graders' => [],
            ];
        }

        if ($samples === []
            || array_is_list($samples) !== true
            || count($samples) > self::MAX_SAMPLES
        ) {
            throw new RuntimeException('invalid_output');
        }
        foreach ($samples as $sample) {
            if (!is_string($sample) || strlen($sample) > self::MAX_OUTPUT_BYTES) {
                throw new RuntimeException('invalid_output');
            }
        }

        $records = [];
        $passedSamples = 0;
        $emptySamples = 0;
        $graderError = false;

        foreach ($samples as $index => $sample) {
            // An empty reply fails before any grader sees it. A forbids-style
            // grader would otherwise pass it, since silence contains nothing forbidden.
            if (self::isEmptyOutput($sample)) {
                ++$emptySamples;
                $records[] = [
                    'case' => $id,
                    'sample' => $index,
                    'grader' => null,
                    'version' => null,
                    'blocking' => true,
                    'pass' => false,
                    'score' => 0.0,
                    'reason' => 'empty_output',
                ];
                continue;
            }

            $sampleBlocked = false;
            foreach ($case['graders'] as $name) {
                $grader = $graders[$name];
                $record = [
                    'case' => $id,
                    'sample' => $index,
                    'grader' => $name,
                    'version' => $grader['version'],
                    'blocking' => $grader['blocking'],
                ];
                // Each call gets its own copies. A grader that takes its arguments
                // by reference changes only these, never the case used for the
                // decision or the input seen by the next grader.
                $caseCopy = $case;
                $sampleCopy = $sample;
                try {
                    $verdict = ($grader['fn'])($caseCopy, $sampleCopy);
                    if (!is_array($verdict)
                        || !is_bool($verdict['pass'] ?? null)
                        || !is_float($verdict['score'] ?? null)
                        || !is_finite($verdict['score'])
                        || $verdict['score'] < 0.0
                        || $verdict['score'] > 1.0
                        || !is_string($verdict['reason'] ?? null)
                    ) {
                        throw new RuntimeException('invalid_grader_result');
                    }
                } catch (Throwable $e) {
                    $records[] = $record + [
                        'pass' => false,
                        'score' => 0.0,
                        'reason' => 'grader_error',
                        'error' => $e->getMessage(),
                    ];
                    // A blocking grader that cannot produce a verdict never yields
                    // a pass. An advisory one is reported and has no effect.
                    if ($grader['blocking']) {
                        $graderError = true;
                        $sampleBlocked = true;
                    }
                    continue;
                }

                $records[] = $record + [
                    'pass' => $verdict['pass'],
                    'score' => $verdict['score'],
                    'reason' => $verdict['reason'],
                ];
                if ($grader['blocking'] && $verdict['pass'] !== true) {
                    $sampleBlocked = true;
                }
            }
            if (!$sampleBlocked) {
                ++$passedSamples;
            }
        }

        $total = count($samples);
        $satisfied = $rule === 'all'
            ? $passedSamples === $total
            : $passedSamples * 2 > $total;

        return [
            'tier' => $case['tier'],
            'repeats' => $rule,
            'status' => $graderError ? 'grader_error' : ($satisfied ? 'pass' : 'fail'),
            'samples' => $total,
            'passed_samples' => $passedSamples,
            'empty_samples' => $emptySamples,
            'graders' => $records,
        ];
    }

    private static function compareBaseline(array $results, array $graders, string $repeats, ?array $baseline): array
    {
        if ($baseline === null) {
            return [
                'regressions' => [],
                'known_failures' => [],
                'changed_cases' => [],
                'new_cases' => array_keys($results),
                'removed_cases' => [],
                'incomparable' => false,
            ];
        }

        $recorded = $baseline['grader_versions'];
        ksort($recorded);
        // A changed grader measures a different thing, and a changed repeat rule
        // decides a case differently, so old outcomes are not evidence about this run.
        $incomparable = $recorded !== self::graderVersions($graders)
            || $baseline['repeats'] !== $repeats;

        $regressions = [];
        $knownFailures = [];
        $changed = [];
        $newCases = [];
        $removed = array_values(array_diff(array_keys($baseline['cases']), array_keys($results)));
        sort($removed, SORT_STRING);
        foreach ($results as $id => $result) {
            if (!array_key_exists($id, $baseline['cases'])) {
                $newCases[] = $id;
                continue;
            }
            $previous = $baseline['cases'][$id];
            // Same id, different meaning. Neither its old pass nor its old failure
            // says anything about this case, so it blocks until a person accepts
            // a new baseline for it.
            if (!hash_equals($previous['fingerprint'], $result['fingerprint'])) {
                $changed[] = $id;
                continue;
            }
            // An incomparable baseline is not evidence either way, so nothing is
            // classified as a regression or excused as a known failure against it.
            if ($result['status'] === 'pass' || $incomparable) {
                continue;
            }
            if ($previous['status'] === 'pass') {
                $regressions[] = $id;
                continue;
            }
            $knownFailures[] = $id;
        }

        return [
            'regressions' => $regressions,
            'known_failures' => $knownFailures,
            'changed_cases' => $changed,
            'new_cases' => $newCases,
            'removed_cases' => $removed,
            'incomparable' => $incomparable,
        ];
    }
}
