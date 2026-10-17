# V1 completion plan

This is implementation work toward the full open-source v1 described in
`SPEC.md`. It does not authorize tagging, publishing, deployment or announcements.
The working branch is `codex/v1-readiness`; `main` retains the reviewed beta.

- [x] Livewire 3/4 static collectors: discover registered and application components,
  callable actions and locked properties; identify visible checks and mutations.
- [x] Filament 4/5 static collectors: registered panels, resources and pages,
  discovered model policies, authorization overrides and skipped checks.
- [x] Schema 2 matrix, reports, CI diff and hashed approvals for static surfaces,
  with a safe migration from schema 1 that preserves existing route approvals.
- [x] Optional Warden and OWASP Advisor integrations verified against real APIs.
- [x] Verification fixtures for API authentication, custom and nested bindings,
  file uploads, validation order and multiple testing database connections.
- [x] Stable public API documentation, migration guide, examples and support policy.
- [x] Repeatable benchmark of useful first results against the five-minute target.
- [x] Clean distribution artifacts, install-from-artifact acceptance and compatible
  CI jobs for core versions, optional integrations and database engines.
- [x] Independent security review and regression tests for confirmed findings.

The local candidate evidence and remaining release gates are recorded in
[v1-validation.md](v1-validation.md). Completed items describe the implemented,
locally checked scope; static collectors do not claim dynamic component proof.

The existing core compatibility target remains PHP 8.3–8.5, Laravel 12–13,
Pest 3/4 and PHPUnit 11/12. Optional packages must remain optional. Private
consumer application identities, results and fixtures stay outside this package.

Replay, SARIF, sticky comments, dynamic component verification, tenancy adapters,
token-ability dimensions and paid features remain later-roadmap work.

External release evidence is recorded separately: hosted CI must run against the
reviewed candidate; human beta/RC feedback and publishing approval cannot be
substituted by local test results.
