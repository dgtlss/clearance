<?php

namespace Dgtlss\Clearance\Tests\Feature;

use Dgtlss\Clearance\Clearance;
use Dgtlss\Clearance\Report\Findings;
use Dgtlss\Clearance\Static\AstIndex;
use Dgtlss\Clearance\Static\CheckDetector;
use Dgtlss\Clearance\Static\CheckDetectors\AuthorizationDetector;
use Dgtlss\Clearance\Static\PolicyAnalyzer;
use Dgtlss\Clearance\Tests\Fixtures\FixtureController;
use Dgtlss\Clearance\Tests\Fixtures\MissingActionController;
use Dgtlss\Clearance\Tests\Fixtures\Order;
use Dgtlss\Clearance\Tests\Fixtures\UpdateOrderRequest;
use Dgtlss\Clearance\Tests\TestCase;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use PhpParser\Node\Stmt\ClassMethod;
use RuntimeException;

class StaticScanTest extends TestCase
{
    public function test_inventory_distinguishes_authorization_from_authentication_and_unknown(): void
    {
        $routes = app(Clearance::class)->scan()['routes'];

        $this->assertSame('public', $routes['GET public']['verdict']);
        $this->assertSame('auth-only', $routes['GET broken/{order}']['verdict']);
        $this->assertSame('guarded', $routes['GET protected/{order}']['verdict']);
        $this->assertSame('guarded', $routes['GET middleware/{order}']['verdict']);
        $this->assertSame('unknown', $routes['GET closure']['verdict']);
        $this->assertSame(['order' => Order::class], $routes['GET broken/{order}']['bindings']);
        $this->assertSame('policy', $routes['PUT orders/{order}']['form_requests'][UpdateOrderRequest::class]);
        $this->assertTrue($routes['POST late/{order}']['validation_before_authorization']);
        $this->assertTrue($routes['POST validated-late/{order}']['validation_before_authorization']);
        $this->assertFalse($routes['PUT orders/{order}']['validation_before_authorization']);
    }

    public function test_policy_analysis_checks_the_body_for_the_model_parameter(): void
    {
        $analyzer = app(PolicyAnalyzer::class);

        $this->assertTrue($analyzer->analyze(Order::class, 'view')['instance_aware']);
        $this->assertFalse($analyzer->analyze(Order::class, 'delete')['instance_aware']);
        $this->assertFalse($analyzer->analyze(Order::class, 'nonexistent')['resolved']);
    }

    public function test_model_parameters_follow_laravels_snake_case_fallback_and_exact_name_precedence(): void
    {
        app('router')->get('/snake/{order_record}', [FixtureController::class, 'snakeCase'])->name('fixture.snake');
        app('router')->get('/exact/{orderRecord}/{order_record}', [FixtureController::class, 'snakeCase'])->name('fixture.exact');
        config(['clearance.routes.include' => ['fixture.snake', 'fixture.exact']]);

        $routes = app(Clearance::class)->scan()['routes'];
        $this->assertSame(['order_record' => Order::class], $routes['GET snake/{order_record}']['bindings']);
        $this->assertSame(['orderRecord' => Order::class], $routes['GET exact/{orderRecord}/{order_record}']['bindings']);
    }

    public function test_ast_resolves_imported_gate_calls(): void
    {
        $node = app(AstIndex::class)->method(FixtureController::class, 'show');

        $this->assertSame(['gate:authorize:view'], (new AuthorizationDetector)->detect($node));
    }

    public function test_routes_on_different_domains_do_not_overwrite_each_other(): void
    {
        $router = app('router');
        $router->domain('one.example.test')->get('/same', [FixtureController::class, 'index'])->name('fixture.one');
        $router->domain('two.example.test')->get('/same', [FixtureController::class, 'index'])->name('fixture.two');
        $routes = app(Clearance::class)->scan()['routes'];

        $this->assertArrayHasKey('GET [one.example.test] same', $routes);
        $this->assertArrayHasKey('GET [two.example.test] same', $routes);
    }

