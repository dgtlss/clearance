<?php

namespace Dgtlss\Clearance\Static;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionMethod;

final class AstIndex
{
    private array $files = [];

    /** @return list<Node\Stmt\ClassLike> */
    public function classes(string $path): array
    {
        $source = file_get_contents($path);
        $key = $path.':'.hash('sha256', $source);
        if (! isset($this->files[$key])) {
            $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
            $this->files[$key] = (new NodeTraverser(new NameResolver))->traverse($nodes);
        }

        return (new NodeFinder)->findInstanceOf($this->files[$key], Node\Stmt\ClassLike::class);
    }

    public function fingerprint(Node|array|null $node): string
    {
        $normalize = function ($value) use (&$normalize) {
            if ($value instanceof Node) {
                $result = ['type' => $value->getType()];
                foreach ($value->getSubNodeNames() as $name) {
                    $result[$name] = $normalize($value->$name);
                }

                return $result;
            }

            return is_array($value) ? array_map($normalize, $value) : $value;
        };

        return hash('sha256', json_encode($normalize($node), JSON_THROW_ON_ERROR));
    }

    public function method(string $class, string $method): ?Node\Stmt\ClassMethod
    {
        if (! method_exists($class, $method)) {
            return null;
        }

        $reflection = new ReflectionMethod($class, $method);
        $method = $reflection->getName();
        $path = $reflection->getFileName();

        if ($path === false || ! is_readable($path)) {
            return null;
        }

        $classes = $this->classes($path);
        $classNode = (new NodeFinder)->findFirst($classes, fn (Node $node) => $node instanceof Node\Stmt\ClassLike
            && isset($node->namespacedName)
            && $node->namespacedName->toString() === $reflection->getDeclaringClass()->getName());

        if ($node = $classNode?->getMethod($method)) {
            return $node;
        }
        $alias = $reflection->getDeclaringClass()->getTraitAliases()[$method] ?? null;
        if ($alias !== null) {
            [$trait, $original] = explode('::', $alias, 2);

            return $this->method($trait, $original);
        }
        // Reflection reports the consuming class for imported trait methods,
        // while its source file and line range still identify the trait body.
        $matches = [];
        foreach ($classes as $candidate) {
            foreach ($candidate->getMethods() as $node) {
                if ($node->name->toString() === $method && $node->getStartLine() === $reflection->getStartLine()
                    && $node->getEndLine() === $reflection->getEndLine()) {
                    $matches[] = $node;
                }
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }
}
