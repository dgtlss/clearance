# Clearance v0.1.0 beta: release checklist

Owner: Nathan. Drafted 17 October 2026. **Announcement embargo: not before
17 November 2026**, after the Runnr launch window ending 16 November.

This checklist records future release work. The beta is unreleased; preparing
these files does not authorize tagging, publishing, or announcing it.

## 1. Code readiness

- [ ] All six jobs in `.github/workflows/tests.yml` are green for the release candidate, including PHP 8.3 with Laravel 12 and 13.
- [x] Run the suite, fresh-app acceptance, lint and strict Composer validation locally across all six combinations, including PHP 8.3. See `docs/implementation.md`.
- [x] Add the fresh-app Spatie acceptance workflow to each CI combination: install, verify, stable lock, deliberate regression, approval and explicit scope review.
- [x] Protect against lost route/scenario coverage and distinguish reviewed foreign access from unreviewed findings.
- [ ] Test suite, `composer lint`, and `composer validate --strict` pass on the reviewed `main` release candidate.
- [ ] Push reviewed code to `github.com/dgtlss/clearance`; verify the intended branch and file contents on GitHub.
- [x] Prepare `LICENSE` (MIT), `composer.json` license metadata, the README, and the CHANGELOG locally.
- [ ] Verify GitHub detects the MIT license after the files are pushed.
- [ ] Set the repository description and topics: `laravel`, `security`, `authorization`, `owasp`, `pest`.

## 2. Acceptance validation

- [x] Verify the committed Spatie fixture: seven route-method entries, four personas and 49 fully verified scenarios through both Pest and PHPUnit.
- [x] Check owned/foreign access, nested scoped bindings, database rollback, side-effect fakes and deterministic baseline files.
- [x] Confirm deliberate authorization removal blocks CI and that approvals leave unrelated changes blocking.
- [x] Confirm registered-route exclusions require scope review while physical route deletion remains narrowing.
- [x] Document reproducible fixture-based checks in `docs/acceptance.md`. Keep consumer application identities, results and artifacts outside the package.

## 3. Docs

- [x] Merge the quickstart into `README.md`; verify all draft command names, options, config keys, classes, and paths against the implementation.
- [x] Check the limits against current behavior, including redirect classification, route removal, transaction scope, and subprocess timeouts.
- [x] Add a prominent beta/API-may-change note and keep the planned v0.1.0 notes under `Unreleased`.
- [ ] On the actual release date, update the README's unreleased status, move the Unreleased notes into a dated `0.1.0` section, and add links to the real tag and comparison. Keep the beta/API-may-change note; do not use the documentation draft date as the release date.
- [x] Add `SECURITY.md`; enable GitHub private vulnerability reporting and verify the repository API returns `enabled: true`.
- [x] Recheck local Composer installation, auto-discovery, installer commands and both test runners in fresh Laravel 12 and 13 apps. Verify Warden auto-registration against its real optional contract locally.

## 4. Tag and publish — separate release approval required

- [ ] Tag `v0.1.0` on reviewed `main` with an annotated tag and push it.
- [ ] Verify `dgtlss/clearance` is available on Packagist, submit it, and enable the GitHub auto-update hook.
- [ ] Check `composer require dgtlss/clearance:^0.1 --dev` in fresh Laravel 12 and 13 apps.
- [ ] Create GitHub release notes from the CHANGELOG with highlights, installation, limits, roadmap, and a quickstart link. Mark the release as a pre-release.

## 5. Announce — on or after 17 November 2026

- [ ] Prepare a post about missing authorization checks and the access matrix, using the committed example fixtures.
- [ ] Announce through the chosen channels: X, r/laravel, a Laravel News submission, and a Warden README cross-link.
- [ ] Triage issues during the first week, prioritizing false positives.

## Naming checks before publishing

- [ ] Recheck package-name availability and search overlap on release day. Verify the draft's noted `rivalex/clearance` Laravel package and Thoughtbot's Rails `clearance` gem rather than treating their current status as confirmed.
