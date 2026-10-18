# Clearance — Product & Technical Spec

> **dgtlss/clearance** · Continuous proof of who can access what in your Laravel app.
> Author: Nathan Langer (dgtlss) · Oct 2026 · Targets Laravel 12–13, PHP 8.3–8.5

---

This is the original product draft, including illustrative APIs and later-roadmap
ideas. It is not the current API reference. See [implementation status](docs/implementation.md),
[public API](docs/api.md), and [v1 checklist](docs/v1-plan.md). Clearance uses
schema 2, a separate `surfaces` map for static evidence, nine commands including
`clearance:migrate`, and no replay, tenancy or token-ability API.

## 1. Problem & why now

Broken access control is #1 in the OWASP Top 10 (A01). In Laravel it usually looks mundane:

- a controller action that loads `Order::findOrFail($id)` with no `authorize()` call,
- a `FormRequest::authorize()` that does `return true;`,
- a route group that forgot `can:` middleware,
- a policy method that ignores the model instance (`return $user->isCustomer();`),
- a Livewire action or Filament resource that never calls a policy.

None of these break tests and all of them ship. **Why now:** a large and growing share of Laravel code is written by coding agents (Boost ships with new Laravel apps). Agents produce working CRUD fast, and the authorisation check is exactly what gets skipped, because the happy-path test passes without it. Code review doesn't scale to "did every one of 400 routes keep its check?".

