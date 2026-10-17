# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Planned first public beta: **v0.1.0**. No release date or tag has been set. The
beta API may change. Date these notes when the release is actually made.

### Added

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
- Eight Artisan commands: `clearance:install`, `clearance:scan`, `clearance:verify`, `clearance:lock`, `clearance:check`, `clearance:approve`, `clearance:explain`, and `clearance:personas`.
- Console and JSON output, plus automatic GitHub Actions change annotations from `clearance:check` when enabled.
- Optional `Dgtlss\Clearance\Integrations\WardenAudit` adapter: blocking matrix changes block, baseline findings remain advisory, and findings carry OWASP A01 metadata.
- Actionable diagnostics for unresolved controller classes and methods, retaining `unknown` verdicts and known middleware.
- Safe installer for `config/clearance.php` and `app/Clearance/Personas.php`, plus package auto-discovery.
- Support targets for Laravel 12 and 13, PHP 8.3–8.5, PHPUnit 11/12, and Pest 3/4; six PHP/Laravel combinations configured in CI.
- MIT license.

### Fixed

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
- Internal Livewire actions and Filament resources are not inventoried; OWASP Advisor scoring is not implemented.
- Transactions cover only the default and configured database connections; DDL, explicit commits, direct filesystem writes, raw sockets, and external SDKs can escape isolation.
- No hard per-request timeout. Slow completed scenarios, including setup, are detected; the generated test subprocess has a configurable timeout.

[Unreleased]: https://github.com/dgtlss/clearance/commits/main