    public function test_scan_supports_json_filters_and_does_not_touch_the_database(): void
    {
        $this->artisan('clearance:scan', ['--json' => true, '--route' => 'fixture.protected'])
            ->expectsOutputToContain('GET protected/{order}')
            ->assertSuccessful();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_owner_query_signals_are_recorded_as_heuristics(): void
    {
        app('router')->get('/scoped', [FixtureController::class, 'scoped'])->middleware('auth')->name('fixture.scoped');
        $route = app(Clearance::class)->scan()['routes']['GET scoped'];

        $this->assertContains('query:owner-column:user_id', $route['guards']['scoping']);
        $this->assertSame('auth-only', $route['verdict']);
    }

    public function test_actual_controller_ability_selects_the_policy_method(): void
    {
        app('router')->post('/remove/{order}', [FixtureController::class, 'destroy'])->name('fixture.remove');
        $route = app(Clearance::class)->scan()['routes']['POST remove/{order}'];

        $this->assertSame('delete', $route['guards']['policies'][0]['method']);
        $this->assertFalse($route['guards']['policies'][0]['instance_aware']);
    }

    public function test_risky_filter_keeps_guarded_routes_with_instance_blind_policies(): void
    {
        app('router')->post('/remove/{order}', [FixtureController::class, 'destroy'])->name('fixture.remove');

        $this->artisan('clearance:scan', ['--only' => 'risky', '--json' => true])->expectsOutputToContain('POST remove/{order}')->assertSuccessful();
    }

    public function test_scoped_bindings_are_recorded_in_the_guard_profile(): void
    {
        app('router')->get('/scoped/{order}', [FixtureController::class, 'show'])->scopeBindings()->name('fixture.scoped-binding');

        $this->assertContains('binding:scoped', app(Clearance::class)->scan()['routes']['GET scoped/{order}']['guards']['scoping']);
    }

    public function test_sources_outside_the_host_app_use_portable_composer_paths(): void
    {
        $route = app(Clearance::class)->scan()['routes']['GET protected/{order}'];

        $this->assertSame('vendor/dgtlss/clearance/tests/Fixtures/FixtureController.php', $route['source']['file']);
        $this->assertStringNotContainsString(base_path(), $route['source']['file']);
    }

    public function test_missing_controller_methods_remain_unknown_with_actionable_diagnostics(): void
    {
        app('router')->get('/missing-action/{order}', [MissingActionController::class, 'missing'])
            ->middleware('can:view,order')->name('fixture.missing-action');
        config(['clearance.routes.include' => ['fixture.missing-action']]);
        $matrix = app(Clearance::class)->scan();

        foreach (['GET', 'HEAD'] as $method) {
            $route = $matrix['routes'][$method.' missing-action/{order}'];
            $this->assertSame('unknown', $route['verdict']);
            $this->assertSame(['Unresolved controller action ['.MissingActionController::class.'@missing]: method does not exist.'], $route['diagnostics']);
            $this->assertContains(Authenticate::class, $route['guards']['middleware']);
            $this->assertContains(Authorize::class.':view,order', $route['guards']['middleware']);
            $this->assertSame([], $route['bindings']);
            $this->assertSame(['unverified'], array_values(array_unique($route['access'])));
        }

        $findings = app(Findings::class)->forMatrix($matrix);
        $this->assertCount(2, $findings);
        foreach ($findings as $finding) {
            $this->assertStringContainsString('Unresolved controller action', $finding['message']);
            $this->assertStringContainsString(MissingActionController::class.'@missing', $finding['message']);
        }

        $this->artisan('clearance:scan', ['--only' => 'risky'])
            ->expectsOutputToContain(MissingActionController::class.'@missing')->assertSuccessful();
    }

    public function test_missing_controller_classes_preserve_expanded_route_middleware_and_exclusions(): void
    {
        $class = 'Missing\\Controller';
        $router = app('router');
        $router->middlewareGroup('fixture-guards', ['auth', 'can:view,order', 'throttle:60,1']);
        $router->get('/missing-controller', [$class, 'show'])->middleware('fixture-guards')
            ->withoutMiddleware('throttle:60,1')->name('fixture.missing-controller');
        config(['clearance.routes.include' => ['fixture.missing-controller']]);

        foreach (app(Clearance::class)->scan()['routes'] as $route) {
            $this->assertSame('unknown', $route['verdict']);
            $this->assertSame(['Unresolved controller action ['.$class.'@show]: controller class does not exist.'], $route['diagnostics']);
            $this->assertContains(Authenticate::class, $route['guards']['middleware']);
            $this->assertContains(Authorize::class.':view,order', $route['guards']['middleware']);
            $this->assertNotContains('Illuminate\\Routing\\Middleware\\ThrottleRequests:60,1', $route['guards']['middleware']);
            $this->assertSame(['unverified'], array_values(array_unique($route['access'])));
        }
    }

    public function test_unexpected_analyzer_exceptions_are_still_reported_as_failures(): void
    {
        $detector = new class implements CheckDetector
        {
            public function detect(ClassMethod $method): array
            {
                throw new RuntimeException('Synthetic analyzer failure');
            }
        };
        app()->instance($detector::class, $detector);
        config(['clearance.routes.include' => ['fixture.public'], 'clearance.detect.detectors' => [$detector::class]]);

        foreach (app(Clearance::class)->scan()['routes'] as $route) {
            $this->assertSame('unknown', $route['verdict']);
            $this->assertSame(['Static analysis failed: Synthetic analyzer failure'], $route['diagnostics']);
            $this->assertSame(['unverified'], array_values(array_unique($route['access'])));
        }
    }
}
