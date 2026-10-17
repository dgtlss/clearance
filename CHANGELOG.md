# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Target: **v1.0.0**. This is an unreleased development candidate. No release date
or tag has been set. Date these notes only when the release is approved.

### Added

- Livewire 3/4 static component/action discovery, locked-property signals and visible unguarded mutations, including Livewire 4 anonymous class templates.
- Filament 4/5 panel, resource and page discovery; model policy coverage, authorization overrides, skipped checks, configuration evidence and distinct resource-page identities.
- Schema 2 static surfaces with no fabricated runtime access, canonical implementation fingerprints, new-surface/drift gates, hashed approvals and coverage-loss detection.
- Lossless `clearance:migrate` for schema-1 route baselines and their approval hashes.
- Optional Advisor 1.1 bridge preserving native checks and the worst A01 status, with documented outcome/conformance scores and Laravel 12 compatibility.
- Actual Sanctum guard fixtures, multipart/nested uploads, and two-connection rollback on SQLite, MySQL and PostgreSQL.
- Public API/support policy, migration and integration guides, a locally buildable static docs site and link validation.
- Clean Composer/Git distribution rules and acceptance installation from a built ZIP.
- Repeatable synthetic benchmark: 507 route entries and 3,549 scenarios; elapsed time is reported separately from deterministic matrices.
- CI jobs for published optional integrations, SQL engines, distribution installs, docs and the benchmark.

- Runtime route inventory with methods, URIs, domains, actions, and resolved global and route middleware.
- Static AST signals for controller `authorize()` and Gate calls, FormRequest `authorize()`, policies, and owner-query or model-scope heuristics.
- Personas for guests, factory-built users, and named guards; `expectAll()` for explicitly unrestricted widening exemptions.
- Real verification through the host Pest or PHPUnit bootstrap, with owned and foreign model scenarios.
- Testing-only dynamic requests with enabled middleware, per-scenario rollback, side-effect fakes, and blocked stray Laravel HTTP requests.
- Canonical `clearance.lock.json` and a readable `CLEARANCE.md` matrix, with configurable paths.
- CI checks for access widening, removed guards/scoping signals, instance-awareness regressions, and removed personas. Verification errors and explicit expectation mismatches also fail checks.
- Approvals recording a reason, reviewer, UTC time, and canonical guard/access hash, with route and persona scope checks.
- Full registered route inventory and normalized verification scope in the lock, with explicit `clearance:approve --coverage` reviews that preserve route approvals.
- Categorized incomplete verification with scenario/response counts and actionable console/JSON summaries. Matching approvals and declared foreign access remain visible as informational findings.
- Parent-model context for ownership factories, allowing valid related fixtures for nested route bindings.
- Fresh-app acceptance tests with real Spatie roles, both test runners, deterministic baselines, deliberate regressions, and the complete install/verify/lock/check/approve workflow in each CI combination.
- `SECURITY.md` and an enabled GitHub private vulnerability reporting channel.
- Nine Artisan commands (including the new `clearance:migrate`): `clearance:install`, `clearance:scan`, `clearance:verify`, `clearance:lock`, `clearance:check`, `clearance:approve`, `clearance:explain`, `clearance:personas`, and `clearance:migrate`.
- Console and JSON output, plus automatic GitHub Actions change annotations from `clearance:check` when enabled.
- Optional `Dgtlss\Clearance\Integrations\WardenAudit` adapter: blocking matrix changes block, baseline findings remain advisory, and findings carry OWASP A01 metadata.
- Actionable diagnostics for unresolved controller classes and methods, retaining `unknown` verdicts and known middleware.
- Safe installer for `config/clearance.php` and `app/Clearance/Personas.php`, plus package auto-discovery.
- Support targets for Laravel 12 and 13, PHP 8.3–8.5, PHPUnit 11/12, and Pest 3/4; six PHP/Laravel combinations configured in CI.
- MIT license.

### Fixed

- Trait-provided and aliased authorization methods retain their real AST bodies, so Filament implementation changes invalidate static approvals instead of hashing a missing body.
- Anonymous Livewire components retain trait/inherited public actions and locked properties. Unresolvable composition or ambiguous portable identities reports an error rather than false narrowing.
- Livewire property redeclarations remove inherited locked evidence, and callable overrides of framework methods remain inventoried. Conflicting anonymous trait/base property attributes report incomplete discovery across supported PHP versions.
- Request-scoped principals, facade roots and cached route controllers no longer leak into later persona scenarios.
- Effective HTTP method mismatches and unmatched-route 404s cannot become verified denials for an unexecuted route.
- Selective HTTP stray-request allowlists inherited from the host are cleared on the verifier's clone; original clients and configured fakes are preserved.

- Uploaded files enter Laravel's real file bag. Minimal FormRequest payloads include basic bounds and file/image fixtures.
- Application code ending a managed scenario transaction stops verification with an error instead of silently continuing after a commit.
- Shared Filament page classes retain distinct resource identities rather than overwriting each other.

- Model binding discovery follows Laravel's snake_case route-parameter fallback for camelCase controller arguments, while preserving exact-name precedence.
- Binding failures for a known single-model fixture are verified through the real HTTP kernel instead of becoming premature scenario errors. Unresolved bindings across multiple parameters remain unverified so invalid nested fixtures cannot prove a denial.
- Custom guard redirects to `/` and other non-login destinations remain unverified instead of falsely reporting allowed access. Login detection resolves relative URLs against the scenario's domain and requires the same origin; external login redirects remain unverified.
- A 404 only counts as denial in a bound foreign-record scenario; unbound and owned-record 404s retain uncertainty.
- Regression coverage for redirecting middleware, owned/foreign mutations, later access widening, and refusal to approve unverified access at the default error threshold.
- Exclusions and filters cannot silently turn lost coverage into route deletion. Lost verified cells and owned/foreign dimensions block even with an increased error threshold.
- Invalid or stale approval metadata cannot mark findings as reviewed. New route approvals retain a consistent baseline inventory.

### Known limitations

- Static checks establish call presence, not enforcement on every code path.
- Custom route binders and nested ownership have limited support; scenarios cannot cover every model state or outsider.
- Successful action redirects remain unverified alongside custom rejection redirects; host feature tests are needed to distinguish them. Validation hidden behind helpers may also need explicit scenarios.
- Physically removed routes count as narrowing; excluded registered routes and lost persona/scenario coverage block. Incomplete results also obey the configured error threshold.
- Livewire and Filament evidence is static only; internal component actions have no verified persona access. Custom discovery and functional Volt templates need explicit host coverage.
- Advisor scores describe outcomes/conformance, not an OWASP certification; Advisor 1.1 is compatible with Laravel 12 only.
- Transactions cover only the default and configured database connections; DDL, explicit commits, direct filesystem writes, raw sockets, and external SDKs can escape isolation.
- No hard per-request timeout. Slow completed scenarios, including setup, are detected; the generated test subprocess has a configurable timeout.

[Unreleased]: https://github.com/dgtlss/clearance/commits/main
