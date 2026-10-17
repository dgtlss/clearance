<?php

namespace Dgtlss\Clearance\Tests\Feature;

use Dgtlss\Clearance\Dynamic\Verifier;
use Dgtlss\Clearance\Tests\Fixtures\ApiPersonas;
use Dgtlss\Clearance\Tests\Fixtures\FixtureController;
use Dgtlss\Clearance\Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    public function test_real_sanctum_guard_applies_ownership_and_does_not_leak_authentication_to_guests(): void
    {
        if (! class_exists('Laravel\\Sanctum\\SanctumServiceProvider')) {
            $this->markTestSkipped('Optional API integration job installs the real Sanctum package.');
        }
        app('router')->get('/api/orders/{order}', [FixtureController::class, 'show'])->middleware(['api', 'auth:sanctum'])->name('fixture.api');
        config(['clearance.routes.include' => ['fixture.api'], 'clearance.personas' => ApiPersonas::class]);
        $route = app(Verifier::class)->run($this)['routes']['GET api/orders/{order}'];
        $this->assertSame('deny', $route['access']['guest']);
        $this->assertSame(401, $route['observations']['guest']['foreign']['status']);
        $this->assertSame(['owned' => 'allow', 'foreign' => 'deny'], $route['access']['customer'], json_encode($route['observations']['customer']));
        $this->assertSame(403, $route['observations']['customer']['foreign']['status']);
        $this->assertSame(['owned' => 'allow', 'foreign' => 'allow'], $route['access']['admin']);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('orders', 0);
    }
}
