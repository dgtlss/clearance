<?php

namespace Dgtlss\Clearance\Tests\Feature;

use Dgtlss\Clearance\Clearance;
use Dgtlss\Clearance\Diff\MatrixDiff;
use Dgtlss\Clearance\Dynamic\Verifier;
use Dgtlss\Clearance\Matrix\CanonicalJson;
use Dgtlss\Clearance\Tests\Fixtures\ActorFacade;
use Dgtlss\Clearance\Tests\Fixtures\FixtureController;
use Dgtlss\Clearance\Tests\Fixtures\MissingActionController;
use Dgtlss\Clearance\Tests\Fixtures\Order;
use Dgtlss\Clearance\Tests\Fixtures\RedirectingOwnership;
use Dgtlss\Clearance\Tests\Fixtures\RelatedPersonas;
use Dgtlss\Clearance\Tests\Fixtures\ScopedActorController;
use Dgtlss\Clearance\Tests\Fixtures\User;
use Dgtlss\Clearance\Tests\TestCase;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class VerificationTest extends TestCase
{
    public function test_file_payloads_use_the_real_request_file_bag_and_keep_foreign_access_denied(): void
    {
        app('router')->post('/upload/{order}', [FixtureController::class, 'upload'])->middleware(['web', 'auth'])->name('fixture.upload');
        config(['clearance.routes.include' => ['fixture.upload']]);
        $matrix = app(Verifier::class)->run($this);
        $route = $matrix['routes']['POST upload/{order}'];
        $this->assertSame('deny', $route['access']['guest']);
        $this->assertSame(['owned' => 'allow', 'foreign' => 'deny'], $route['access']['customer']);
        $this->assertSame(200, $route['observations']['customer']['owned']['status']);
        $this->assertSame(403, $route['observations']['customer']['foreign']['status']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertFalse(Storage::disk('local')->exists('uploads/fixture.txt'));
        $this->assertFalse(Storage::disk('local')->exists('uploads/fixture.png'));
    }

    public function test_application_commits_cannot_silently_disable_request_rollback(): void
    {
        app('router')->post('/commit', function () {
            DB::commit();

            return ['ok' => true];
        })->name('fixture.commit');
        config(['clearance.routes.include' => ['fixture.commit']]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ended a Clearance transaction');
        app(Verifier::class)->run($this);
    }

    public function test_unresolved_actions_do_not_turn_runtime_failures_into_denials(): void
    {
        app('router')->post('/missing-action', [MissingActionController::class, 'missing'])->name('fixture.missing-action');
        config(['clearance.routes.include' => ['fixture.missing-action']]);
        $route = app(Verifier::class)->run($this)['routes']['POST missing-action'];

        $this->assertSame('unknown', $route['verdict']);
        foreach (['guest', 'customer', 'admin'] as $persona) {
            $this->assertSame('error', $route['access'][$persona]);
            $this->assertNull($route['observations'][$persona]['foreign']['status']);
        }
    }

    public function test_owned_foreign_guest_and_admin_access_are_verified(): void
    {
        config(['clearance.routes.include' => ['fixture.protected', 'fixture.broken', 'fixture.public', 'fixture.middleware']]);
        $matrix = app(Verifier::class)->run($this);
        $routes = $matrix['routes'];

        $this->assertSame('deny', $routes['GET protected/{order}']['access']['guest']);
        $this->assertSame(['owned' => 'allow', 'foreign' => 'deny'], $routes['GET protected/{order}']['access']['customer']);
        $this->assertSame(['owned' => 'allow', 'foreign' => 'allow'], $routes['GET protected/{order}']['access']['admin']);
        $this->assertSame(['owned' => 'allow', 'foreign' => 'allow'], $routes['GET broken/{order}']['access']['customer']);
        $this->assertSame(['owned' => 'allow', 'foreign' => 'deny'], $routes['GET middleware/{order}']['access']['customer']);
        $this->assertSame('allow', $routes['GET public']['access']['guest']);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_422_only_counts_as_allowed_when_authorization_precedes_validation(): void
    {
        config(['clearance.routes.include' => ['fixture.update', 'fixture.late', 'fixture.validated-late']]);
        config(['clearance.dynamic.payloads.PUT orders/{order}' => []]);
        config(['clearance.dynamic.payloads.POST validated-late/{order}' => []]);
        $routes = app(Verifier::class)->run($this)['routes'];

        $this->assertSame(422, $routes['PUT orders/{order}']['observations']['customer']['owned']['status']);
        $this->assertSame(['owned' => 'allow', 'foreign' => 'deny'], $routes['PUT orders/{order}']['access']['customer']);
        $this->assertSame(['owned' => 'unverified', 'foreign' => 'unverified'], $routes['POST late/{order}']['access']['customer']);
        $this->assertSame(['owned' => 'unverified', 'foreign' => 'unverified'], $routes['POST validated-late/{order}']['access']['customer']);
    }

    public function test_snake_case_model_bindings_verify_owned_and_foreign_records(): void
    {
        app('router')->get('/snake/{order_record}', [FixtureController::class, 'snakeCase'])
            ->middleware(['web', 'auth'])->name('fixture.snake');
        config(['clearance.routes.include' => ['fixture.snake']]);

        $route = app(Verifier::class)->run($this)['routes']['GET snake/{order_record}'];
        $this->assertSame('deny', $route['access']['guest']);
        $this->assertSame(['owned' => 'allow', 'foreign' => 'deny'], $route['access']['customer']);
        $this->assertSame(['owned' => 'allow', 'foreign' => 'allow'], $route['access']['admin']);
    }

    public function test_binding_denials_are_observed_through_the_real_http_kernel(): void
    {
        app('router')->bind('order', function (string $value): Order {
            $order = Order::findOrFail($value);
            if (auth()->user()?->role === 'customer' && $order->user_id !== auth()->id()) {
                throw (new ModelNotFoundException)->setModel(Order::class, [$value]);
            }

            return $order;
        });
        config(['clearance.routes.include' => ['fixture.protected']]);

        $route = app(Verifier::class)->run($this)['routes']['GET protected/{order}'];
        $this->assertSame(['owned' => 'allow', 'foreign' => 'deny'], $route['access']['customer']);
        $this->assertSame(404, $route['observations']['customer']['foreign']['status']);
        $this->assertSame(401, $route['observations']['guest']['foreign']['status']);
        $this->assertSame(['owned' => 'allow', 'foreign' => 'allow'], $route['access']['admin']);
    }

    public function test_unresolved_nested_bindings_do_not_become_denials(): void
    {
        app('router')->get('/nested/{order}/{otherOrder}', [FixtureController::class, 'nested'])
            ->middleware(['web', 'auth'])->name('fixture.nested');
        app('router')->bind('otherOrder', fn () => throw (new ModelNotFoundException)->setModel(Order::class));
        config(['clearance.routes.include' => ['fixture.nested']]);

        $route = app(Verifier::class)->run($this)['routes']['GET nested/{order}/{otherOrder}'];
        $this->assertSame(['owned' => 'unverified', 'foreign' => 'unverified'], $route['access']['customer']);
        $this->assertNull($route['observations']['customer']['foreign']['status']);
        $this->assertStringContainsString('configure related fixtures', $route['observations']['customer']['foreign']['reason']);
    }

    public function test_ownership_factories_can_build_valid_related_records_without_granting_foreign_access(): void
    {
        app('router')->get('/related/{order}/lines/{line}', [FixtureController::class, 'line'])
            ->middleware(['web', 'auth'])->scopeBindings()->name('fixture.related');
        config(['clearance.routes.include' => ['fixture.related'], 'clearance.personas' => RelatedPersonas::class]);

        $route = app(Verifier::class)->run($this)['routes']['GET related/{order}/lines/{line}'];
        $this->assertSame('deny', $route['access']['guest']);
        $this->assertSame(['owned' => 'allow', 'foreign' => 'deny'], $route['access']['customer']);
        $this->assertSame(['owned' => 'allow', 'foreign' => 'allow'], $route['access']['admin']);
        $this->assertSame(403, $route['observations']['customer']['foreign']['status']);
        $this->assertDatabaseCount('order_lines', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_custom_guard_redirects_remain_unverified_and_later_access_is_detected(): void
    {
        $controller = \Mockery::mock(FixtureController::class)->makePartial();
        $controller->shouldReceive('mutate')->passthru();
        app()->instance(FixtureController::class, $controller);
        $route = app('router')->post('/redirecting/{order}', [FixtureController::class, 'mutate'])
            ->middleware(['web', RedirectingOwnership::class])->name('fixture.redirecting');
        config(['clearance.routes.include' => ['fixture.redirecting']]);
        $this->followingRedirects();

        $before = app(Verifier::class)->run($this);
        $profile = $before['routes']['POST redirecting/{order}'];
        $this->assertSame('deny', $profile['access']['guest']);
        $this->assertSame(['owned' => 'allow', 'foreign' => 'unverified'], $profile['access']['customer']);
        $this->assertSame(['owned' => 'allow', 'foreign' => 'allow'], $profile['access']['admin']);
        $this->assertSame(302, $profile['observations']['customer']['foreign']['status']);
        $this->assertStringContainsString('Redirect', $profile['observations']['customer']['foreign']['reason']);
        $controller->shouldHaveReceived('mutate')->times(3);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('users', 0);

        $route->withoutMiddleware(RedirectingOwnership::class);
        $after = app(Verifier::class)->run($this);
        $this->assertSame('allow', $after['routes']['POST redirecting/{order}']['access']['customer']['foreign']);
        $diff = app(MatrixDiff::class);
        $widenings = array_filter($diff->blocking($diff->compare($before, $after)), fn (array $change) => $change['type'] === 'widening' && $change['persona'] === 'customer');
        $this->assertCount(1, $widenings);
    }

    public function test_login_redirects_use_the_scenario_domain(): void
    {
        app('router')->getRoutes()->getByName('login')->setUri('authenticate')->domain('tenant.example.test');
        app('router')->get('/tenant-protected', fn () => redirect('/authenticate'))
            ->domain('tenant.example.test')->name('fixture.tenant-protected');
        config(['clearance.routes.include' => ['fixture.tenant-protected']]);

        $route = app(Verifier::class)->run($this)['routes']['GET [tenant.example.test] tenant-protected'];
        $this->assertSame('deny', $route['access']['guest']);
        $this->assertSame(302, $route['observations']['guest']['foreign']['status']);
    }

    public function test_404_is_only_a_denial_for_a_bound_foreign_record(): void
    {
        app('router')->get('/missing-page', fn () => abort(404))->name('fixture.missing-page');
        app('router')->get('/missing-record/{order}', [FixtureController::class, 'missing'])
            ->middleware('web')->name('fixture.missing-record');
        config(['clearance.routes.include' => ['fixture.missing-page', 'fixture.missing-record']]);

        $routes = app(Verifier::class)->run($this)['routes'];
        foreach (['guest', 'customer', 'admin'] as $persona) {
            $this->assertSame('unverified', $routes['GET missing-page']['access'][$persona]);
            $this->assertSame(404, $routes['GET missing-page']['observations'][$persona]['foreign']['status']);
        }
        $this->assertSame(['owned' => 'unverified', 'foreign' => 'deny'], $routes['GET missing-record/{order}']['access']['customer']);
        $this->assertSame(404, $routes['GET missing-record/{order}']['observations']['customer']['foreign']['status']);
    }

    public function test_errors_and_unknown_parameters_are_never_classified_as_denials(): void
    {
        config(['clearance.routes.include' => ['fixture.fail', 'fixture.unknown']]);
        $routes = app(Verifier::class)->run($this)['routes'];

        $this->assertSame('error', $routes['GET fail']['access']['guest']);
        $this->assertSame('unverified', $routes['GET unknown/{slug}']['access']['guest']);
    }

    public function test_configured_parameters_resolve_routes_without_model_bindings(): void
    {
        config(['clearance.routes.include' => ['fixture.unknown'], 'clearance.dynamic.parameters' => ['GET unknown/{slug}' => ['slug' => 'example'], 'HEAD unknown/{slug}' => ['slug' => 'example']]]);

        $this->assertSame('allow', app(Verifier::class)->run($this)['routes']['GET unknown/{slug}']['access']['guest']);
    }

    public function test_mutations_are_rolled_back_and_files_are_faked(): void
    {
        app('router')->delete('/mutation/{order}', [FixtureController::class, 'mutate'])->name('fixture.mutation');
        config(['clearance.routes.include' => ['fixture.mutation']]);
        $originalDisk = Storage::disk('local');
        $routes = app(Verifier::class)->run($this)['routes'];

        $this->assertSame(['owned' => 'allow', 'foreign' => 'allow'], $routes['DELETE mutation/{order}']['access']['customer']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertFalse($originalDisk->exists('clearance-side-effect.txt'));
        $this->assertSame($originalDisk, Storage::disk('local'));
    }

    public function test_read_only_routes_and_disabled_methods_remain_unverified(): void
    {
        config(['clearance.routes.include' => ['fixture.update'], 'clearance.dynamic.read_only' => ['fixture.update']]);

        $this->assertSame(['owned' => 'unverified', 'foreign' => 'unverified'], app(Verifier::class)->run($this)['routes']['PUT orders/{order}']['access']['customer']);
    }

    public function test_guest_does_not_inherit_a_previous_authenticated_request(): void
    {
        $this->actingAs(User::factory()->create());
        config(['clearance.routes.include' => ['fixture.protected']]);

        $this->assertSame('deny', app(Verifier::class)->run($this)['routes']['GET protected/{order}']['access']['guest']);
    }

    public function test_production_environment_cannot_execute_dynamic_requests(): void
    {
        config(['clearance.routes.include' => ['fixture.public']]);
        app()->instance('env', 'production');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('testing environment');
        app(Verifier::class)->run($this);
    }

    public function test_disabled_middleware_cannot_produce_an_access_matrix(): void
    {
        $this->withoutMiddleware();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Enable middleware');

        app(Verifier::class)->run($this);
    }

    public function test_closure_validation_is_unverified_when_check_order_cannot_be_resolved(): void
    {
        app('router')->post('/closure-validation', function (Request $request) {
            $request->validate(['title' => 'required']);
            abort(403);
        })->name('fixture.closure-validation');
        config(['clearance.routes.include' => ['fixture.closure-validation']]);

        $this->assertSame('unverified', app(Verifier::class)->run($this)['routes']['POST closure-validation']['access']['guest']);
    }

    public function test_declared_access_expectations_fail_when_the_observed_access_does_not_match(): void
    {
        $matrix = ['routes' => [['access' => ['guest' => 'allow'], 'expectations' => ['guest' => 'deny']]]];

        $this->assertSame(1, Clearance::errors($matrix));
        $matrix['routes'][0]['access']['guest'] = 'deny';
        $this->assertSame(0, Clearance::errors($matrix));
    }

    public function test_repeated_dynamic_runs_produce_identical_matrices(): void
    {
        config(['clearance.routes.include' => ['fixture.public', 'fixture.protected']]);

        $this->assertSame(CanonicalJson::encode(app(Verifier::class)->run($this)), CanonicalJson::encode(app(Verifier::class)->run($this)));
    }

    public function test_individually_disabled_auth_middleware_is_not_verified(): void
    {
        $this->withoutMiddleware(Authenticate::class);
        config(['clearance.routes.include' => ['fixture.protected']]);

        $this->assertSame('unverified', app(Verifier::class)->run($this)['routes']['GET protected/{order}']['access']['guest']);
    }

    public function test_stray_http_requests_are_blocked_and_the_http_client_is_restored(): void
    {
        app('router')->get('/outbound', fn () => Http::get('https://clearance.example.invalid'))->name('fixture.outbound');
        config(['clearance.routes.include' => ['fixture.outbound']]);
        $original = Http::getFacadeRoot();

        $this->assertSame('error', app(Verifier::class)->run($this)['routes']['GET outbound']['access']['guest']);
        $this->assertSame($original, Http::getFacadeRoot());
    }

    public function test_scoped_principals_facades_and_cached_controllers_are_reset_between_scenarios(): void
    {
        app()->scoped('fixture.actor', fn () => auth()->user());
        app('router')->get('/scoped-actor', function () {
            abort_unless(ActorFacade::getFacadeRoot()?->role === 'admin', 403);

            return ['ok' => true];
        })->middleware('web')->name('fixture.scoped-actor');
        app('router')->get('/scoped-controller', ScopedActorController::class)->middleware('web')->name('fixture.scoped-controller');
        config(['clearance.routes.include' => ['fixture.scoped-*']]);
        $matrix = app(Verifier::class)->run($this);
        foreach ($matrix['routes'] as $route) {
            $this->assertSame(['admin' => 'allow', 'customer' => 'deny', 'guest' => 'deny'], $route['access']);
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertNull(ActorFacade::getFacadeRoot());
    }

    public function test_method_override_cannot_attribute_a_sibling_routes_denial_to_the_selected_route(): void
    {
        $calls = ['post' => 0, 'delete' => 0];
        app('router')->post('/method-target', function () use (&$calls) {
            $calls['post']++;

            return ['ok' => true];
        })->middleware('web')->name('fixture.method-post');
        app('router')->delete('/method-target', function () use (&$calls) {
            $calls['delete']++;
            abort(403);
        })->middleware('web')->name('fixture.method-delete');
        config(['clearance.routes.include' => ['fixture.method-post'], 'clearance.dynamic.payloads.POST method-target' => ['_method' => 'DELETE']]);
        $profile = app(Verifier::class)->run($this)['routes']['POST method-target'];
        $this->assertSame(['post' => 0, 'delete' => 3], $calls);
        $this->assertSame(array_fill_keys(['admin', 'customer', 'guest'], 'unverified'), $profile['access']);
        $this->assertSame('routing', $profile['observations']['guest']['foreign']['category']);
        config(['clearance.dynamic.payloads.POST method-target' => []]);
        $plain = app(Verifier::class)->run($this)['routes']['POST method-target'];
        $this->assertSame(array_fill_keys(['admin', 'customer', 'guest'], 'allow'), $plain['access']);
        $this->assertSame(['post' => 3, 'delete' => 3], $calls);
    }

    public function test_inherited_http_allowlists_are_cleared_inside_verification_and_restored_afterwards(): void
    {
        if (! method_exists(Http::getFacadeRoot(), 'allowStrayRequests')) {
            $this->markTestSkipped('This Laravel version has no selective stray-request allowlist.');
        }
        $calls = 0;
        $transport = function () use (&$calls) {
            $calls++;

            return Create::promiseFor(new Response(200, [], '{}'));
        };
        Http::fake(['https://clearance.example.invalid/faked' => Http::response(['fixture' => 'dummy'])]);
        Http::allowStrayRequests(['https://clearance.example.invalid/*']);
        $original = Http::getFacadeRoot();
        $fakes = [];
        app('router')->get('/allowlisted-http', function () use ($transport, &$fakes) {
            $fakes[] = Http::get('https://clearance.example.invalid/faked')->json('fixture');
            Http::withOptions([])->setHandler($transport)->get('https://clearance.example.invalid/write');

            return ['ok' => true];
        })->name('fixture.allowlisted-http');
        config(['clearance.routes.include' => ['fixture.allowlisted-http']]);
        $profile = app(Verifier::class)->run($this)['routes']['GET allowlisted-http'];
        $this->assertSame(0, $calls);
        $this->assertSame(array_fill(0, 6, 'dummy'), $fakes);
        $this->assertSame(array_fill_keys(['admin', 'customer', 'guest'], 'error'), $profile['access']);
        $this->assertSame($original, Http::getFacadeRoot());
        Http::withOptions([])->setHandler($transport)->get('https://clearance.example.invalid/write');
        $this->assertSame(1, $calls);
    }

    public function test_unmatched_route_constraints_cannot_be_reported_as_foreign_record_denials(): void
    {
        app('router')->get('/constrained/{order}', [FixtureController::class, 'show'])->whereNumber('order')
            ->middleware(['web', 'auth'])->name('fixture.constrained');
        config(['clearance.routes.include' => ['fixture.constrained'],
            'clearance.dynamic.parameters.GET constrained/{order}' => ['order' => 'invalid'],
            'clearance.dynamic.parameters.HEAD constrained/{order}' => ['order' => 'invalid']]);
        $matrix = app(Verifier::class)->run($this);
        foreach ($matrix['routes'] as $route) {
            $this->assertSame(['owned' => 'unverified', 'foreign' => 'unverified'], $route['access']['customer']);
            $this->assertSame('unverified', $route['access']['guest']);
            $this->assertSame('routing', $route['observations']['customer']['foreign']['category']);
            $this->assertSame(404, $route['observations']['customer']['foreign']['status']);
        }
    }
}
