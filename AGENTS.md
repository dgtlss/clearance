# Working on Clearance

Use [SPEC.md](SPEC.md) as the product and technical guide. It is a draft with an
MVP and a separate roadmap; do not present roadmap features as implemented.
The current implementation and its limits are described in
[docs/implementation.md](docs/implementation.md).

- Clearance observes access; it must never grant application permissions.
- Keep verdicts deterministic. Errors and unknown ownership cannot become denials.
- Execute dynamic checks through the host test bootstrap, with middleware enabled,
  testing databases, request rollback, and side-effect fakes.
- Preserve explicit approvals; bind them to canonical guard and access hashes.
- Test access-control logic with real Laravel routes and owned/foreign records.
- Run `composer test`, `composer lint`, and `composer validate --strict` after changes.
- Support PHP 8.3–8.5 and Laravel 12–13. Keep optional integrations optional.
