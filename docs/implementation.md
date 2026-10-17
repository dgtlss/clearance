# Implementation status

`SPEC.md` is the product guide. This checkout implements the core route workflow
as an early 0.1 development version. It is not a completed v1.0 release.

| Area | Available |
| --- | --- |
| Package | MIT, Laravel provider discovery, published config, safe installer, six PHP/Laravel CI combinations |
| Route inventory | Runtime route collection, methods/domains, route globs, global middleware and expanded route middleware |
| Static signals | Cached PHP AST, controller checks, Gate/user ability calls, custom detectors/checks, FormRequest classifications, constructor resource-authorization signal, owner-query and model global-scope heuristics |
| Policies | Explicit and Laravel auto-discovered policies; model-parameter usage and unresolved method reporting |
| Personas | Guest, factory-backed users, named guards, expected unrestricted personas; ownership factories and simple owner-column inference |
| Verification | Real Pest/PHPUnit bootstrap, owned/foreign requests, minimal required payloads, payload/parameter overrides, HTTP outcomes and reasons |
| Safety | Testing-only execution, enabled middleware, per-request rollback, application side-effect fakes, blocked stray Laravel HTTP requests |
| Matrix | Canonical sorted JSON without generation timestamps, Markdown route groups, unknown/error cells, explicit access expectations |
| Gate | Widening, guard removal, instance-awareness regression, lost personas/scenarios/owned dimensions, registered route exclusions and scope changes, warning-only physical route removal, exit codes |
| Approvals | Reason/reviewer/time/hash, initial baseline approval, route/persona scope checks, separate hashed scope reviews, refusal to silently invalidate approvals |
| Reports | All eight commands, JSON, categorized incomplete checks with next steps, reviewed access as informational findings, escaped GitHub change annotations |
| Acceptance | Fresh Laravel apps with Composer discovery, real Spatie roles, nested fixtures, both runners, rollback, stable baselines, deliberate regressions and approvals |
| Warden | Optional adapter for the existing CustomAudit API; widening blocks, baseline findings remain advisory; A01 metadata |

## Work remaining before 1.0

- Deeper authorization control flow. The AST pass reports call presence, not proof every code path
  enforces it. Validation order is conservative for visible calls but cannot
  resolve checks hidden behind arbitrary helpers or middleware internals.
- Broader binding discovery, including arbitrary custom route binders and
  composite/nested ownership scenarios. Typed Eloquent route parameters are
  supported; configure other parameters explicitly.
- Optional Livewire/Filament static collectors and integration fixtures for
  them. Their internal action surface is not inventoried
  yet; only registered HTTP routes are included.
- Direct OWASP Advisor score integration after confirming its extension API.
  Current findings expose the category and Warden metadata.
- A complete docs site, broader application fixtures, and release work.

## Verification limits

The access matrix records the supplied scenarios. It cannot prove authorization
for every possible payload, model state, guard, or relationship. Foreign records
use another freshly created owner, not every possible outsider. Nested route
parameters need factories/overrides that establish the intended relationships.
Ownership factories receive the owner and previously built models keyed by
route parameter. This supports explicit related fixtures without inferring
arbitrary ownership or granting any permissions.
Typed model bindings recognize Laravel's snake_case fallback for camelCase
controller arguments. When preparing a known single-model fixture produces a
binding miss, the real kernel verifies the response with middleware enabled.
Unresolved bindings across multiple parameters remain unverified; independently
created parent and child records cannot prove an ownership denial.

Redirects to recognized login destinations on the scenario's origin count as
denials. Other redirects remain unverified: a successful action and a custom
middleware rejection can both redirect to `/`. Relative destinations resolve
against the actual scenario URL, including its domain. External redirects do not
prove a denial, even when their path is `/login`. A 404 counts as denial only for
a bound foreign record; owned-record and unbound 404s remain unverified.

Transactions cover the default database connection plus configured
`dynamic.connections`. Application DDL, manual commits, unlisted connections,
direct filesystem writes, and external SDK calls need isolation supplied by the
host testing environment. `timeout_ms` detects slow completed requests; the
subprocess timeout bounds the full run. There is no per-request hard interrupt.

`expectAll()` exempts widening for an intentionally unrestricted persona. Adding
a new exemption blocks against an existing baseline; changing the baseline's
persona definitions requires explicit review. This version's approval command
approves route entries and refuses changes to the persona list or exemptions;
persona-definition migrations need a reviewed baseline edit, including matching
access rows and renewed approval hashes where necessary. Keep those changes
visible in the same PR.

Schema 1 now includes the full route inventory and normalized verification
scope. Older schema-1 locks remain readable, but adding this metadata requires
`clearance:approve --coverage` with a reason. That command retains matching route
approvals and cannot approve access or guard changes. Lost verified scenarios
block independently of `max_errors`; increasing that threshold cannot hide a
previously verified result becoming unknown. Reviewed foreign access becomes
informational in reports only while its guard/access hash remains valid.

## Later roadmap

Replay recording, SARIF, sticky PR comments/Action, dynamic Livewire/Filament
checks, tenancy adapters, Sanctum/Passport token dimensions, incremental/parallel
verification, and field-level exposure remain the spec's v1.x/v2 work. History,
compliance PDFs, dashboards, organization policies, and scheduled drift runs are
the separate Pro roadmap.

## Local validation

The fixture suite, including the generated host-runner subprocess, was exercised
on these dependency combinations:

| Laravel | PHP | PHPUnit | Pest harness |
| --- | --- | --- | --- |
| 12.70.0 | 8.3.35, 8.4.26, 8.5.11 | 11.5.56 | 3.8.7 |
| 13.37.0 | 8.3.35 | 11.5.56 | 3.8.7 |
| 13.37.0 | 8.4.26, 8.5.11 | 12.5.33 | 4.7.8 |

The optional Warden adapter was also tested against the neighboring Warden
checkout's real `CustomAudit` contract. Its test skips when Warden is absent.
All six combinations passed the suite, formatting and strict Composer validation
in isolated Docker runtimes. Each also ran `composer test:acceptance`: a fresh
Composer-installed Laravel app with actual Spatie permissions, seven route-method
entries and 49 fully verified scenarios. Both Pest and PHPUnit produced the same
matrix. Repeated baselines were byte-identical; removing authorization failed the
gate, per-route approvals left other changes blocking, exclusions required scope
review, and real route deletion remained narrowing. Database rows rolled back and
real storage stayed untouched. Hosted GitHub Actions still needs to run against
the reviewed commit after it is pushed; local checks do not claim hosted CI passed.

See [acceptance testing](acceptance.md) to reproduce the fixture-based checks.
