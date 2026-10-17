# Clearance

> **Beta (v0.1.0, unreleased). API may change.** Check who can reach your Laravel
> routes, verify access with real requests, commit the result, and fail CI when
> access widens.

Clearance builds an **access matrix** for your app: configured routes × personas
(guest, customer, staff, admin…). It calls routes through your test bootstrap as
each persona, using owned and foreign records where model bindings are resolved.
You commit the matrix. On each pull request, Clearance regenerates it and blocks
unapproved widening, guard removal, policy regressions, or lost verification coverage.

An agent or a teammate adds a controller, the happy-path test passes, and nobody
notices the `authorize()` call is missing. Clearance makes that access visible.

- [MIT licensed](LICENSE) · Laravel 12 & 13 · PHP 8.3–8.5 · Pest 3/4 or PHPUnit 11/12.
- Dynamic requests run only in the **testing** environment, with middleware enabled.
- Clearance observes access; it never grants application permissions.

## Install

Once the beta is available through your Composer repository:

```bash
composer require dgtlss/clearance --dev
php artisan clearance:install
```

For an unpublished local checkout, configure a Composer `path` repository pointing
to it and require `dgtlss/clearance:@dev` instead. The service provider is
auto-discovered. The installer creates `config/clearance.php` and
`app/Clearance/Personas.php` without overwriting existing files.

Your app needs Pest 3/4 or PHPUnit 11/12, a working `tests/TestCase.php`, enabled
middleware, and a disposable testing database. Prepare its schema using your
normal test setup before verifying access. The default `DatabaseTransactions`
trait does not run migrations.

## Define your personas

Edit `app/Clearance/Personas.php`. Persona definitions belong in a class extending
`Dgtlss\Clearance\Personas`, not a file returning an array. Adapt the example's
`role` attributes to your own authorization fields or factory states.

```php
<?php

namespace App\Clearance;

use App\Models\Order;
use App\Models\User;
use Dgtlss\Clearance\Persona;
use Dgtlss\Clearance\Personas as BasePersonas;
use Dgtlss\Clearance\World;

class Personas extends BasePersonas
{
    public function define(): array
    {
        return [
            Persona::guest(),
            Persona::make('customer')
                ->user(fn () => User::factory()->create(['role' => 'customer'])),
            Persona::make('staff')
                ->user(fn () => User::factory()->create(['role' => 'staff']))
                ->guard('web'),
            Persona::make('admin')
                ->user(fn () => User::factory()->create(['role' => 'admin']))
                ->expectAll(),
        ];
    }

    public function ownership(World $world): void
    {
        $world->owns(Order::class, fn (User $user) =>
            Order::factory()->create(['user_id' => $user->getKey()]));
    }
}
```

Factories run separately for each scenario and roll back afterward. `guard()`
selects an authentication guard. Without a configured persona class, the inventory
contains only a guest persona.

`expectAll()` exempts an intentionally unrestricted persona from access-widening
checks once that exemption is in the baseline. It does not grant permissions or
assert that every request succeeds. Adding a new exemption blocks against an
existing baseline. Changes to persona definitions or exemptions need an explicit
baseline migration; individual route approvals cannot perform that migration.

## Generate your first matrix

```bash
php artisan clearance:scan        # Static signals; no route requests
php artisan clearance:verify      # Static signals plus real test requests
```

For each route, Clearance records:

- **Runtime inventory:** method, URI, domain, name, action, global middleware, and
  the expanded route middleware stack.
- **Static signals:** controller `authorize()` and Gate calls, FormRequest
  `authorize()`, policies, and owner-query or model-scope heuristics.
- **Verified access:** `allow`, `deny`, `unverified`, or `error` per persona,
  including owned and foreign scenarios for resolvable model bindings.

Missing controller classes or methods produce actionable diagnostics and an
`unknown` static verdict. They are not treated as safe or as a scanner crash.

Verification reports scenario and HTTP-response counts, then groups incomplete
checks by cause: ownership, related bindings, route parameters, redirects,
ambiguous responses, server errors, and test setup. Each group includes a next
step. JSON output includes `summary` and `findings`; `clearance:explain` shows the
category, status, and reason for each scenario.

## Set and approve a baseline

```bash
php artisan clearance:lock
```

This verifies access and writes two files by default:

- `clearance.lock.json`: canonical JSON with sorted map keys and no generation timestamp.
- `CLEARANCE.md`: a readable route × persona matrix.

