# Clearance v1: release checklist

Owner: Nathan. Updated 17 October 2026. **Announcement embargo: not before
17 November 2026**, after the Runnr launch window ending 16 November.

Verification evidence is in [docs/v1-validation.md](../docs/v1-validation.md).

## Package verification

- [x] Complete the scope in `docs/v1-plan.md`; document the separate later roadmap.
- [x] Independently review security boundaries; reproduce and fix the five confirmed findings with regression coverage. Check the follow-up Livewire property and callable-method cases.
- [x] Pass core tests, fresh-app acceptance, lint and strict Composer validation across all six PHP 8.3–8.5 / Laravel 12–13 combinations.
- [x] Exercise real Spatie roles, both test runners, owned/foreign and nested bindings, mutation rollback, deterministic baselines, approvals and coverage-loss gates.
- [x] Check published Livewire 3/4, Filament 4/5, Sanctum 4, Warden 2.2 and Advisor 1.1 integrations; fail focused integration tests on skips.
- [x] Check MySQL 8.4 and PostgreSQL 17 isolation on Laravel 12 and 13 with two disposable databases.
- [x] Install the built ZIP in a fresh app; inspect the artifact and Git export rules.
- [x] Run the synthetic 507-route / 3,549-scenario benchmark within five minutes; document warm-dependency timing limits.
- [x] Build the static docs and check local links. Keep API, migration, support and known limits consistent with code.
- [x] Prepare MIT license metadata, README, CHANGELOG and SECURITY policy.
- [x] Keep private consumer application identities, fixtures and results out of package files and distribution artifacts.

## Maintainer review

- [ ] Review the diff, schema migration, public API and documented limits.
- [x] Run every hosted workflow against the reviewed code. All 12 jobs passed on `main` at `bef5d2c`; repeat for the selected release commit.
- [ ] Collect integration feedback, including setup and false positives, without exposing private consumer data.
- [ ] Resolve any resulting blockers and repeat affected checks.
- [x] Merge the reviewed changes into `main` and verify the merge commit and hosted checks.
- [ ] Verify GitHub detects the MIT license and private vulnerability reporting remains enabled.
- [ ] Set the repository description/topics and recheck package-name availability and search overlap.
- [ ] On release day, group the changelog notes under the version and actual release date, and add the corresponding comparison/tag links.

## Tag and publish

- [ ] Create an annotated `v1.0.0` tag on the reviewed `main` commit and push it.
- [ ] Submit `dgtlss/clearance` to Packagist and enable the GitHub auto-update hook.
- [ ] Check `composer require dgtlss/clearance:^1.0 --dev` in fresh Laravel 12 and 13 apps.
- [ ] Create GitHub release notes from the dated CHANGELOG, including installation, migration, evidence and limits.

## Announce — on or after 17 November 2026

- [ ] Prepare a post explaining missing authorization checks and the access matrix using committed synthetic fixtures.
- [ ] Announce through the chosen channels, and triage issues during the first week, prioritizing false positives.
