# Upgrade to v1 / schema 2

Start on a branch with a clean, reviewed baseline. Back up both generated files.
Install Clearance through your chosen Composer repository. Run the host test
suite before changing its access baseline.

1. Compare your published `config/clearance.php` with the new package defaults.
   Keep application settings, add the collector settings and the `surface_added`
   and `static_changed` fail types, and decide whether to enable Advisor. Nested
   published arrays are not automatically refreshed by Laravel's config merge.
2. Run `php artisan clearance:migrate --by=reviewer --reason='Upgrade baseline to schema 2'`.
   This performs no route requests. Existing route guards, access and approvals
   remain unchanged. It adds empty static sections and migration metadata. Invalid
   legacy approvals must be resolved first. Schema 2 is already current.
3. Run `php artisan clearance:check --json`. Review any route changes as usual.
   Livewire/Filament surfaces are new static evidence, not newly proven persona
   access. Inspect each surface and its related host feature tests.
4. Approve each intended route/static change using its exact printed identifier
   and a reason. Static surfaces do not accept `--persona`. Approval updates only
   the selected entry. It cannot approve unrelated changes.
5. Run `php artisan clearance:approve --coverage --by=reviewer --reason='Review v1 collector scope'`
   after resolving retained-entry changes. This records the expanded collector
   scope (and adds missing legacy inventory metadata) without changing matching
   route approval hashes.
6. Re-run `clearance:check`, review JSON and Markdown diffs, and commit both files.

Do not delete and recreate a baseline to get a passing check. `--all-new` applies
only when no lock exists. `clearance:lock` refuses unapproved changes and refuses
silently invalidating approvals.

An exact framework-version change in static evidence also requires review. A
collector disappearing is coverage loss; intentionally disabling or excluding it
requires a coverage reason. A surface absent from a successfully completed full
inventory is a deletion and counts as narrowing. Collection errors stay errors.

Persona-list or `expectAll()` changes require a reviewed baseline edit: update
persona metadata and every access row, renew affected approval hashes using the
approval workflow, and inspect the complete PR diff. A route-only approval cannot
silently alter persona definitions. Keep this migration separate from unrelated
authorization changes so the resulting access is reviewable.
