# PHP release gate for AI features

A small, dependency-free PHP release gate for an AI feature. It grades recorded replies against a versioned golden set, compares them with the last accepted run and exits non-zero when a release should be blocked. It never calls a model and makes no network requests.

It accompanies the iLogr article [An Evaluation Harness for AI Features in PHP: A Release Gate for CI](https://ilogr.com/evaluation-harness-ai-features-before-production/). More examples are at [github.com/ilogrblog](https://github.com/ilogrblog).

> **Before you use it:** this is example code, provided as is, without warranty of any kind. Run it on a development machine or a staging copy first, never as the only check on a live site, store or database, and keep a backup of anything you point it at. You use it entirely at your own risk. Nobody who wrote or published it accepts responsibility for any loss, damage or downtime.

## Requirements

- PHP 8.1 or later with the `mbstring` extension. 8.1 is the floor because the code uses `array_is_list()`. PHP 8.1 itself no longer receives security fixes, so prefer a branch listed as supported on [php.net](https://www.php.net/supported-versions.php) (8.2 to 8.5 when this was written).
- Nothing else: no Composer packages, credentials, provider calls or network access.

If the PHP version is too old or `mbstring` is missing, each script stops with a one-line message and exit code 2.

## Run it

From the repository folder:

```bash
php check.php
php gate.php
php gate.php suite/support-reply.outputs.regressed.json
```

| Command | Expected |
| --- | --- |
| `php check.php` | Every check prints `PASS`; the last line says how many passed; exit 0 |
| `php gate.php` | `verdict: RELEASE`, `aggregate tier: 7 of 8 passed (min 7)`, exit 0 |
| `php gate.php suite/support-reply.outputs.regressed.json` | `verdict: BLOCK`, also 7 of 8 passed, with `must_not_fail` and `regression` reasons for `refund-window` and `no-discount-invention`, exit 1 |

Both recorded runs pass 7 of 8 aggregate cases. The second is blocked anyway, because two cases that must never fail broke and both had passed in the baseline. That is the point of the example: the count did not move, the verdict did.

`gate.php` exits 0 on release, 1 on a block and 2 when the harness or its configuration is broken.

## Accepting a new baseline

`accept.php` is the only script that writes a file. It writes one candidate baseline next to the accepted one and never touches the accepted file:

```bash
php accept.php suite/support-reply.outputs.json run-a
diff -u suite/support-reply.baseline.json suite/support-reply.baseline.candidate.json
mv suite/support-reply.baseline.candidate.json suite/support-reply.baseline.json
```

Replace the baseline only after `accept.php` exits 0 and you have read the diff. [CONTRACTS.md](CONTRACTS.md) explains what it refuses and why.

By default every script reads the `suite/` folder. Set `EVAL_SUITE_DIR` to a folder holding the same five file names to use another suite.

## What is in this repository

| Path | What it is |
| --- | --- |
| `EvalGate.php` | The gate: fingerprints, two-tier evaluation, baseline comparison, baseline acceptance. |
| `Graders.php` | Two blocking graders (`contains`, `forbids`) and one advisory grader (`length`), each versioned. |
| `gate.php` | Command-line runner. |
| `accept.php` | Writes a candidate baseline for review. |
| `check.php` | The self-check, including command-line runs against scratch copies of the suite. |
| `suite/` | The ten-case golden set, the reviewed policy, two recorded runs and the accepted baseline, all synthetic. |
| `CONTRACTS.md` | The contracts the gate enforces, CI notes and what the example does not do. |

The recorded replies are synthetic and were written for the example. No provider was called and no customer data is involved.

## Tested versions

`php check.php`, `php gate.php` and the blocked run give the same results on PHP 8.1, 8.2, 8.3, 8.4 and 8.5 (official `php` Docker images, CLI), with all errors, warnings and deprecations enabled and none reported.

## Licence

[MIT](LICENSE). The licence file carries the usual no-warranty and no-liability wording. The code is not WordPress code, so MIT is used rather than the GPL.
