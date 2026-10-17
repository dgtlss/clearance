<?php

namespace Dgtlss\Clearance\Static\CheckDetectors;

use Dgtlss\Clearance\Static\CheckDetector;
use Illuminate\Support\Facades\Gate;
use PhpParser\Node;
use PhpParser\NodeFinder;

final class AuthorizationDetector implements CheckDetector
{
    public function detect(Node\Stmt\ClassMethod $method): array
    {
        $checks = [];

        foreach ((new NodeFinder)->findInstanceOf($method->stmts ?? [], Node\Expr\CallLike::class) as $call) {
            $name = $call->name ?? null;

            if (! $name instanceof Node\Identifier) {
                continue;
            }

            $name = $name->toString();
            $ability = isset($call->args[0]) && $call->args[0]->value instanceof Node\Scalar\String_
                ? ':'.$call->args[0]->value->value : '';

            if ($call instanceof Node\Expr\StaticCall && $call->class instanceof Node\Name) {
                $class = $call->class->toString();

                if (is_a($class, Gate::class, true)
                    && in_array($name, ['authorize', 'allows', 'denies', 'check', 'any', 'none', 'inspect'], true)) {
                    $checks[] = 'gate:'.$name.$ability;
                }

                if (in_array($class.'@'.$name, config('clearance.detect.custom_checks', []), true)) {
                    $checks[] = 'custom:'.$class.'@'.$name;
                }
            }

            if ($call instanceof Node\Expr\MethodCall && in_array($name, ['authorize', 'can', 'cannot', 'cant'], true)) {
                $checks[] = 'method:'.$name.$ability;
            }
        }

        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (in_array($attribute->name->getLast(), ['Authorize', 'Can'], true)) {
                    $checks[] = 'attribute:'.$attribute->name->toString();
                }
            }
        }

        return array_values(array_unique($checks));
    }
}