The paths are configurable through `clearance.lock_path` and
`clearance.markdown_path`. `clearance:check` never modifies these files.

Read `CLEARANCE.md`. A baseline records current access; it does not make every
existing grant appropriate. Static findings remain visible. Fix surprising
access, or record its intended scope with an explicit approval:

```bash
php artisan clearance:approve 'PUT orders/{order}' --persona=staff \
  --by=reviewer --reason='Staff can edit any order (OPS-112)'
```

`route` is an exact discovered route identifier. `--reason` must be non-empty.
`--by` sets the recorded reviewer; it defaults to `GITHUB_ACTOR` or the PHP script
owner. The reviewer field is review evidence, not identity verification.

`--persona=staff` limits widening approval to that persona and refuses guard
changes or widening for other personas on the selected route. Omit `--persona`
to approve the whole route. Other routes are left unchanged.

Approvals record the reason, reviewer, UTC time, and a canonical hash of the
route's guards and access. A subsequent change invalidates that hash;
`clearance:lock` refuses to silently discard the approval. Use
`clearance:approve` again with a reviewed reason.

Reports keep reviewed access visible as informational findings, with its reason.
A persona approval applies only to that persona. An exact, satisfied `Expect`
declaration also marks intended foreign access as informational. Unreviewed
authenticated foreign access is high priority for review; guest foreign access
and foreign access explicitly expected to be denied are critical. These are
review priorities, not proof of an exploit. Stale approvals cannot downgrade a
finding, and expectation mismatches still fail verification.

For an explicitly approved first baseline, use this instead of `clearance:lock`:

```bash
php artisan clearance:approve --all-new --by=reviewer --reason='Initial baseline'
```

`--all-new` requires no existing lockfile and cannot be combined with a route or
`--persona`. Commit and review both generated files:

```bash
git add clearance.lock.json CLEARANCE.md
git commit -m 'Clearance baseline'
```

## Gate pull requests in CI

```bash
php artisan clearance:check
```

By default, the check blocks:

- **Widening:** a non-exempt persona gains access, including on a new route.
- **Guard removal:** an authorization middleware, check, policy, or recorded
  scoping signal disappears or changes. Throttle and signed middleware are not scored.
- **Policy regression:** a previously instance-aware policy stops referencing
  the model instance.
- **Lost coverage:** a persona, owned/foreign dimension, or previously verified
  scenario disappears; a registered route is excluded; or verification scope changes.

Actual removal of a route from the application's full inventory is narrowing
and does not block. Hiding a registered route behind a filter or exclusion loses
coverage and blocks. Errors or unverified outcomes fail when they exceed
`clearance.gate.max_errors` (default `0`). Explicit `Expect` mismatches fail even
when that error threshold is raised. Losing a previously verified result also
blocks as coverage loss regardless of the error threshold. The scored change types are configured in
`clearance.gate.fail_on`.

The lock records the full registered inventory and normalized `include`,
`exclude`, `dynamic.methods`, and `dynamic.read_only` settings. Review intentional
scope changes explicitly:

```bash
php artisan clearance:approve --coverage --by=reviewer \
  --reason='This webhook has dedicated host tests (SEC-42)'
```

This records the selected scope and removes excluded baseline rows. It does not
approve widening, removed guards, or lost scenarios on retained routes. Review
those routes separately first. It cannot be combined with a route, `--persona`,
or `--all-new`. The default error threshold still requires all selected scenarios
to be verified. Exclusions are visible gaps, not evidence that the excluded
surface is safe. A schema-1 lock from before scope metadata was added uses this
same review command to migrate; existing route approvals are preserved.

| Exit | Meaning |
| --- | --- |
| 0 | Check passed; narrowing may be reported |
| 1 | Unapproved widening, guard removal, instance-awareness regression, or lost coverage |
| 2 | Verification failure, errors/unverified outcomes above the threshold, expectation mismatch, or invalid command configuration |
| 3 | Missing, malformed, or unsupported lockfile |

Add the check to your existing test job after preparing its testing database and
services. This standalone example uses a disposable SQLite file so migrations
and the generated test subprocess share the same schema. Apps that require
MySQL, PostgreSQL, or other services should use their normal testing setup.

