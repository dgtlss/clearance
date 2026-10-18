# Acceptance testing

Run the package suite and the normal Artisan workflow:

```bash
composer install
composer test
composer test:acceptance
composer test:package
composer test:benchmark
composer docs
composer lint
composer validate --strict
```

The acceptance script copies `tests/Acceptance/application` into a temporary
directory and installs this checkout using a Composer path repository. Its
Laravel, Pest and PHPUnit versions match the installed package-development
dependencies. Spatie Permission is installed in the fixture app, without adding
it as a Clearance dependency. A failed run retains the app at the printed path;
a successful run removes it. Composer needs network access.

The fixture has guest, customer, staff and admin personas, seven route-method
entries, and 49 scenarios. It exercises ownership, nested scoped bindings, real
Spatie permission middleware and a mutation that writes a record, sends mail and
writes a storage file. The test requires every scenario to receive an HTTP
response, checks each access result, compares Pest and PHPUnit output, and
asserts rollback and real-disk isolation. It then verifies deterministic lock and
Markdown files, read-only checks, physical route deletion, deliberate removal of
authorization, separate GET/HEAD approvals, and explicit scope review. A local
Git repository supplies a trusted base ref: unchanged access passes, missing refs
fail clearly, and a feature branch's self-approved widened lock still fails
against the base. The fixture mutations happen in the temporary copy.

`.github/workflows/tests.yml` runs this command in all six supported PHP/Laravel
combinations. These combinations also passed locally in isolated Docker runtimes
on 17 October 2026. To reproduce one runtime:

```bash
docker build --build-arg PHP_VERSION=8.3 -t clearance-test-php:8.3 tests/Acceptance
```

Copy the checkout into a disposable directory, excluding `.git` and `vendor`,
then mount that copy at `/work` in the image. Resolve the dependency constraints
from the relevant workflow matrix entry with `composer update --with=...`, then
run the commands above. Keep each combination's vendor directory separate.
See [validation results](v1-validation.md) for recorded local and hosted checks.

`test:package` builds and inspects a ZIP, rejects development/private artifacts and
installs that exact artifact in the fresh app. `test:benchmark` generates 250 more
GET/HEAD pairs: 507 route entries and 3,549 real scenarios. It asserts all expected
access and rollback, reports verification duration separately, and fails above
five minutes. Dependencies are warm; downloads and initial app setup are excluded.

Optional integration jobs install published packages rather than doubles:
Filament 4/Livewire 3 on Laravel 12 with Warden 2.2, Advisor 1.1 and Sanctum 4;
Filament 5/Livewire 4 on Laravel 13 with Warden and Sanctum. Focused tests fail on
skips so an absent dependency cannot make an integration job appear successful.
Advisor's upstream constraints currently exclude Laravel 13.

`DatabaseIsolationTest` runs on SQLite in the normal suite. SQL jobs run it alone
with `CLEARANCE_TEST_DB_DRIVER=mysql` or `pgsql`, two distinct disposable
`CLEARANCE_TEST_DATABASE` / `CLEARANCE_TEST_SECONDARY_DATABASE` values prefixed
`clearance_`, and explicit host/user/password variables. It creates/drops fixture
tables, so use the disposable CI services. It checks real HTTP ownership outcomes,
rollback on both connections and zero remaining rows/transactions.

`composer docs` builds a static site under `build/docs`, validates relative file
links and includes no publishing step. Open `build/docs/index.html` locally.
