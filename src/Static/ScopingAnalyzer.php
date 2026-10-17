<?php

namespace Dgtlss\Clearance\Static;

use PhpParser\Node;
use PhpParser\NodeFinder;

final class ScopingAnalyzer
{
    public function __construct(private readonly AstIndex $ast) {}

    /** Heuristic signals only; these do not establish a guarded verdict. */
    public function analyze(Node\Stmt\ClassMethod $method, array $models): array
    {
        $signals = [];
        $finder = new NodeFinder;

        foreach ($finder->findInstanceOf($method->stmts ?? [], Node\Expr\MethodCall::class) as $call) {
            if (! $call->name instanceof Node\Identifier) {
                continue;
            }

            if (in_array($call->name->toString(), ['where', 'whereBelongsTo'], true)
                && isset($call->args[0]) && $call->args[0]->value instanceof Node\Scalar\String_
                && in_array($call->args[0]->value->value, ['user_id', 'owner_id', 'tenant_id'], true)) {
                $signals[] = 'query:owner-column:'.$call->args[0]->value->value;
            }

            $parent = $call->var;

            if ($parent instanceof Node\Expr\MethodCall && $parent->name instanceof Node\Identifier
                && $parent->name->toString() === 'user') {
                $signals[] = 'query:user-relation:'.$call->name->toString();
            }
        }

        foreach (array_unique($models) as $model) {
            foreach (['boot', 'booted'] as $hook) {
                $node = $this->ast->method($model, $hook);

                if ($node && $finder->findFirst($node->stmts ?? [], fn (Node $n) => $n instanceof Node\Expr\StaticCall && $n->name instanceof Node\Identifier
                    && $n->name->toString() === 'addGlobalScope')) {
                    $signals[] = 'model:global-scope:'.$model;
                }
            }
        }

        $signals = array_values(array_unique($signals));
        sort($signals);

        return $signals;
    }
}
