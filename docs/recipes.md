# Integration recipes

These adapt the [quickstart](../README.md) to common test setups. Use the app's
actual permissions; Clearance never creates a bypass in its authorization layer.

## Spatie roles and permissions

Create the application's roles/permissions in the host test setup, with the
correct guard name. A persona factory can return
`User::factory()->create()->assignRole('staff')`. Do not use `expectAll()` merely
because staff can edit some foreign records. Declare an exact `Expect` on those
routes or approve their intended access. The acceptance fixture exercises real
Spatie middleware, ownership and admin access through both test runners.

## API guards and Sanctum

Use `Persona::make('api-client')->user(fn () => User::factory()->create())->guard('sanctum')`
for an existing `auth:sanctum` route. Keep the host's `api` middleware group,
including `SubstituteBindings`, enabled. The suite checks guest isolation and
owned/foreign policies through Sanctum's actual guard. This does not mint bearer
tokens or vary token abilities. Token abilities and Passport scopes are later
persona dimensions; test those separately in the host suite.

## Custom binders and nested records

Typed Eloquent controller parameters are discovered automatically. For a binder
that resolves an untyped value, supply exact per-route parameter callbacks and
matching payloads. Establish the intended owned/foreign fixtures yourself; the
scanner cannot infer arbitrary binder ownership. Real middleware and binders
still execute, and unresolved relationships remain unverified.

For `orders/{order}/lines/{line}`, register factories in `ownership()`:

```php
$world->owns(Order::class, fn (User $owner) =>
    Order::factory()->create(['user_id' => $owner->id]));
$world->owns(OrderLine::class, fn (User $owner, array $parents) =>
    OrderLine::factory()->create(['order_id' => $parents['order']->id]));
```

Factories also need the host's domain state: memberships, active subscriptions,
soft-deletion states or a current team. Use additional named personas/scenarios
and host tests for cases beyond the single owned/foreign pair.

## File uploads and validation

Per-route payload arrays or callbacks can contain `UploadedFile::fake()` objects,
including nested arrays. Clearance moves them into the real HTTP file bag.
Required `file` and `image` fields have basic generated fixtures; explicit input
is needed for MIME, size, database-dependent and conditional validation.

```php
'payloads' => [
    'POST orders/{order}/attachments' => fn ($request) => [
        'title' => 'Review document',
        'attachment' => \Illuminate\Http\UploadedFile::fake()->create('review.pdf', 20, 'application/pdf'),
    ],
],
```

A 422 is insufficient when visible authorization happens after validation. Supply
valid input to reach that check. The AST pass cannot prove control flow hidden in
arbitrary helpers; use host feature tests for those cases.

## Multiple databases and side effects

Configure every writable testing connection in `dynamic.connections`, including
the default. Keep each database disposable. MySQL and PostgreSQL tests exercise
two connections with real allowed/denied requests and assert both return to zero
rows and zero open transactions. Application code ending a managed transaction
stops verification with an error; a commit may already have persisted, so this is
detection, not compensation. DDL can implicitly commit on some engines.

Mail, notifications, queue, events, HTTP and configured storage are faked by
default. Files written directly or network calls through other SDKs need isolation
provided by the host. Exclude unsafe routes explicitly and review lost coverage.

## Optional audit tools

Warden 2.2 auto-registers the Clearance audit; use `warden:audit --only=clearance`.
Advisor 1.1 on Laravel 12 is decorated automatically and retains all native
checks. Its A01 outcome score cannot improve an existing failure. Both run real
verification only through the testing harness. Disable either integration using
its `report` switch when the host wants explicit command execution instead.