Today you can either *scan* code (heuristic, false positives, can't see runtime behaviour) or *write tests* by hand (accurate but nobody writes the outsider test for every route). Nothing gives a team a **reviewed, versioned record of the app's access surface** that fails CI when it silently widens.

**Clearance's thesis:** treat access control like a lockfile. Generate it, commit it, review diffs, block unapproved widening.

## 2. Goals / non-goals

**Goals**
1. Produce a complete inventory of the access surface: every route (and Livewire/Filament action where feasible) × the checks that guard it.
2. *Verify* the inventory dynamically by actually calling routes as defined personas and recording allow/deny.
3. Commit the result as `clearance.lock.json` + `CLEARANCE.md`; diff on every PR.
4. Fail CI when access **widens** (a persona gains access, a guard disappears) unless explicitly approved.
5. Zero-config useful result in under 5 minutes on a typical app; precise results with ~20 lines of persona definition.
6. Fit the Warden / OWASP Advisor family (shared reporting, one CI step).

**Non-goals**
- Not a permissions/roles package (use spatie/laravel-permission, Bouncer, etc.). Clearance observes, it doesn't grant.
- Not a runtime WAF or production request blocker.
- Not a general SAST tool (OWASP Advisor / Warden cover that).
- Not a black-box pentest scanner against deployed URLs (v1 runs in-process only).
- No AI in the core verdict path. Results must be deterministic and reproducible. (Optional AI explanations may come later, off by default.)

## 3. How it works

Pipeline: **Discover → Analyse (static) → Verify (dynamic) → Lock → Diff/Gate → Report.**

### 3a. Static scan

Inputs: `Route::getRoutes()` (runtime, so it's always accurate, including package routes) plus PHP source parsed with `nikic/php-parser`.

For each route Clearance builds a **GuardProfile**:

| Signal | Source |
|---|---|
| Auth middleware | resolved middleware stack (`auth`, `auth:sanctum`, `verified`, custom aliases, groups expanded) |
| Ability middleware | `can:update,post`, `->can()` route macro, `Gate`-based custom middleware (configurable list) |
| Controller checks | AST: `$this->authorize()`, `Gate::allows/denies/authorize`, `$user->can/cannot`, `abort_unless(...can...)`, `#[Authorize]`-style attributes, `authorizeResource()` in constructor |
| FormRequest | type-hinted FormRequest's `authorize()` body classified as: `true` literal, `false`, policy call, custom expression, missing |
| Route-model binding | bound models + whether `scopeBindings()` / `->scoped()` is used |
| Query scoping | heuristic: `auth()->user()->orders()`, `where('user_id', ...)`, global scopes on the bound model |
| Policies | discovered via `Gate::policies()` + auto-discovery; each method classified **instance-aware** (references `$model`) vs **instance-blind** |
| Throttle / signed | `throttle`, `signed` middleware (reported, not scored) |

Each route gets a static verdict: `guarded`, `auth-only` (logged-in but no ability/ownership check on a route with a bound model → likely IDOR), `public`, `unknown` (closure / dynamic call we can't resolve). `unknown` is never silently treated as safe.

**Livewire (v3/v4):** enumerate components, public methods (actions) and `#[Locked]` props; detect `$this->authorize()` in actions; flag actions that mutate a model with no check. *Static only in MVP.*
**Filament (v4/v5):** enumerate resources/pages; check whether a policy exists for the resource model and which `can*` methods are overridden. Filament falls back to policies, so the main finding is "resource model has no policy / instance-blind policy". *Static only in MVP.*

### 3b. Dynamic verification

Static analysis says "there's a check". Dynamic verification proves **what the check actually does** for each persona.

1. Clearance runs inside the test framework (Pest 3/4 or PHPUnit 11/12) using `RefreshDatabase` or `DatabaseTransactions`. No live server, no real network.
2. For each persona, a **scenario** builds a world with factories: the persona user, plus *owned* and *foreign* instances of each bound model.
3. For each route × persona × {owned, foreign} instance, Clearance issues the request in-process (`$this->actingAs($user)->call(...)`), filling route params from the scenario.
4. Request bodies for write routes: built from the FormRequest `rules()` using a minimal valid-payload generator (required fields + type-appropriate values), overridable per route. If it can't build a valid payload, it still records whether the request got *past authorisation* (a 422 means authz passed, a 403 means denied). That distinction is the core trick: **for access purposes, 422 = allowed.**
5. Outcome classification: `allow` (2xx, or 422 when no validation-before-authorization hazard is detected), `deny` (401/403, 404 on a bound foreign record, recognized same-origin login redirect), `error` (5xx; recorded, never counted as deny). Other redirects, unbound/owned 404s, and ambiguous responses remain `unverified`.
6. Side-effect safety: everything runs in a transaction that's rolled back; `Mail::fake()`, `Notification::fake()`, `Queue::fake()`, `Event::fake()` (opt-out), `Http::preventStrayRequests()`. Routes can be excluded or marked `readOnly` for dynamic runs.
7. Optional **replay mode**: use requests your existing feature tests already make (Clearance hooks the kernel during your normal test run, records route + params), then replays them as every other persona. This gives realistic payloads with zero config.

Dynamic and static results are merged. A route with a static check but an `allow` for an outsider on a foreign instance is the highest-severity finding (**the check exists but doesn't work**).

### 3c. Access matrix (lock file)

`clearance.lock.json`, committed, deterministic (sorted keys, no timestamps in the body, so diffs are clean):

```json
{
  "schema": 1,
  "app": "acme",
  "generated_by": "dgtlss/clearance 1.0.0",
  "personas": ["guest", "customer", "staff", "admin"],
  "routes": {
    "PUT orders/{order}": {
      "name": "orders.update",
      "action": "App\\Http\\Controllers\\OrderController@update",
      "guards": {
        "middleware": ["auth:sanctum"],
        "checks": ["formrequest:UpdateOrderRequest->policy:update"],
        "policy": { "class": "App\\Policies\\OrderPolicy", "method": "update", "instance_aware": true }
      },
      "access": {
        "guest":    "deny",
        "customer": { "owned": "allow", "foreign": "deny" },
        "staff":    { "owned": "allow", "foreign": "allow" },
        "admin":    { "owned": "allow", "foreign": "allow" }
      },
      "approved": null
    }
  },
  "livewire": { "...": "static profiles" },
  "filament": { "...": "static profiles" }
}
```

Plus generated `CLEARANCE.md`: one table per route group, rows = routes, columns = personas, cells ✅ / ❌ / 🟡 (owned-only) / ⚠️ (unverified/error). Reviewers read this in the PR, not the JSON.

### 3d. Diff, gate & approve

`clearance:check` regenerates the matrix in memory and compares with the lock.
PR CI must use `--baseline=<trusted base ref>` to read that lock from a fetched
Git commit, rather than the PR's editable checkout. Protect baseline changes and
verification inputs with required code-owner review; see the README CI guide.

- **Widening** (blocks CI): new route reachable by any non-admin persona; any cell `deny → allow`; `owned-only → foreign allow`; a guard removed (middleware/check/policy disappeared) even if dynamic results are unchanged; policy became instance-blind.
- **Narrowing** (warns): `allow → deny`. Could be a bug, never a security issue.
- **Neutral**: metadata changes, renamed controller with identical access.

Approval workflow:

```bash
php artisan clearance:approve "PUT orders/{order}" --persona=staff --reason="Staff can edit any order (ticket OPS-112)"
php artisan clearance:approve --all-new --reason="Initial baseline"   # first run only
```

Approval writes `approved: { by, reason, at, hash }` into the lock entry, where `hash` covers the route's guard profile + access row. If the access changes again, the approval is invalidated. Approvals show up in the PR diff, so the *review* is the approval. `clearance:lock` regenerates the whole file (refuses to drop approvals silently).
New approvals require code-owner review and cannot override a Git baseline in the
same PR. Maintainers must promote the reviewed baseline to the trusted ref
through a separate controlled update before the access change can pass.

Exit codes: `0` clean, `1` unapproved widening, `2` verification errors above threshold, `3` unusable lock or Git baseline.

## 4. Artisan commands

| Command | Purpose |
|---|---|
| `clearance:install` | publish config, stub `app/Clearance/Personas.php`, add CI snippet |
| `clearance:scan` | static only; fast; table output (`--json`, `--route=`, `--only=risky`) |
| `clearance:verify` | static + dynamic; prints matrix |
| `clearance:lock` | write `clearance.lock.json` + `CLEARANCE.md` |
| `clearance:check --baseline=origin/main` | CI gate: diff vs a trusted Git lock, exit code, annotations |
| `clearance:approve` | approve specific widening(s) with reason |
| `clearance:explain {route}` | full trace for one route: middleware, checks found, per-persona HTTP status and why |
| `clearance:personas` | list personas and validate their factories build |

Dynamic runs execute inside the test runner. `clearance:verify` shells out to `pest --group=clearance` (or PHPUnit) using a generated test file, so it gets the project's real test bootstrapping.

## 5. Configuration (`config/clearance.php`)

```php
return [
    'lock_path'      => base_path('clearance.lock.json'),
    'markdown_path'  => base_path('CLEARANCE.md'),
    'personas'       => App\Clearance\Personas::class,

    'routes' => [
        'include' => ['*'],
        'exclude' => ['horizon.*', 'telescope.*', '_debugbar.*', 'sanctum.csrf-cookie', 'livewire.*'],
        'admin_prefixes' => ['admin', 'nova', 'filament'], // stricter defaults
    ],

    'dynamic' => [
        'enabled'        => true,
        'methods'        => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'],
        'fake'           => ['mail', 'notifications', 'queue', 'events', 'http'],
        'read_only'      => [],          // routes never executed with write verbs
        'replay_tests'   => false,       // record from existing suite
        'timeout_ms'     => 2000,
    ],

    'detect' => [
        'ability_middleware' => ['can', 'role', 'permission'], // spatie aliases included
        'custom_checks'      => [],      // e.g. [App\Support\Acl::class.'@check']
        'livewire'           => true,
        'filament'           => true,
    ],

    'gate' => [
        'fail_on'        => ['widening', 'guard_removed', 'instance_blind'],
        'max_errors'     => 0,
    ],

    'report' => [
        'github_annotations' => env('GITHUB_ACTIONS', false),
        'sarif'              => false,
        'warden'             => true,
    ],
];
```

## 6. Personas API

```php
namespace App\Clearance;

use App\Models\{User, Order, Team};
use Dgtlss\Clearance\Persona;
use Dgtlss\Clearance\Personas as Base;
use Dgtlss\Clearance\World;

class Personas extends Base
{
    public function define(): array
    {
        return [
            Persona::guest(),

            Persona::make('customer')
                ->user(fn () => User::factory()->create()),

            Persona::make('staff')
                ->user(fn () => User::factory()->staff()->create())
                ->guard('web'),

            Persona::make('admin')
                ->user(fn () => User::factory()->admin()->create())
                ->expectAll(),            // expected to reach everything; never "widening"

            Persona::make('api-client')
                ->user(fn () => User::factory()->create())
                ->sanctum(['orders:read']), // token abilities
        ];
    }

    /** How to make "owned" vs "foreign" instances of bound models. */
    public function ownership(World $world): void
    {
        $world->owns(Order::class, fn (User $u) => Order::factory()->for($u)->create());
        $world->owns(Team::class,  fn (User $u) => Team::factory()->hasAttached($u)->create());
        // Unconfigured models: Clearance tries a `user_id`/`owner_id` belongsTo guess, else marks "ownership unknown".
    }
}
```

Per-route overrides with an attribute or config:

```php
#[Clearance\Payload(['status' => 'shipped'])]
#[Clearance\Expect(customer: 'deny')]   // explicit intent, verified every run
public function update(UpdateOrderRequest $request, Order $order) { ... }
```

Multi-tenancy: `Persona::make('tenant-a-admin')->tenant(fn () => Tenant::factory()->create())` with a pluggable `TenantResolver` (stancl/tenancy and spatie/laravel-multitenancy adapters in v2). Cross-tenant access is reported like a foreign instance.

## 7. Output & reporting

- **CLI:** coloured matrix; findings grouped by severity (`critical`: outsider allow on foreign instance; `high`: auth-only + bound model, guard removed; `medium`: instance-blind policy, `authorize(): true`; `info`: public routes).
- **GitHub annotations:** `::error file=app/Http/Controllers/OrderController.php,line=42::Customer can update other users' orders (PUT orders/{order})`, pointed at the controller method line.
- **PR comment** (via an Action `dgtlss/clearance-action`, using `GITHUB_TOKEN`): an access diff table with only the changed cells, with approve instructions. Updates one sticky comment rather than spamming.
- **SARIF** export for GitHub code scanning (v1.1).
- **JSON** (`--json`) for other tooling.

## 8. Warden & OWASP Advisor integration

- **Warden:** Clearance registers as a Warden audit source. `warden:audit` includes Clearance findings in its report and notifications (Slack/Discord/email channels Warden already has). Shared severity scale.
- **OWASP Advisor:** maps findings to **A01 Broken Access Control**, so the Advisor's A01 score uses real verified data instead of heuristics.
- One CI step for the suite: `php artisan warden:audit --with=clearance`.
- Clearance does **not** depend on Warden; integration activates if Warden is installed.

## 9. Competitors & differentiation

| Tool | What it does | Gap Clearance fills |
|---|---|---|
| phoenix1331/laravel-auth-audit | static scan of routes/controllers/FormRequests/policies, coverage %, CI threshold | static only; can't tell if a check *works*; coverage % not a reviewable matrix |
| jay-tank/idorguard | static: route-model-bound params without visible checks | narrow controller-only heuristic |
| mykholy/laravel-accessproof | helpers for owner/outsider paired tests | you still write each test; no inventory, lock or diff |
| accguard / jabearri (rodrigo-areyzaga) | records test requests, replays with second credential | replay only; no static profile, personas, lock or approvals |
| spatie/laravel-permission, Bouncer | grant roles/permissions | they grant access; Clearance verifies the result |
| Generic SAST (Semgrep, Psalm taint, Snyk) | pattern/taint rules | no Laravel route/policy semantics, no runtime proof |

**Differentiation in one line:** the only tool that combines static guard detection, dynamic persona verification, a committed reviewable access matrix and a widening-only CI gate with approvals. And it's from the Warden maintainer, which matters in an ecosystem full of abandoned packages.

*(Competitor capabilities taken from their public READMEs as of Oct 2026; verify again before publishing comparisons.)*

## 10. Support matrix

- PHP 8.3, 8.4, 8.5. Laravel 12.x, 13.x. Pest 3/4, PHPUnit 11/12.
- Livewire 3/4 and Filament 4/5 detection optional (detected via `class_exists`).
- Databases: whatever the app's test suite uses (SQLite in-memory recommended for speed).
- Policy: support the two latest Laravel majors; drop the oldest within 3 months of a new major.

## 11. Architecture / modules

```
src/
  Discovery/      RouteCollector, MiddlewareResolver, LivewireCollector, FilamentCollector
  Static/         AstIndex (php-parser, cached by file hash), CheckDetectors/* (Authorize, Gate, Can,
                  FormRequest, Middleware, Scoping), PolicyAnalyzer, GuardProfile
  Dynamic/        PersonaRegistry, World (factories + ownership), PayloadBuilder (from rules()),
                  RequestRunner, OutcomeClassifier, ReplayRecorder
  Matrix/         AccessMatrix, LockFile (read/write/canonicalise), MarkdownRenderer
  Diff/           MatrixDiff, WideningRules, ApprovalStore (hash + invalidate)
  Report/         ConsoleReporter, GithubAnnotations, PrComment (payload only), Sarif, Json
  Integrations/   Warden, OwaspAdvisor, Tenancy/*
  Console/        Install, Scan, Verify, Lock, Check, Approve, Explain, Personas
  Testing/        Pest plugin + PHPUnit extension, generated test harness
```

Design rules: detectors are small plug-in classes (`CheckDetector` interface) so teams can add their own ACL helpers; the lock file is the only state; everything sorted and deterministic.

## 12. Scope

**MVP (v1.0, open source, MIT)**
- Livewire 3/4 and Filament 4/5 static inventory, fingerprints, reports and approvals.
- Optional verified-access Warden and OWASP Advisor integrations; explicit schema migration.
- Route discovery, middleware resolution, controller/FormRequest/policy static detection.
- Personas API, ownership via factories, dynamic GET + write verification with the 422-means-allowed rule.
- Lock file + markdown, `check` with widening rules, `approve` with hash invalidation.
- Console + GitHub annotations + JSON. Warden hook.

**v1.x / v2 (open source)**
- Replay mode from existing tests; SARIF; PR comment Action.
- Livewire action *dynamic* verification; Filament resource dynamic checks.
- Tenancy adapters; Sanctum token abilities and Passport scopes as persona dimensions.
- Inertia/API resource field-level exposure (who sees which attributes): a strong v2 feature.

**Pro (paid, Warden Pro bundle)**
- **History:** every lock change stored with who approved what and why; timeline per route.
- **SOC2 / ISO 27001 evidence reports:** periodic access review PDF (personas × capabilities, approvals with reasons, changes in period). This is the main thing a paying team buys.
- **Hosted dashboard** across many repos/apps; org-wide policies (e.g. "no public write routes", "admin prefix requires `can:`").
- Scheduled drift runs, Slack approvals, SSO.
- Priority support.

## 13. Milestones (solo-maintainer estimates, part-time)

| # | Milestone | Effort |
|---|---|---|
| M0 | Skeleton, CI matrix (L12/13 × PHP 8.3–8.5), fixture apps (vanilla, spatie-permission, Filament) | 3–4 days |
| M1 | Static: routes, middleware, AST detectors, policies, `clearance:scan` | 1.5–2 weeks |
| M2 | Dynamic: personas, World/ownership, PayloadBuilder, runner, classifier, `verify` | 2–3 weeks |
| M3 | Lock file, markdown, diff rules, approvals, `check`/`approve`/`explain` | 1–1.5 weeks |
| M4 | GitHub annotations, docs site, Warden/OWASP Advisor hooks, **v1.0 release** | 1 week |
| M5 | Replay mode, SARIF, PR comment Action (v1.1) | 1.5 weeks |
| M6 | Livewire/Filament dynamic, tenancy adapters (v2) | 3–4 weeks |
| M7 | Pro: history store, reports, dashboard | 4–6 weeks |

Rough total to v1.0: **6–8 weeks**. Overall size: **M**, with the dynamic engine as the hard part.

## 14. Risks & open questions

- **Payload generation for write routes.** Complex `rules()` (closures, `Rule::exists` on related data, conditional rules) will defeat a generic builder. Mitigation: 422 still proves authz passed; per-route `#[Payload]` overrides; replay mode.
- **Authorisation after validation.** If a controller validates *before* authorising, a 422 doesn't prove authz passed. Detect order statically and mark those cells `unverified` instead of `allow`. *Open question: how common is this in real apps?* Needs a survey of fixture apps.
- **Side effects despite fakes** (raw `DB::statement`, external SDKs not using `Http`, filesystem writes). Mitigation: `Storage::fake`, `read_only` list, `--dry-static` mode; document clearly.
- **Ownership inference** for models without a simple `user_id` (polymorphic, team-based, tenant-based). Requires the user to configure `ownership()`. Unknown ownership must surface loudly, not default to safe.
- **Runtime cost** on apps with hundreds of routes × personas. Mitigation: parallel runs (Pest `--parallel`), only re-verify routes whose files or guard hashes changed (`--changed`).
- **Noise / alert fatigue** killing adoption. Initial `--all-new` baseline approval, and widening-only gating, are the key UX decisions.
- **Name collision:** `rivalex/clearance` (a Laravel/Livewire permissions admin panel) exists on Packagist and sits in the same problem space; thoughtbot's `clearance` Rails auth gem is well known. The vendor prefix avoids technical conflict, but brand/SEO overlap is real. *Decide before launch.*
- **Open:** should the PR comment Action live in this repo or separately? Should Pro be part of Warden Pro or sold separately? Do we gate on `narrowing` optionally for teams who want strict equality?

## 15. Quickstart (README draft)

```bash
composer require dgtlss/clearance --dev
php artisan clearance:install
```

Define who uses your app in `app/Clearance/Personas.php`:

```php
return [
    Persona::guest(),
    Persona::make('customer')->user(fn () => User::factory()->create()),
    Persona::make('admin')->user(fn () => User::factory()->admin()->create())->expectAll(),
];
```

Generate and commit your access matrix:

```bash
php artisan clearance:verify          # see who can reach what
php artisan clearance:lock            # writes clearance.lock.json + CLEARANCE.md
git add clearance.lock.json CLEARANCE.md
```

Gate every pull request:

```yaml
# .github/workflows/clearance.yml
- uses: actions/checkout@v4
  with:
    fetch-depth: 0
- run: php artisan clearance:check --baseline=origin/main
```

When access legitimately widens:

```bash
php artisan clearance:approve "PUT orders/{order}" --persona=staff --reason="Staff edit any order (OPS-112)"
```

The approval appears in the PR diff and needs code-owner review. Follow the
README workflow to promote it to the trusted baseline before merging widening.
