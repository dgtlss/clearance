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

    public function method(string $class, string $method): ?Node\Stmt\ClassMethod
    {
        if (! method_exists($class, $method)) {
            return null;
        }

        $reflection = new ReflectionMethod($class, $method);
        $path = $reflection->getFileName();

        if ($path === false || ! is_readable($path)) {
            return null;
        }

        $source = file_get_contents($path);
        $key = $path.':'.hash('sha256', $source);

        if (! isset($this->files[$key])) {
            $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
            $this->files[$key] = (new NodeTraverser(new NameResolver))->traverse($nodes);
        }

        $classNode = (new NodeFinder)->findFirst($this->files[$key], fn (Node $node) => $node instanceof Node\Stmt\ClassLike
            && isset($node->namespacedName)
            && $node->namespacedName->toString() === $reflection->getDeclaringClass()->getName());

        return $classNode?->getMethod($method);
    }
}
