# Acceptance testing

Run the package suite and the normal Artisan workflow:

```bash
composer install
composer test
composer test:acceptance
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
authorization, separate GET/HEAD approvals, and explicit scope review. The
fixture mutations happen in the temporary copy.

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
Hosted Actions status remains a separate release check after the commit is pushed.
