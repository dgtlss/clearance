<?php

namespace Dgtlss\Clearance;

use Closure;
use Dgtlss\Clearance\Dynamic\Unverified;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;

final class World
{
    private array $factories = [];

    /** The factory receives the owner and previously built models, keyed by route parameter. */
    public function owns(string $model, Closure $factory): void
    {
        $this->factories[$model] = $factory;
    }

    public function parameters(Route $route, array $profile, string $id, ?Authenticatable $user, string $scope, Persona $persona): array
    {
        $owner = null;
        $parameters = [];
        $models = [];
        $overrides = config('clearance.dynamic.parameters', [])[$id] ?? [];

        foreach ($route->parameterNames() as $name) {
            if (array_key_exists($name, $overrides)) {
                $value = $overrides[$name];
                $value = $value instanceof Closure ? $value($user, $scope, $this) : $value;
            } elseif (isset($profile['bindings'][$name])) {
                $owner ??= $scope === 'owned' && $user !== null ? $user : $this->outsider($persona, $user);
                $class = $profile['bindings'][$name];
                $value = isset($this->factories[$class])
                    ? ($this->factories[$class])($owner, $models)
                    : $this->infer($class, $owner);

                if (! $value instanceof $class || ! $value->exists) {
                    throw new Unverified("Ownership factory for [{$class}] must return a persisted model.", 'ownership');
                }
            } elseif (array_key_exists($name, $route->defaults)) {
                $value = $route->defaults[$name];
            } elseif (str_contains($route->uri(), '{'.$name.'?}')) {
                continue;
            } else {
                throw new Unverified("No scenario value for route parameter [{$name}].", 'parameters');
            }

            if ($value instanceof Model) {
                $models[$name] = $value;
                $field = $route->bindingFieldFor($name) ?? $value->getRouteKeyName();
                $value = $value->getAttribute($field);
            }

            if (! is_scalar($value)) {
                throw new Unverified("Route parameter [{$name}] needs a scalar route key.", 'parameters');
            }

            $parameters[$name] = $value;
        }

        return $parameters;
    }

    private function outsider(Persona $persona, ?Authenticatable $user): Authenticatable
    {
        if ($persona->factory !== null) {
            $outsider = $persona->build();
        } else {
            $guard = config('auth.defaults.guard');
            $provider = config('auth.guards.'.$guard.'.provider');
            $class = config('auth.providers.'.$provider.'.model');

            if (! is_string($class) || ! method_exists($class, 'factory')) {
                throw new Unverified('Configure ownership and a factory-backed auth model to build foreign records.', 'ownership');
            }

            $outsider = $class::factory()->create();
        }

        if ($user !== null && (string) $outsider->getAuthIdentifier() === (string) $user->getAuthIdentifier()) {
            throw new Unverified('The user factory returned the same owner for the foreign scenario.', 'ownership');
        }

        return $outsider;
    }

    private function infer(string $class, Authenticatable $owner): Model
    {
        $model = new $class;

        if (method_exists($class, 'factory')) {
            foreach (['user_id', 'owner_id'] as $column) {
                if ($model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), $column)) {
                    return $class::factory()->create([$column => $owner->getAuthIdentifier()]);
                }
            }
        }

        throw new Unverified("Ownership unknown for [{$class}]; define World::owns().", 'ownership');
    }
}
