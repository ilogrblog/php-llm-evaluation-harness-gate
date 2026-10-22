# How the gate decides

Reference for the contracts behind `EvalGate.php`, `gate.php` and `accept.php`. The [README](README.md) covers running the example.

## Changing the suite, a grader or an accepted failure

`accept.php` writes a candidate baseline next to the accepted one and never
touches the accepted file:

```sh
php accept.php suite/support-reply.outputs.json run-a
diff -u suite/support-reply.baseline.json suite/support-reply.baseline.candidate.json
mv suite/support-reply.baseline.candidate.json suite/support-reply.baseline.json
```

It writes exactly one file, `suite/support-reply.baseline.candidate.json` (or the same name inside the folder named by `EVAL_SUITE_DIR`), and removes an older file of that name. A person runs it, reviews the diff, replaces the baseline and commits it alongside
any golden-set or policy change. That is the only way a changed case stops
blocking. Do not redirect its output over the baseline file: a shell redirect
empties the target before the script runs, so a refused acceptance would destroy
the accepted baseline.

**Continue to the diff and replacement only after `accept.php` exits 0.** Before
it does anything else, including checking its arguments, it removes any candidate
left from an earlier run, symlinks included. The new candidate is written to a
temporary file and renamed into place only after acceptance succeeds. On exit 2
there is no candidate: wrong arguments, a golden set whose fingerprint is not
pinned in the policy (`unpinned_suite`), or any case with a missing output or a
blocking grader error (`unmeasured_case`). If filesystem permissions stop it
removing an old candidate, it exits 2 with `cannot_remove_stale_candidate` and
says the remaining file is stale and must not be used. A genuinely evaluated
failure can be accepted, and it then counts as a known failure in later runs.

## Contracts

- The golden set, the graders, the policy and the baseline live in the repository.
  Nothing in a model response can add a case, change a tier, raise a threshold or
  set a verdict.
- `EvalGate::fingerprint()` hashes canonical JSON of every case's id, tier, input,
  expectation and grader list, ordered by id. Associative keys inside the
  expectation are sorted recursively and grader names are sorted, so reordering
  cases, keys or graders does not change it. List order inside an expectation is
  preserved, because position can matter. Because each case is serialised as a
  structure rather than joined with separator bytes, no value can forge a field
  or case boundary. Fields outside that list, such as a `note`, are deliberately
  not covered. Floats in an expectation are encoded with `serialize_precision` set to `-1` whatever
  `php.ini` says, so the same suite has the same fingerprint on every machine. It is a change detector against a pinned reference, not proof that
  the cases are well chosen.
- Case ids must start with a lowercase letter, followed by up to 63 lowercase
  letters, digits and hyphens. A numeric-only id would become an integer array key
  in PHP, so it is rejected as `invalid_case`.
- A grader is `['version' => string, 'blocking' => bool, 'fn' => callable]`. The
  callable returns `['pass' => bool, 'score' => float, 'reason' => string]` with the
  score a finite float in 0.0 to 1.0 inclusive. `NAN` and infinities are rejected
  explicitly, since `NAN` passes an ordinary range comparison. The score is
  reported, never thresholded by the gate.
- Every grader call receives its own copy of the case and the sample. A grader
  that declares its parameters by reference can change only its copies, never the
  tier, expectation or reply used by the next grader or by the decision.
- A blocking grader that throws, or returns anything outside that contract,
  produces the case status `grader_error`, which blocks. A grader that cannot
  reach a verdict must not be able to produce a pass.
- `blocking => false` registers an advisory grader. Its results are recorded and
  cannot affect the verdict. If an advisory grader throws or returns a malformed
  result, the record shows `grader_error` with the message, and the report lists
  it under `advisory_errors`, but the case status, the aggregate count and the
  baseline comparison are unchanged. The shipped `length` grader is advisory.
- An empty sample fails before any grader runs, with the record reason
  `empty_output`. Empty means nothing remains after removing Unicode whitespace
  (spaces, tabs, newlines, carriage returns, non-breaking and other Unicode
  spaces) and three zero-width characters: zero-width space, word joiner and byte
  order mark. Without this, a forbidden-phrase grader would pass an empty reply,
  because silence contains nothing forbidden. The rule does not detect every
  invisible character: a reply of only a left-to-right mark, zero-width joiner or
  bidirectional override is graded like any other text. Invalid UTF-8 is not
  treated as empty and is graded as bytes. Cases with an empty sample are listed
  under `empty_outputs`.
