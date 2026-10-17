# Clearance v1: release checklist

Owner: Nathan. Updated 17 October 2026. **Announcement embargo: not before
17 November 2026**, after the Runnr launch window ending 16 November.

The local v1 candidate is on `codex/v1-readiness`. This checklist does not
authorize merging, tagging, publishing or announcements. Local evidence is in
[docs/v1-validation.md](../docs/v1-validation.md).

## Local candidate preparation

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

## Before approving a release

- [ ] Review the candidate diff, schema migration, public API and documented limits.
- [ ] Make the reviewed candidate available on GitHub and run every hosted workflow against its exact commit. Local runs do not establish hosted CI status.
- [ ] Collect and record human beta/RC feedback, including integration setup and false positives, without exposing private consumer data.
- [ ] Resolve any resulting blockers and repeat affected checks.
- [ ] Merge the reviewed candidate into `main` and verify its final commit and hosted checks.
- [ ] Verify GitHub detects the MIT license and private vulnerability reporting remains enabled.
- [ ] Set the repository description/topics and recheck package-name availability and search overlap.
- [ ] On the actual approved release date, move Unreleased notes into a dated `1.0.0` section, add real comparison/tag links, and update the README status. Do not use the draft date as a release date.

## Tag and publish — explicit release approval required

- [ ] Obtain approval to tag and publish; the current instruction explicitly holds both.
- [ ] Create an annotated `v1.0.0` tag on the reviewed `main` commit and push it.
- [ ] Submit `dgtlss/clearance` to Packagist and enable the GitHub auto-update hook.
- [ ] Check `composer require dgtlss/clearance:^1.0 --dev` in fresh Laravel 12 and 13 apps.
- [ ] Create GitHub release notes from the dated CHANGELOG, including installation, migration, evidence and limits.

## Announce — on or after 17 November 2026

- [ ] Prepare a post explaining missing authorization checks and the access matrix using committed synthetic fixtures.
- [ ] Announce through the chosen channels, and triage issues during the first week, prioritizing false positives.