```yaml
# .github/workflows/clearance.yml in the host application
name: Clearance
on: [pull_request]

permissions:
  contents: read

jobs:
  clearance:
    runs-on: ubuntu-latest
    env:
      APP_ENV: testing
      DB_CONNECTION: sqlite
      DB_DATABASE: ${{ github.workspace }}/database/clearance-testing.sqlite
      CACHE_STORE: array
      SESSION_DRIVER: array
      MAIL_MAILER: array
      QUEUE_CONNECTION: sync
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: mbstring, pdo_sqlite
          coverage: none
      - run: composer install --no-interaction --prefer-dist
      - name: Prepare testing database
        run: |
          cp .env.example .env
          touch database/clearance-testing.sqlite
          php artisan key:generate
          php artisan migrate --force
      - name: Check access matrix
        run: php artisan clearance:check
```

`clearance:check` emits escaped GitHub annotations automatically when
`GITHUB_ACTIONS` is set, controlled by `clearance.report.github_annotations`.
There is no annotation CLI switch. `--json` emits JSON instead of console output
and annotations.

For legitimate widening, run `clearance:approve` locally and commit the updated
lock and Markdown matrix. **Reviewing that diff is the approval.**

## Commands

| Command | Purpose |
| --- | --- |
| `clearance:install` | Install config and the persona stub without overwriting files; print a CI step |
| `clearance:scan --json --route='orders.*' --only=risky` | Inspect static profiles without route requests |
| `clearance:verify --json` | Run persona scenarios and print the matrix |
| `clearance:lock --json` | Write JSON and Markdown; refuse unapproved widening |
| `clearance:check --json` | Compare verification with the committed baseline |
| `clearance:approve` | Approve one verified route, the first baseline, or scope changes with `--coverage` |
| `clearance:explain 'PUT orders/{order}' --json` | Show guards and request observations; `--dry-static` skips requests |
| `clearance:personas --json` | List personas; `--validate` also runs route verification through the test harness |

Identifiers use `METHOD uri`, including separate GET/HEAD entries. Domain routes
use `METHOD [domain] uri`. `--route` accepts name, URI, or identifier globs on
`clearance:scan`; approvals require an exact identifier.

## Scenarios and write payloads

Ownership defaults to a model factory with a `user_id` or `owner_id` column.
Configure `World::owns()` for other ownership rules. Unknown ownership or route
parameters leave requests **unverified**.

An ownership factory also receives previously built models keyed by route
parameter. Use them to keep nested fixtures related:

```php
// For orders/{order}/lines/{line}, after registering the Order ownership factory:
$world->owns(OrderLine::class, fn (User $owner, array $parents) =>
    OrderLine::factory()->create(['order_id' => $parents['order']->getKey()]));
```

This supplies fixtures; Laravel still performs its real scoped binding and
authorization. Arbitrary custom binders and tenancy need explicit app setup.
With Spatie Permission, assign the app's actual roles/permissions in persona
factories and create those definitions in the host test setup. Declare or approve
legitimate role-wide access per route instead of exempting an entire staff persona.

In `config/clearance.php`, override payloads and non-model parameters by exact
route identifier:

```php
'dynamic' => [
    // Keep the other published defaults.
    'payloads' => [
        'PUT orders/{order}' => ['title' => 'A valid order title'],
    ],
    'parameters' => [
        'GET reports/{period}' => ['period' => '2026-10'],
    ],
],
```

A parameter callback receives `($user, $scope, $world)`, where `$scope` is `owned`
or `foreign`. Payload callbacks receive the scenario request. Controller method
attributes are also supported:

```php
use Dgtlss\Clearance\Attributes\Expect;
use Dgtlss\Clearance\Attributes\Payload;

#[Payload(['title' => 'A valid order title'])]
#[Expect(guest: 'deny', customer: ['owned' => 'allow', 'foreign' => 'deny'])]
public function update(UpdateOrderRequest $request, Order $order): array
{
    $this->authorize('update', $order);
    $order->update($request->validated());

    return ['ok' => true];
}
```

The minimal payload generator handles common required fields. A 422 counts as
allowed when no validation-before-authorization hazard is detected. If validation
precedes a visible controller authorization call, it stays unverified; supply a
valid payload to verify the later check. 401/403, recognized login redirects on
the request's origin, and 404s for bound foreign records are denials. Other
redirects stay unverified, including redirects to `/` used by custom guards and
successful post-save redirects. A 404 without a bound foreign record is also
unverified. 5xx responses are errors; 419/429 and other ambiguous responses remain
unverified. Unknown or errored cells never become denials.

## Test environment and side effects

