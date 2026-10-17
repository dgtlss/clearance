# V1 validation results

Validated on **17 October 2026**. Implementation reference: `274344c`, with
subsequent documentation updates merged into `main`.

## Security remediation

Independent source-review agents checked the package's request isolation,
classification, static inventory, approvals/gates and packaging boundaries.
Five findings were independently validated against the original source and
bounded offline fixtures, then reproduced again against the fixed code:

| Finding | Fixed result |
| --- | --- |
| Trait authorization bodies missing from Filament fingerprints | Real bodies and aliases resolve; changing the body blocks drift and invalidates the old approval |
| Anonymous Livewire action lost after moving it into a trait | The callable surface stays inventoried and removed guards block |
| Scoped principal reused between personas | Scopes, facade roots and route controllers reset; customer/guest requests use their own identities |
| Method spoofing recorded a verdict for an unexecuted route | Effective-method/route mismatches remain unverified |
| Inherited HTTP allowlist escaped the stray-request block | The clone has no inherited allowlist; configured fakes and the original client survive |

The follow-up review also checked inherited property markers and application
overrides of Livewire framework methods. Native Reflection/Livewire utility
regressions ensure removed `Locked` markers renew approval and callable overrides
remain covered. Anonymous trait/base property attribute conflicts report explicit
incomplete discovery because PHP versions differ on their precedence.

The original five observations were limited to synthetic review/matrix effects
and a dummy HTTP transport. No deployed endpoints or consumer data were used.
Detailed audit artifacts stay outside the distributed package. This agent review
does not substitute for human adoption feedback or an external audit.

## Compatibility and acceptance

Every row passed the full core suite, fresh-app acceptance through Pest and
PHPUnit, `composer lint` and `composer validate --strict`:

| PHP | Laravel | PHPUnit / Pest |
| --- | --- | --- |
| 8.3.35 | 12.70.0 | 11.5.56 / 3.8.7 |
| 8.3.35 | 13.37.0 | 11.5.56 / 3.8.7 |
| 8.4.26 | 12.70.0 | 11.5.56 / 3.8.7 |
| 8.4.26 | 13.37.0 | 12.5.33 / 4.7.8 |
| 8.5.11 | 12.70.0 | 11.5.56 / 3.8.7 |
| 8.5.11 | 13.37.0 | 12.5.33 / 4.7.8 |

Each core run executed 107 tests / 425 assertions with 12 expected skips for
absent optional dependencies. Each fresh app verified all 49 Spatie/ownership
scenarios and checked stable files, rollback, deliberate regressions, approvals
and lost-coverage protection. Native PHP 8.5.10 / Laravel 13 also passed.

Published optional packages passed separately:

- Laravel 12 / PHP 8.3, Livewire 3, Filament 4, Sanctum 4, Warden 2.2 and Advisor
  1.1: 107 tests / 524 assertions, no skips.
- Laravel 13 / PHP 8.5, Livewire 4, Filament 5, Sanctum 4 and Warden 2.2:
  107 tests / 507 assertions; only the two Advisor tests skipped, because its
  upstream package excludes Laravel 13.
- Focused integration checks passed with `--fail-on-skipped` on both combinations.
- MySQL 8.4 and PostgreSQL 17 each passed two-connection ownership/rollback tests
  on Laravel 12 and 13, ten assertions per engine/framework combination.

## Distribution and timing

`composer test:package` inspects a fresh Composer ZIP and installs that exact
artifact in a disposable Laravel app, running the acceptance workflow. The Git
export rules exclude development tools, tests, caches and private environment
files. `composer docs` builds the local static site and checks relative links.

The synthetic benchmark passed at **507 route entries / 3,549 scenarios in
10.258 seconds** on PHP 8.5.10 / Laravel 13.37.0 / SQLite. Dependencies were warm;
timing includes the test bootstrap and requests, excludes Composer downloads and
initial application setup, and is not a performance promise for every host app.
See [acceptance.md](acceptance.md) for repeatable commands and isolation limits.

## Hosted CI

All 12 jobs in the [Tests workflow](https://github.com/dgtlss/clearance/actions/runs/39074479930)
passed on `main` at `bef5d2c`: six core combinations, two optional integration
jobs, two SQL jobs, formatting and distribution checks. Release procedures are
recorded in the [release checklist](https://github.com/dgtlss/clearance/blob/main/.github/RELEASE_CHECKLIST.md).
