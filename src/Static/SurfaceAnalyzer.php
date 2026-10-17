<?php

namespace Dgtlss\Clearance\Static;

use Composer\InstalledVersions;
use Dgtlss\Clearance\Static\CheckDetectors\AuthorizationDetector;
use PhpParser\Node;
use PhpParser\NodeFinder;

final class SurfaceAnalyzer
{
    public function __construct(private readonly AstIndex $ast, private readonly SourceLocation $sources) {}

    public function profile(string $kind, string $class, array $source): array
    {
        return ['kind' => $kind, 'class' => $class, 'evidence' => 'static',
            'guards' => ['middleware' => [], 'checks' => [], 'policies' => [], 'scoping' => [], 'evidence' => []],
            'access' => [], 'verdict' => 'unknown', 'diagnostics' => [], 'source' => $source, 'approved' => null];
    }

    public function action(string $class, Node\Stmt\ClassMethod $method, string $path, array $locked): array
    {
        $profile = $this->profile('livewire', $class, $this->sources->forFile($path, $method->getStartLine()));
        $profile['method'] = $method->name->toString();
        $profile['locked_properties'] = $locked;
        $profile['guards']['checks'] = $this->checks($method);
        $profile['guards']['scoping'] = array_map(fn ($name) => 'locked:'.$name, $locked);
        $profile['guards']['evidence'] = ['body:'.$this->ast->fingerprint($method),
            'framework:livewire/livewire@'.(InstalledVersions::isInstalled('livewire/livewire') ? InstalledVersions::getPrettyVersion('livewire/livewire') : 'unknown')];
        $mutations = ['save', 'saveQuietly', 'update', 'updateQuietly', 'create', 'createQuietly', 'insert', 'upsert', 'updateOrCreate', 'firstOrCreate', 'delete', 'deleteQuietly', 'destroy', 'forceDelete', 'restore', 'increment', 'decrement', 'attach', 'detach', 'sync', 'syncWithoutDetaching', 'toggle', 'truncate', 'put', 'store', 'storeAs', 'move', 'send', 'dispatch'];
        $profile['mutates'] = (new NodeFinder)->findFirst($method->stmts ?? [], fn (Node $node) => ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall)
            && $node->name instanceof Node\Identifier && in_array($node->name->toString(), $mutations, true)) !== null;
        $profile['verdict'] = $profile['guards']['checks'] !== [] ? 'guarded' : ($profile['mutates'] ? 'unguarded' : 'unknown');

        return $profile;
    }

    public function checks(Node\Stmt\ClassMethod $method): array
    {
        $checks = [];
        foreach ([AuthorizationDetector::class, ...config('clearance.detect.detectors', [])] as $detector) {
            $checks = [...$checks, ...app($detector)->detect($method)];
        }
        $checks = array_values(array_unique($checks));
        sort($checks);

        return $checks;
    }

    public function classification(?Node\Stmt\ClassMethod $method): string
    {
        if ($method === null) {
            return 'unknown';
        }
        $statements = $method->stmts ?? [];
        if (count($statements) === 1 && $statements[0] instanceof Node\Stmt\Return_ && $statements[0]->expr instanceof Node\Expr\ConstFetch) {
            $name = strtolower($statements[0]->expr->name->toString());
            if (in_array($name, ['true', 'false'], true)) {
                return $name;
            }
        }

        return $this->checks($method) !== [] ? 'check' : 'custom';
    }
}
