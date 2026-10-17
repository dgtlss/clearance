<?php

namespace Dgtlss\Clearance\Static;

use Illuminate\Contracts\Auth\Access\Gate;
use PhpParser\Node;
use PhpParser\NodeFinder;

final class PolicyAnalyzer
{
    public function __construct(private readonly AstIndex $ast, private readonly Gate $gate) {}

    public function analyze(string $model, string $ability): ?array
    {
        $policy = $this->gate->getPolicyFor($model);

        if ($policy === null) {
            return null;
        }

        $method = $this->ast->method($policy::class, $ability);
        $parameter = $method?->params[1]->var->name ?? null;
        $aware = $parameter !== null && (new NodeFinder)->findFirst(
            $method->stmts ?? [],
            fn (Node $node) => $node instanceof Node\Expr\Variable && $node->name === $parameter,
        ) !== null;

        return [
            'class' => $policy::class,
            'method' => $ability,
            'instance_aware' => $aware,
            'resolved' => $method !== null,
        ];
    }
}
