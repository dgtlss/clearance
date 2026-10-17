<?php

namespace Dgtlss\Clearance\Tests;

use Dgtlss\Clearance\ClearanceServiceProvider;
use Dgtlss\Clearance\Tests\Fixtures\FixtureController;
use Dgtlss\Clearance\Tests\Fixtures\FixturePersonas;
use Dgtlss\Clearance\Tests\Fixtures\Order;
use Dgtlss\Clearance\Tests\Fixtures\OrderPolicy;
use Dgtlss\Clearance\Tests\Fixtures\User;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ClearanceServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('app.url', 'http://localhost');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('clearance.personas', FixturePersonas::class);
        $app['config']->set('clearance.routes.include', ['fixture.*']);
        $app['config']->set('clearance.dynamic.timeout_ms', 10000);
        $app['config']->set('filesystems.disks', ['local' => ['driver' => 'local', 'root' => sys_get_temp_dir().'/clearance-fixture-storage']]);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('customer');
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->string('title');
            $table->timestamps();
        });

        Gate::policy(Order::class, OrderPolicy::class);
    }

    protected function defineRoutes($router): void
    {
        $router->aliasMiddleware('auth', Authenticate::class);
        $router->aliasMiddleware('can', Authorize::class);
        $router->middleware('web')->name('fixture.')->group(function (Router $router) {
            $router->get('/public', [FixtureController::class, 'index'])->name('public');
            $router->get('/broken/{order}', [FixtureController::class, 'broken'])->middleware('auth')->name('broken');
            $router->get('/protected/{order}', [FixtureController::class, 'show'])->middleware('auth')->name('protected');
            $router->put('/orders/{order}', [FixtureController::class, 'update'])->middleware('auth')->name('update');
            $router->post('/late/{order}', [FixtureController::class, 'late'])->middleware('auth')->name('late');
            $router->post('/validated-late/{order}', [FixtureController::class, 'validatedLate'])->middleware('auth')->name('validated-late');
            $router->get('/middleware/{order}', [FixtureController::class, 'broken'])->middleware(['auth', 'can:view,order'])->name('middleware');
            $router->get('/fail', [FixtureController::class, 'fail'])->name('fail');
            $router->get('/unknown/{slug}', [FixtureController::class, 'slug'])->name('unknown');
            $router->get('/closure', fn () => 'public')->name('closure');
        });
        $router->get('/login', fn () => 'login')->name('login');
    }
}
