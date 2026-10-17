<?php

namespace Dgtlss\Clearance\Tests\Feature;

use Dgtlss\Clearance\Dynamic\Verifier;
use Dgtlss\Clearance\Tests\Fixtures\FixtureController;
use Dgtlss\Clearance\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseIsolationTest extends TestCase
{
    public function test_real_owned_and_foreign_requests_rollback_both_connections(): void
    {
        Schema::connection('secondary')->dropAllTables();
        Schema::connection('secondary')->create('audit_entries', function (Blueprint $table) {
            $table->id();
            $table->string('message');
        });
        app('router')->post('/dual/{order}', [FixtureController::class, 'dualWrite'])->middleware(['web', 'auth'])->name('fixture.dual');
        config(['clearance.routes.include' => ['fixture.dual'], 'clearance.dynamic.connections' => ['secondary']]);
        $route = app(Verifier::class)->run($this)['routes']['POST dual/{order}'];
        $this->assertSame('deny', $route['access']['guest']);
        $this->assertSame(['owned' => 'allow', 'foreign' => 'deny'], $route['access']['customer']);
        $this->assertSame(['owned' => 'allow', 'foreign' => 'allow'], $route['access']['admin']);
        $this->assertSame(200, $route['observations']['customer']['owned']['status']);
        $this->assertSame(403, $route['observations']['customer']['foreign']['status']);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, DB::connection('secondary')->table('audit_entries')->count());
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertSame(0, DB::connection('secondary')->transactionLevel());
    }
}