Artisan launches the app's Pest executable, or PHPUnit when Pest is absent, with
a temporary generated test. The app's `phpunit.xml`, `.env.testing`, and base test
case apply. The default `clearance.dynamic.test_case` is `Tests\TestCase`;
`clearance.dynamic.database_trait` defaults to `DatabaseTransactions`. Configure
it as `Illuminate\Foundation\Testing\RefreshDatabase` if the host needs migrations.

Each scenario wraps the default database connection in a transaction and rolls
it back. Add secondary connection names to `clearance.dynamic.connections`.
Mail, notifications, queues, events, and configured storage disks are faked;
unstubbed Laravel HTTP client requests are blocked.

Use isolated testing databases, disks, and credentials. Set
`clearance.dynamic.read_only` to route globs to skip write requests; those cells
remain unverified. Static scans issue no route requests, but bootstrapping the
host app and resolving controller middleware can execute host initialization code.

## Optional: Warden adapter

If [Warden](https://github.com/dgtlss/warden) with the
`Dgtlss\Warden\Contracts\CustomAudit` API is installed, Clearance automatically
registers `Dgtlss\Clearance\Integrations\WardenAudit` in `warden.custom_audits`.
There is no need to register it manually. Set `clearance.report.warden` to `false`
to opt out.

```bash
php artisan warden:audit --only=clearance
```

Unapproved widening and the other blocking matrix changes block the audit.
Current baseline findings remain advisory. Findings carry **OWASP A01 (Broken
Access Control)** metadata. Verification failures are reported as failed audits.
Warden is optional; Clearance works without it.

## What it can't prove

Clearance provides evidence for the supplied scenarios, not a guarantee:

- **A check present does not prove it is enforced.** Static analysis finds calls,
  not proof that every code path uses them. Verification covers defined inputs,
  roles, model states, and relationships, not every possible combination.
- **Ownership needs configuration.** Typed Eloquent bindings are supported;
  arbitrary custom binders and nested ownership have limited support. A foreign
  scenario uses another freshly created owner, not every possible outsider.
- **Redirects and validation can be ambiguous.** Redirects other than recognized
  local login destinations stay unverified, whether they follow a successful
  action or a custom guard's rejection. Use host feature tests to establish that
  boundary. Hidden validation/authorization control flow may need explicit payloads
  and feature tests as well.
- **Routes only.** Internal Livewire actions and Filament resources are not
  inventoried. Their integrations remain roadmap work.
- **No OWASP Advisor scoring.** The Warden adapter provides A01 metadata only.
- **Side effects have limits.** Transactions cover only the default and configured
  connections. DDL, manual commits, direct filesystem writes, raw sockets, and
  external SDKs that bypass Laravel's HTTP client can escape rollback or fakes.
- **No hard per-request timeout.** `clearance.dynamic.timeout_ms` (default `2000`)
  marks slow completed scenarios, including setup, as errors. The test subprocess is
  bounded by `clearance.dynamic.process_timeout` (default `300` seconds); a hung
  request can stall that subprocess until its timeout.
- **No deployed-site testing or runtime enforcement.** This is an in-process
  testing tool, not a pentest or a WAF.

See [implementation status](docs/implementation.md) for the current coverage.

## Development

```bash
composer install
composer test
composer test:acceptance
composer lint
composer validate --strict
```

The suite uses SQLite fixtures with owner/outsider policies and intentionally
vulnerable routes. The repository defines six PHP/Laravel CI combinations across
PHP 8.3–8.5 and Laravel 12–13; configured jobs are not a claim that a release's CI
has passed. See the [release checklist](.github/RELEASE_CHECKLIST.md).

The acceptance command creates a temporary Laravel app, installs the local
package through Composer, and uses real Spatie roles. It checks both Pest and
PHPUnit, nested ownership, mutation rollback, stable baseline files, deliberate
authorization removal, approvals, and scope changes. It requires network access
for Composer; failed fixtures are retained at the printed temporary path.

Report vulnerabilities in Clearance through the private channel in
[SECURITY.md](SECURITY.md).

## Roadmap (not in v0.1)

- **v1.x/v2:** replay recording from existing tests, SARIF, sticky PR comments or a
  GitHub Action, Livewire/Filament collectors, tenancy, Sanctum/Passport token
  dimensions, parallel runs, and field-level exposure.
- **Pro (separate):** history, compliance PDFs, dashboards, organization policies,
  and scheduled drift runs.

[SPEC.md](SPEC.md) is the draft product and technical guide; roadmap items are not
implemented features.
