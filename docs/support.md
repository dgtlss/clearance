# Support and release policy

Clearance supports the following PHP, Laravel and optional integration versions.

| Component | Supported target |
| --- | --- |
| PHP | 8.3, 8.4, 8.5 (`>=8.3 <8.6`) |
| Laravel | 12.x, 13.x |
| Test runners | Pest 3/4 or PHPUnit 11/12, where the runner supports the PHP version |
| Databases exercised | SQLite, MySQL 8.4, PostgreSQL 17; default and configured secondary connections |
| Livewire static collector | 3.x and 4.x |
| Filament static collector | 4.x and 5.x (with its compatible Livewire version) |
| Warden adapter | Published 2.2 CustomAudit API |
| OWASP Advisor bridge | Published 1.1, Laravel 12; its upstream constraint excludes Laravel 13 |
| Spatie Permission / Sanctum | Consumer fixtures; optional, no hard dependencies |

Core has no dependency on optional integrations. Dependency constraints determine
valid combinations; supporting two ranges does not make every cross-product
installable. CI includes six core combinations and real optional-package jobs.

For 1.x, the APIs in [api.md](api.md), documented configuration and commands are
the compatibility boundary. Breaking changes require a major release. Changes to
static discovery can reveal previously missed surfaces, and fingerprint changes
can require renewed review. Such changes must be described in the changelog.
Lock schema changes require a migration; approvals must not disappear silently.

The target is the two latest Laravel majors. Dropping a supported PHP/Laravel
major is a breaking release, with upgrade notes. Security reports use the private
channel in [SECURITY.md](../SECURITY.md). Security fixes target `main` and the
latest 1.x release. End-of-support dates must be announced before changing this
policy.

Release checks include hosted CI on the exact commit, independent security
review, installation from a built artifact, and maintainer review of migrations
and known limits. Record integration feedback without publishing private
consumer identities or data. See the [release checklist](https://github.com/dgtlss/clearance/blob/main/.github/RELEASE_CHECKLIST.md).
