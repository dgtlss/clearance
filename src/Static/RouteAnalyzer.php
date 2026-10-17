<?php

namespace Dgtlss\Clearance\Static;

use Closure;
use Dgtlss\Clearance\Attributes\Expect;
use Dgtlss\Clearance\Discovery\MiddlewareResolver;
use Dgtlss\Clearance\Static\CheckDetectors\AuthorizationDetector;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\NodeFinder;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

final class RouteAnalyzer
{
    public function __construct(
        private readonly AstIndex $ast,
        private readonly MiddlewareResolver $middleware,
        private readonly PolicyAnalyzer $policies,
        private readonly SourceLocation $sources,
    ) {}

    public function analyze(Route $route, string $method): array
    {
        $profile = [
            'method' => $method,
            'uri' => $route->uri(),
            'domain' => $route->getDomain(),
            'name' => $route->getName(),
            'action' => $route->getActionName(),
            'guards' => ['middleware' => [], 'checks' => [], 'policies' => [], 'scoping' => []],
            'bindings' => [],
            'binding_fields' => $route->bindingFields(),
            'scoped_bindings' => $route->enforcesScopedBindings(),
            'form_requests' => [],
            'expectations' => [],
            'validation_before_authorization' => true,
            'verdict' => 'unknown',
            'diagnostics' => [],
            'source' => null,
            'access' => [],
            'approved' => null,
        ];

        try {
            $stack = $this->middleware->resolve($route);
            $profile['guards']['middleware'] = $stack;
            $abilityMiddleware = array_filter($stack, $this->middleware->isAbility(...));
            $authenticated = array_filter($stack, $this->middleware->isAuthentication(...)) !== [];
            $action = $route->getAction('uses');
            $reflection = $action instanceof Closure ? new ReflectionFunction($action) : null;
            $node = null;

            if (is_string($action) && str_contains($action, '@')) {
                [$class, $actionMethod] = explode('@', $action, 2);

                if (! class_exists($class) || ! method_exists($class, $actionMethod)) {
                    $reason = class_exists($class) ? 'method does not exist' : 'controller class does not exist';
                    $profile['diagnostics'][] = "Unresolved controller action [{$class}@{$actionMethod}]: {$reason}.";

                    return $profile;
                }

                $reflection = new ReflectionMethod($class, $actionMethod);
                $expectations = $reflection->getAttributes(Expect::class);
                $profile['expectations'] = $expectations !== [] ? $expectations[0]->newInstance()->personas : [];
                $node = $this->ast->method($class, $actionMethod);
                $constructor = $this->ast->method($class, '__construct');

                if ($constructor && (new NodeFinder)->findFirst($constructor->stmts ?? [], fn (Node $n) => $n instanceof Node\Expr\MethodCall && $n->name instanceof Node\Identifier
                    && $n->name->toString() === 'authorizeResource')) {
                    // Constructor registration is a signal; the resolved middleware proves which action is protected.
                    $profile['diagnostics'][] = 'Controller registers authorizeResource middleware.';
                }
            }

            if ($reflection) {
                $file = $reflection->getFileName();
                $profile['source'] = $file ? $this->sources->forFile($file, $reflection->getStartLine()) : null;

                foreach ($reflection->getParameters() as $parameter) {
                    $type = $parameter->getType();

                    if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                        continue;
                    }

                    $parameterClass = $type->getName();

                    if (is_a($parameterClass, Model::class, true)) {
                        $name = $parameter->getName();
                        if (! in_array($name, $route->parameterNames(), true)) {
                            $name = Str::snake($name);
                        }
                        if (in_array($name, $route->parameterNames(), true)) {
                            $profile['bindings'][$name] = $parameterClass;
                        }
                    }

                    if (is_a($parameterClass, FormRequest::class, true)) {
                        $classification = $this->formRequest($parameterClass);
                        $profile['form_requests'][$parameterClass] = $classification;

                        if ($classification === 'policy' || $classification === 'false') {
                            $profile['guards']['checks'][] = 'formrequest:'.$parameterClass.':'.$classification;
                        }

                        if ($classification === 'policy') {
                            foreach ((new AuthorizationDetector)->detect($this->ast->method($parameterClass, 'authorize')) as $check) {
                                $profile['guards']['checks'][] = 'formrequest:'.$parameterClass.'->'.$check;
                            }
                        }

                        if (in_array($classification, ['missing', 'custom'], true)) {
                            $profile['diagnostics'][] = "FormRequest [{$parameterClass}] authorization is {$classification}.";
                        }
                    }
                }
            }

            if ($node) {
                $profile['guards']['scoping'] = app(ScopingAnalyzer::class)->analyze($node, array_values($profile['bindings']));
                foreach ([AuthorizationDetector::class, ...config('clearance.detect.detectors', [])] as $detector) {
                    $profile['guards']['checks'] = array_merge($profile['guards']['checks'], app($detector)->detect($node));
                }

                $calls = (new NodeFinder)->find($node->stmts ?? [], fn (Node $n) => ($n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\StaticCall)
                    && $n->name instanceof Node\Identifier);
                $validation = [];
                $authorization = [];

                foreach ($calls as $call) {
                    if (in_array($call->name->toString(), ['validate', 'validateWithBag', 'validated'], true)) {
                        $validation[] = $call->getStartFilePos();
                    }
                    if (in_array($call->name->toString(), ['authorize', 'can', 'cannot', 'allows', 'denies'], true)) {
                        $authorization[] = $call->getStartFilePos();
                    }
                }

                // FormRequests validate before entering the controller, including before controller authorization.
                $profile['validation_before_authorization'] = $authorization !== [] && (
                    $profile['form_requests'] !== [] || ($validation !== [] && min($validation) < min($authorization))
                );
            }

            foreach ($profile['bindings'] as $model) {
                foreach ($this->abilities($route, $method, $abilityMiddleware, $profile['guards']['checks']) as $ability) {
                    $policy = $this->policies->analyze($model, $ability);

                    if ($policy !== null && ! in_array($policy, $profile['guards']['policies'], true)) {
                        $profile['guards']['policies'][] = $policy;
                    }
                }
            }

            if ($profile['scoped_bindings']) {
                $profile['guards']['scoping'][] = 'binding:scoped';
                sort($profile['guards']['scoping']);
            }

            $checks = array_values(array_unique($profile['guards']['checks']));
            sort($checks);
            $profile['guards']['checks'] = $checks;
            ksort($profile['bindings']);
            ksort($profile['form_requests']);
            $profile['verdict'] = match (true) {
                $abilityMiddleware !== [] || $checks !== [] => 'guarded',
                $node === null => 'unknown',
                $authenticated => 'auth-only',
                default => 'public',
            };
        } catch (Throwable $e) {
            $profile['diagnostics'][] = 'Static analysis failed: '.$e->getMessage();
            $profile['verdict'] = 'unknown';
        }

