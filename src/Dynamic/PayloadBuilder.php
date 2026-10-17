<?php

namespace Dgtlss\Clearance\Dynamic;

use Dgtlss\Clearance\Attributes\Payload;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use ReflectionMethod;
use Stringable;
use Throwable;

final class PayloadBuilder
{
    public function build(Route $route, array $profile, string $id, Request $request): array
    {
        $overrides = config('clearance.dynamic.payloads', []);

        if (array_key_exists($id, $overrides)) {
            return $overrides[$id] instanceof \Closure ? ($overrides[$id])($request) : $overrides[$id];
        }

        $action = $route->getActionName();

        if (str_contains($action, '@')) {
            [$class, $method] = explode('@', $action, 2);
            $attributes = (new ReflectionMethod($class, $method))->getAttributes(Payload::class);

            if ($attributes !== []) {
                return $attributes[0]->newInstance()->values;
            }
        }

        $payload = [];

        foreach (array_keys($profile['form_requests']) as $class) {
            try {
                // Resolving a FormRequest through the container would authorize and validate it prematurely.
                $form = $class::createFrom($request, new $class);
                $form->setContainer(app())->setRedirector(app('redirect'));
                $rules = method_exists($form, 'rules') ? app()->call([$form, 'rules']) : [];
                $payload = array_replace_recursive($payload, $this->fromRules($rules));
            } catch (Throwable) {
                // Complex rules need an override; the response classifier still handles validation failures.
            }
        }

        return $payload;
    }

    public function fromRules(array $rules): array
    {
        $payload = [];

        foreach ($rules as $field => $definition) {
            $definition = is_array($definition) ? $definition : explode('|', $definition);
            $parts = [];

            foreach ($definition as $rule) {
                if (is_string($rule) || $rule instanceof Stringable) {
                    $parts[] = (string) $rule;
                }
            }

            $types = array_map(fn (string $rule) => explode(':', $rule, 2)[0], $parts);

            if (! array_intersect($types, ['required', 'present', 'accepted'])) {
                continue;
            }

            $value = match (true) {
                in_array('accepted', $types, true), in_array('boolean', $types, true) => true,
                in_array('integer', $types, true), in_array('numeric', $types, true) => 1,
                in_array('array', $types, true) => [],
                in_array('email', $types, true) => 'clearance@example.test',
                in_array('uuid', $types, true) => '00000000-0000-4000-8000-000000000001',
                in_array('date', $types, true) => '2026-01-01',
                in_array('url', $types, true) => 'https://example.test',
                default => 'clearance',
            };

            foreach ($parts as $part) {
                if (str_starts_with($part, 'in:')) {
                    $value = str_getcsv(substr($part, 3), ',', '"', '\\')[0];
                }
            }

            $field = str_replace('*', '0', $field);
            Arr::set($payload, $field, $value);

            if (in_array('confirmed', $types, true)) {
                Arr::set($payload, $field.'_confirmation', $value);
            }
        }

        return $payload;
    }
}
