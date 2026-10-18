# Implementation status

This describes Clearance's implemented features and limits. `SPEC.md` is the
draft product guide; [api.md](api.md) documents the public API.

| Area | Available |
| --- | --- |
| Routes | Runtime inventory, domains/methods, global and expanded route middleware, full discovery before filters |
| Static checks | Cached AST, controller/Gate/user checks, FormRequest authorization, policy instance use, query/global-scope heuristics, custom detectors |
| Personas | Guest, factory-backed users, named guards, explicit widening exemptions, owned/foreign models and parent-model factory context |
| Verification | Host Pest/PHPUnit bootstrap, enabled middleware, real HTTP requests, parameter/payload overrides and controller attributes |
| Payloads | Common required types/bounds and file/image fixtures; nested UploadedFile objects enter the real file bag |
| Safety | Testing-only, per-scenario rollback on default/configured connections, side-effect fakes, blocked stray Laravel HTTP, transaction-ending detection |
| Livewire 3/4 | Static registered/application component actions, anonymous class templates, inherited/trait actions, locked properties, visible checks and mutations |
| Filament 4/5 | Static panels/resources/pages, policies, overrides, skipped checks, panel guards, configured instances and distinct resource pages |
| Matrix | Schema 2 routes plus separate static surfaces; deterministic maps, no fabricated component access, portable source locations |
| Gate | Widening, guard removal, instance-awareness loss, lost runtime/static coverage, new static surfaces and implementation drift; `check --baseline=<git ref>` uses a trusted Git lock |
| Approvals | Canonical guard/access hashes, implementation evidence for static surfaces, explicit scope review, lossless schema-1 migration |
| Commands/reports | Nine commands, console, Markdown, JSON, escaped GitHub annotations and actionable incomplete-check summaries |
| Warden | Optional real 2.2 CustomAudit contract, blocking changes and advisory baseline findings with A01 metadata |
| Advisor | Optional real 1.1 contract/bindings, preserved native checks/status, documented A01 outcome and conformance scores; Laravel 12 only |
| Distribution/docs | MIT, safe installer, artifact-install acceptance, public API/migration/support/recipes, local static docs build |

## Evidence and limits

PR CI must use `clearance:check --baseline=<trusted base ref>` and protect the
baseline and verification inputs with required code-owner review. Without this
option, the check and optional audit integrations read the checkout's lock, which
the PR can replace. The option reads Git objects, never accepts new PR approvals
as its baseline, and reports checkout lock changes separately. It does not
sandbox arbitrary contributor PHP or authenticate recorded reviewer names.

Route access records only the supplied persona/input/model-state scenarios. A
foreign record belongs to another newly built user, not every possible outsider.
Typed Eloquent route parameters are supported. Custom binders and nested ownership
need explicit fixtures and overrides. Unknown ownership cannot prove denial.

Request scopes, facade caches and route controllers are reset for each scenario.
Effective-method mismatches and unmatched-route 404s stay unverified. Inherited
HTTP allowlists cannot bypass the verifier's stray-request block.

Static calls establish presence, not enforcement on every path. Livewire/Filament
profiles have empty access maps and do not execute internal actions. Custom
discovery, functional Volt templates, arbitrary helper control flow and nested
authorization logic can need host tests. A locked identifier does not establish
permission to use its record. Implementation/framework fingerprints require
review even when the textual guard signals remain unchanged. Trait imports and
aliases resolve their real bodies. Property redeclarations remove inherited
locked signals, and application overrides of framework methods remain inventoried.
Anonymous-template local trait adaptations, conflicting trait/base property
attributes, unresolvable bases and ambiguous portable identities produce collector
errors; a named component class can resolve more complex trait composition.

Redirects to recognized login destinations on the scenario's origin count as
denials. Other redirects remain unverified, including successful post-save
redirects. A 404 counts as denial only for bound foreign records. A 422 counts as
allowed when no visible validation-before-authorization hazard is detected;
hidden control flow still needs explicit valid inputs and host tests.

Transactions cover only the default and configured connections. Ending a managed
transaction stops verification, but a manual/implicit commit may already have
persisted. DDL, unlisted connections, direct filesystem writes, raw sockets and
external SDKs require disposable host isolation. Completed slow scenarios are
detected; only the full subprocess has a hard timeout.

`expectAll()` exempts an intentionally unrestricted persona from widening, without
granting permissions or asserting success. Adding exemptions or changing the
persona list requires a reviewed baseline migration. Scope reductions are explicit
reviewed gaps. Lost previously verified cells block independently of the error
budget. Approvals become stale when their canonical guards/access changes.

Advisor's A01 score is a Clearance outcome score: success 100, warning 50, error
0, preserving the worst native status. The nested conformance score is pass/fail
against reviewed scope. Neither score certifies security. Empty/missing or failed
verification never earns conformance credit.

## Validation

The [acceptance guide](acceptance.md) describes repeatable tests. Core CI targets
six PHP 8.3–8.5/Laravel 12–13 combinations. Real optional-package jobs cover
Livewire 3/4, Filament 4/5, Sanctum, published Warden 2.2 and Advisor 1.1. SQL jobs
exercise MySQL 8.4 and PostgreSQL 17 on Laravel 12 and 13, with two disposable
databases per run. Package acceptance installs the built ZIP rather than relying
on a source symlink.

The synthetic benchmark on 17 October 2026 verified **507 route entries / 3,549
scenarios in 10.258 seconds** on native PHP 8.5.10 / Laravel 13.37.0 / SQLite.
Dependencies were already installed; the timing includes the host bootstrap and
real Spatie/ownership requests. This is a repeatable fixture result, not evidence
of a five-minute first result in every consumer application. Composer downloads,
application-specific setup and fixture complexity vary.

Compatibility results, hosted CI and independent-review evidence are recorded in
[v1-validation.md](v1-validation.md).

## Later roadmap

Replay, SARIF, sticky PR comments/Action, dynamic component verification, tenancy
adapters, Sanctum/Passport token dimensions, incremental/parallel runs and
field-level exposure remain later open-source work. History, compliance PDFs,
dashboards, organization policies and scheduled drift runs are the separate Pro
roadmap.