        return $profile;
    }

    private function formRequest(string $class): string
    {
        $method = $this->ast->method($class, 'authorize');

        if ($method === null) {
            return 'missing';
        }

        $statements = $method->stmts ?? [];

        if (count($statements) === 1 && $statements[0] instanceof Node\Stmt\Return_
            && $statements[0]->expr instanceof Node\Expr\ConstFetch) {
            $literal = strtolower($statements[0]->expr->name->toString());

            if (in_array($literal, ['true', 'false'], true)) {
                return $literal;
            }
        }

        return (new AuthorizationDetector)->detect($method) !== [] ? 'policy' : 'custom';
    }

    private function abilities(Route $route, string $method, array $middleware, array $checks): array
    {
        $abilities = [];

        foreach ($middleware as $entry) {
            $class = explode(':', $entry, 2)[0];

            if (str_contains($entry, ':') && ($class === 'can' || is_a($class, Authorize::class, true))) {
                $abilities[] = explode(',', explode(':', $entry, 2)[1], 2)[0];
            }
        }

        foreach ($checks as $check) {
            if (preg_match('/(?:gate|method):(?:authorize|allows|denies|check|any|none|inspect|can|cannot|cant):([^:]+)$/', $check, $matches)) {
                $abilities[] = $matches[1];
            }
        }

        if ($abilities !== []) {
            $abilities = array_values(array_unique($abilities));
            sort($abilities);

            return $abilities;
        }

        return [match ($route->getActionMethod()) {
            'show' => 'view',
            'edit', 'update' => 'update',
            'destroy' => 'delete',
            default => in_array($method, ['GET', 'HEAD'], true) ? 'view' : 'update',
        }];
    }
}