- A case with no recorded output is `missing`, which blocks with its own reason
  and is not counted as a pass in the aggregate denominator. An empty reply and a
  missing reply are different conditions and are reported differently.
- `must_not_fail` cases are evaluated individually. One failure blocks regardless
  of the aggregate tier. Aggregate cases are compared against `min_pass`, a count
  rather than a rounded percentage. A `min_pass` larger than the number of
  aggregate cases throws `invalid_policy`: a gate that can never release is a
  configuration defect.
- `repeats` is `all` or `majority`, applied per case across its recorded samples.
  `majority` needs strictly more than half, so an even split fails. It applies to
  aggregate cases only: a `must_not_fail` case always requires every sample to
  pass. Repeated samples expose variability; they do not make the feature
  deterministic.
- Every baseline entry is validated before comparison, including entries for cases
  no longer in the suite. Each entry must carry a 64-character fingerprint and a
  status of `pass` or `fail`. `missing` and `grader_error` are not valid baseline
  outcomes, because a case that was never measured must not become a known
  failure that excuses a later failing run. A `null` entry is invalid, not absent.
- A case whose id is in the baseline with the same fingerprint is compared: if it
  passed before and does not pass now it is a `regression` and blocks; if it
  already failed it is a `known_failure`, still counted against the aggregate
  threshold but not treated as new damage.
- A case whose id is in the baseline with a different fingerprint has changed
  meaning. It is listed under `changed_cases` and blocks with
  `case_changed:<id>`, whether it passes now or not, until a new baseline is
  accepted. It is never excused as a known failure or reported as new.
- A case whose id is absent from the baseline is `new` and cannot be a regression.
  A case present in the baseline but no longer in the suite is listed under
  `removed_cases`; it does not block on its own, because removing a case already
  changes the suite fingerprint and therefore needs the policy to be re-pinned.
- If the baseline's grader versions or repeat rule differ from the current ones,
  the run is `baseline_incomparable` and blocks, and no case is classified as a
  regression or a known failure against it.
- The policy must pin every registered grader version. A mismatch throws
  `grader_version_mismatch` rather than silently comparing across grader changes.
- Structural problems throw: `invalid_suite`, `invalid_case`, `duplicate_case`,
  `unknown_case`, `unknown_grader`, `invalid_graders`, `invalid_policy`,
  `grader_version_mismatch`, `invalid_output`, `invalid_baseline`, and from
  `acceptBaseline()`, `invalid_run`, `unpinned_suite` and `unmeasured_case`. Evaluation outcomes
  return a `block` verdict instead. A malformed harness is a defect; a failing
  case is a release decision.
- Reason codes are sorted and cases are reported in ascending id order, so two runs
  produce a readable diff. Two identical runs produce identical reports.
- The report gives counts, not a confidence figure. A curated golden set is not a
  random sample of customer traffic, so its pass rate describes the suite and
  estimates nothing about production.
- There is no force, override or skip parameter. A block is expressed as a non-zero
  exit code, and any decision to proceed anyway belongs in the release process where
  it can be recorded.

## Running it in CI

Make the job that runs `gate.php` a required status check, and make sure that job
always runs and fails when recorded outputs are absent or incomplete. A job skipped
by a condition reports success on GitHub, and a whole workflow skipped by a path or
branch filter leaves its required check pending, so the harness's own
`missing_output` rule only helps once the job has actually started.

`gate.php` evaluates the full suite. Running only the `must_not_fail` cases on every
pull request needs its own golden-set file, policy and baseline, because this runner
blocks on every aggregate case with no recorded output.

## What this example does not do

It does not call a model, sample production traffic, score groundedness, detect
prompt injection, measure tone, compare model versions, store history, authenticate
anyone or integrate with WordPress, Magento or a CI provider. The `length` grader is
a deterministic stand-in for the model-based grader a real suite would need, and it
is registered as advisory for that reason.

Two limits are demonstrated rather than fixed. `check.php` includes a reply that
contains the required phrase alongside a damaging instruction, and it releases.
It also shows that a reply made only of a left-to-right mark, zero-width joiner or
bidirectional override is not treated as empty. Both are in the suite so they
cannot be forgotten. Two further limits are documented only: `mb_stripos` does not
case-fold Turkish dotted I, and invalid UTF-8 in an output is graded as bytes.
