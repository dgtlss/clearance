<?php

namespace Dgtlss\Clearance\Dynamic;

use Dgtlss\Clearance\Discovery\RouteCollector;
use Dgtlss\Clearance\Persona;
use Dgtlss\Clearance\World;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

final class RequestRunner
{
    public function __construct(private readonly PayloadBuilder $payloads, private readonly OutcomeClassifier $classifier) {}

    public function run(TestCase $test, Route $route, array $profile, string $id, Persona $persona, string $scope, World $world): array
    {
        if (! app()->environment('testing') || ! app()->runningUnitTests()) {
            throw new RuntimeException('Dynamic verification may only run inside the testing environment and test runner.');
        }

        $method = $profile['method'];

        if (! in_array($method, config('clearance.dynamic.methods', []), true)
            || (! in_array($method, ['GET', 'HEAD'], true) && RouteCollector::matches(
                config('clearance.dynamic.read_only', []), [$id, $route->getName() ?? '', $route->uri()],
            ))) {
            return ['outcome' => 'unverified', 'status' => null, 'category' => 'excluded', 'reason' => 'Excluded from dynamic verification.'];
        }

        $connections = [];
        $originals = [];
        $defaultGuard = app('auth')->getDefaultDriver();
        $originalRequest = app('request');
        $start = hrtime(true);

        try {
            foreach ($profile['guards']['middleware'] as $middleware) {
                $class = explode(':', $middleware, 2)[0];

                if (class_exists($class) && app()->bound($class) && ! app($class) instanceof $class) {
                    throw new Unverified("Middleware [{$class}] was replaced by the host test case.", 'middleware');
                }
            }

            foreach ($this->fakes() as $name => $facade) {
                $originals[$facade] = $facade::getFacadeRoot();

                if (in_array($name, ['http', 'storage'], true)) {
                    $facade::swap(clone $originals[$facade]);
                }

                if ($name === 'http') {
                    $facade::preventStrayRequests();
                } elseif ($name === 'storage') {
                    foreach (array_keys(config('filesystems.disks', [])) as $disk) {
                        $facade::fake($disk);
                    }
                } else {
                    $facade::fake();
                }
            }

            // Additional application connections must be listed in dynamic.connections to be rolled back.
            $connectionNames = array_unique([config('database.default'), ...config('clearance.dynamic.connections', [])]);

            foreach ($connectionNames as $name) {
                $connection = DB::connection($name);
                $connections[$name] = [$connection, $connection->transactionLevel()];
                $connection->beginTransaction();
            }

            app('auth')->forgetGuards();
            app('auth')->shouldUse($defaultGuard);

            if (app()->bound('session')) {
                app('session')->flush();
            }

            $user = $persona->build();

            if ($user !== null) {
                $test->actingAs($user, $persona->guardName);
            }

            $parameters = $world->parameters($route, $profile, $id, $user, $scope, $persona);
            $url = app('url')->toRoute($route, $parameters, true);
            $request = Request::create($url, $method);
            $request->setUserResolver(fn () => $user);
            $boundRoute = clone $route;
            $boundRoute->bind($request);
            $request->setRouteResolver(fn () => $boundRoute);
            try {
                app('router')->substituteBindings($boundRoute);
                app('router')->substituteImplicitBindings($boundRoute);
            } catch (ModelNotFoundException) {
                if (count($profile['bindings']) !== 1 || count($route->parameterNames()) !== 1) {
                    throw new Unverified('Route bindings could not be prepared for multiple parameters; configure related fixtures.', 'bindings');
                }
                // Observe scoped binding denials through the real kernel, after authentication middleware.
                $payload = [];
            }
            $payload ??= $this->payloads->build($route, $profile, $id, $request);
            $uploads = $this->uploads($payload);

            // Use the kernel directly so a host test's followingRedirects() cannot hide a login redirect.
            $httpRequest = Request::create($url, $method, $payload, [], $uploads, ['HTTP_ACCEPT' => 'application/json']);
            $kernel = app(Kernel::class);
            $response = $kernel->handle($httpRequest);
            $kernel->terminate($httpRequest, $response);
            $status = $response->getStatusCode();
            $outcome = $this->classifier->classify(
                $status,
                $response->headers->get('Location'),
                $scope === 'foreign' && $profile['bindings'] !== [],
                $profile['validation_before_authorization'],
                $url,
            );

            if ($outcome === 'allow' && $httpRequest->route() === null) {
                return ['outcome' => 'unverified', 'status' => $status, 'category' => 'routing', 'reason' => 'Response was returned before the intended route was resolved.'];
            }

            if ($httpRequest->route() !== null && RouteCollector::id($httpRequest->route(), $method) !== $id) {
                return ['outcome' => 'unverified', 'status' => $status, 'category' => 'routing', 'reason' => 'Request matched a different route.'];
            }

            if ((hrtime(true) - $start) / 1_000_000 > config('clearance.dynamic.timeout_ms', 2000)) {
                return ['outcome' => 'error', 'status' => $status, 'category' => 'timeout', 'reason' => 'Request exceeded timeout_ms.'];
            }

            return ['outcome' => $outcome, 'status' => $status, 'category' => match ($outcome) {
                'allow', 'deny' => 'verified',
                'error' => 'http_error',
                default => $status >= 300 && $status < 400 ? 'redirect' : 'ambiguous_response',
            }, 'reason' => match ($outcome) {
                'allow' => 'Request passed the access boundary.',
                'deny' => 'Request was denied.',
                'error' => 'Server error; access could not be verified.',
                default => $status >= 300 && $status < 400
                    ? 'Redirect does not prove whether access was allowed or denied.'
                    : 'Response does not prove authorization completed.',
            }];
        } catch (Unverified $e) {
            return ['outcome' => 'unverified', 'status' => null, 'category' => $e->category, 'reason' => $e->getMessage()];
        } catch (Throwable $e) {
            return ['outcome' => 'error', 'status' => null, 'category' => 'scenario_error', 'reason' => 'Scenario failed: '.$e::class];
        } finally {
            $endedTransaction = false;
            foreach (array_reverse($connections) as [$connection, $level]) {
                $endedTransaction = $endedTransaction || $connection->transactionLevel() <= $level;
                $connection->rollBack($level);
            }

            foreach ($originals as $facade => $original) {
                $facade::swap($original);
            }

            if (isset($originals[Event::class])) {
                Model::setEventDispatcher($originals[Event::class]);
            }

            app('auth')->forgetGuards();
            app('auth')->shouldUse($defaultGuard);
            app()->instance('request', $originalRequest);
            \Illuminate\Support\Facades\Request::clearResolvedInstance('request');
            if ($endedTransaction) {
                throw new RuntimeException('Application code ended a Clearance transaction. Verification stopped; use a disposable testing database and isolate this route.');
            }
        }
    }

    private function uploads(array &$payload): array
    {
        $files = [];
        foreach ($payload as $key => &$value) {
            if ($value instanceof UploadedFile) {
                $files[$key] = $value;
                unset($payload[$key]);
            } elseif (is_array($value)) {
                $nested = $this->uploads($value);
                if ($nested !== []) {
                    $files[$key] = $nested;
                }
            }
        }

        return $files;
    }

    private function fakes(): array
    {
        return array_intersect_key([
            'mail' => Mail::class,
            'notifications' => Notification::class,
            'queue' => Queue::class,
            'events' => Event::class,
            'http' => Http::class,
            'storage' => Storage::class,
        ], array_flip(config('clearance.dynamic.fake', [])));
    }
}
