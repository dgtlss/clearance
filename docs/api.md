# V1 public API

This documents the 1.x public API and compatibility boundary. APIs not listed
here, including collectors, report implementation classes and request-runner
internals, can change. None of these APIs grants application permissions.

## Personas and ownership

Extend `Dgtlss\Clearance\Personas` and implement `define(): array` returning a
non-empty list of uniquely named `Persona` objects. Override
`ownership(World $world): void` to register model factories.

| API | Contract |
| --- | --- |
| `Persona::guest()` | Unauthenticated persona named `guest` |
| `Persona::make(string $name)` | Name matches `[a-z][a-z0-9_-]*` |
| `->user(Closure $factory)` | Factory returns an `Authenticatable`; called inside the scenario transaction |
| `->guard(string $guard)` | Select a configured Laravel guard; no token abilities are created |
| `->expectAll()` | Explicit exemption from widening, once recorded in the reviewed baseline; does not assert success |
| `World::owns(string $model, Closure $factory): void` | Factory receives `(Authenticatable $owner, array $parents)` and returns a persisted model of that class |

Parents are keyed by route parameter and ordered as the route's parameters.
Factories must establish valid ownership and related records. Foreign scenarios
use a separate owner from the same persona factory. Returning the same user for
both owners is unverified. Guest bound-record scenarios build a separate owner.

## Controller attributes

`Dgtlss\Clearance\Attributes\Payload` accepts an array of input values.
`Dgtlss\Clearance\Attributes\Expect` accepts named persona arguments, each either
`allow`/`deny` or `['owned' => 'allow', 'foreign' => 'deny']`. Both are method
attributes. A mismatched expectation fails verification independently of the
error budget. Use exact declared access for intentional role-wide permissions.

## Configuration

Published defaults in [config/clearance.php](../config/clearance.php) are the
authoritative configuration reference. Update older published configs during an
upgrade; Laravel merges only missing top-level keys.

| Keys | Meaning |
| --- | --- |
| `lock_path`, `markdown_path`, `personas` | Output paths and persona class |
| `routes.include`, `routes.exclude` | Globs matching names, URIs or full identifiers |
| `routes.admin_prefixes` | Administrative prefix heuristic for findings |
| `dynamic.enabled` | Enable runtime verification; disabling does not invent verified access |
| `dynamic.test_case`, `dynamic.database_trait` | Host test bootstrap and trait; default is `DatabaseTransactions` |
| `dynamic.methods`, `dynamic.read_only` | Executed verbs and write exclusions; omitted checks remain unverified |
| `dynamic.fake` | `mail`, `notifications`, `queue`, `events`, `http`, `storage` by default |
| `dynamic.connections` | Additional testing connection names; default connection is always included |
| `dynamic.timeout_ms`, `dynamic.process_timeout` | Slow completed scenario threshold and hard whole-process timeout |
| `dynamic.payloads` | Exact identifier → input array or `Closure(Request): array`; takes precedence over attributes and generated data |
| `dynamic.parameters` | Exact identifier → parameter map; values or `Closure($user, $scope, World $world)` |
| `detect.ability_middleware` | Ability aliases, including Spatie's role/permission aliases |
| `detect.custom_checks` | Static helper signatures such as `App\Acl@check` |
| `detect.detectors` | Container-resolved custom `CheckDetector` classes |
| `detect.livewire`, `detect.filament` | Optional collector enablement |
| `detect.livewire_components`, `detect.livewire_paths` | Explicit component classes and source paths |
| `detect.surfaces_include`, `detect.surfaces_exclude` | Static surface identifier/class globs |
| `gate.fail_on`, `gate.max_errors` | Blocking diff types and incomplete/error budget, default zero |
| `report.github_annotations`, `report.warden`, `report.owasp_advisor` | Output/integration switches |

The default blocking types are `widening`, `guard_removed`, `instance_blind`,
`coverage_lost`, `surface_added` and `static_changed`. Reducing them reduces the
gate's guarantees. Errors/unknowns never become denials. Even a larger error
budget cannot waive lost previously verified cells or explicit expectations.

## Custom static detector

Implement `Dgtlss\Clearance\Static\CheckDetector::detect(PhpParser\Node\Stmt\ClassMethod $method): array`.
Return a list of deterministic string signals. Register the class in
`detect.detectors`. Use portable identifiers, never timestamps or absolute paths.
Signals establish presence, not control flow or enforcement.

## Commands and matrix schema

All nine supported commands and options are listed in the [README](../README.md).
`clearance:check --baseline=<git ref>` reads the configured lock from the resolved
Git commit and compares current verification against it. The ref must already be
fetched, and the lock path must be inside the application's repository; monorepos
and custom lock paths are supported. Missing refs or invalid/missing baseline
locks fail with exit code `3`, without falling back to the checkout lock. With
this option, JSON also includes `baseline_ref` and `lock_changed`; checkout lock
changes are advisory and cannot approve changes against the trusted ref. Without
the option, `check` reads the checkout lock. Protect the baseline, workflow and
verification inputs as described in the [CI guide](../README.md#gate-pull-requests-in-ci).

Exact identifiers are `METHOD uri`, `METHOD [domain] uri`,
`livewire Class@method`, `filament panel:resource:Class`,
`filament panel:page:Class`, or
`filament panel:resource-page:ResourceClass@PageClass`.

Schema **2** retains route profiles under `routes`, with persona access cells
`allow`, `deny`, `unverified` or `error`. Bound authenticated scenarios have both
`owned` and `foreign`. Static profiles live under `surfaces`, with
`evidence: static`, empty `access`, and `guarded`/`unguarded`/`unknown` verdicts.
`inventory`/`surface_inventory` record discovery before filtering; `collectors`
records complete/unavailable/disabled/error status. `scope` records normalized
verification and collector settings.

Hashes bind canonical guard/access data. Route approvals retain schema-1 hashes;
static hashes additionally cover implementation evidence in the guard profile.
`coverage_approval` binds the normalized scope. Lists retain semantic ordering;
map keys are sorted and generation timestamps are omitted. Approval/migration
timestamps are intentional review metadata. Additive report fields are compatible;
readers should ignore unknown fields. An incompatible lock format requires a
schema change and explicit migration.

Exit codes are **0** pass, **1** unapproved matrix changes, **2** verification or
configuration failure, and **3** unusable baseline. JSON commands add `summary`
and `findings`; `check` adds `changes`. The committed lock excludes these report
fields. Reviewer text is recorded evidence, not authenticated identity.
