# V1 completion plan

This is implementation work toward the full open-source v1 described in
`SPEC.md`. It does not authorize tagging, publishing, deployment or announcements.
The working branch is `codex/v1-readiness`; `main` retains the reviewed beta.

- [ ] Livewire 3/4 static collectors: discover registered and application components,
  callable actions and locked properties; identify visible checks and mutations.
- [ ] Filament 4/5 static collectors: registered panels, resources and pages,
  discovered model policies, authorization overrides and skipped checks.
- [ ] Schema 2 matrix, reports, CI diff and hashed approvals for static surfaces,
  with a safe migration from schema 1 that preserves existing route approvals.
- [ ] Optional Warden and OWASP Advisor integrations verified against real APIs.
- [ ] Verification fixtures for API authentication, custom and nested bindings,
  file uploads, validation order and multiple testing database connections.
- [ ] Stable public API documentation, migration guide, examples and support policy.
- [ ] Repeatable benchmark of useful first results against the five-minute target.
- [ ] Clean distribution artifacts, install-from-artifact acceptance and compatible
  CI jobs for core versions, optional integrations and database engines.
- [ ] Independent security review and regression tests for confirmed findings.

The existing core compatibility target remains PHP 8.3–8.5, Laravel 12–13,
Pest 3/4 and PHPUnit 11/12. Optional packages must remain optional. Private
consumer application identities, results and fixtures stay outside this package.

Replay, SARIF, sticky comments, dynamic component verification, tenancy adapters,
token-ability dimensions and paid features remain later-roadmap work.

External release evidence is recorded separately: hosted CI must run against the
reviewed candidate; human beta/RC feedback and publishing approval cannot be
substituted by local test results.
