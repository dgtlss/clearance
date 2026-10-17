<?php

namespace Dgtlss\Clearance\Discovery;

use Composer\Autoload\ClassLoader;
use Dgtlss\Clearance\Static\AstIndex;
use Dgtlss\Clearance\Static\SourceLocation;
use Dgtlss\Clearance\Static\SurfaceAnalyzer;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use PhpParser\Node;
use ReflectionClass;
use ReflectionMethod;
use ReflectionObject;
use Throwable;

final class LivewireCollector
{
    public function __construct(private readonly AstIndex $ast, private readonly SurfaceAnalyzer $analyzer, private readonly Filesystem $files) {}

    public function collect(): array
    {
        $result = ['status' => 'complete', 'diagnostics' => [], 'surfaces' => []];
        if (! config('clearance.detect.livewire', true) || ! class_exists('Livewire\\Component')) {
            $result['status'] = config('clearance.detect.livewire', true) ? 'unavailable' : 'disabled';

            return $result;
        }
        try {
            $classes = config('clearance.detect.livewire_components', []);
            $paths = config('clearance.detect.livewire_paths', []);
            $namespaces = [config('livewire.class_namespace', 'App\\Livewire')];
            $paths[] = config('livewire.class_path', app_path('Livewire'));
            $views = [...config('livewire.component_locations', []), ...array_values(config('livewire.component_namespaces', []))];

            if (class_exists('Livewire\\Finder\\Finder')) {
                $registry = app('Livewire\\Finder\\Finder');
                $classes = [...$classes, ...array_values($this->state($registry, 'classComponents'))];
                $namespaces = [...$namespaces, ...$this->state($registry, 'classLocations')];
                foreach ($this->state($registry, 'classNamespaces') as $location) {
                    $namespaces[] = $location['classNamespace'];
                    if (! empty($location['classPath'])) {
                        $paths[] = $location['classPath'];
                    }
                }
                $views = [...$views, ...$this->state($registry, 'viewLocations'), ...array_values($this->state($registry, 'viewNamespaces')), ...array_values($this->state($registry, 'viewComponents'))];
            } elseif (class_exists('Livewire\\Mechanisms\\ComponentRegistry')) {
                $registry = app('Livewire\\Mechanisms\\ComponentRegistry');
                $classes = [...$classes, ...array_values($this->state($registry, 'aliases')), ...$this->state($registry, 'nonAliasedClasses')];
            }
            foreach (ClassLoader::getRegisteredLoaders() as $loader) {
                foreach ($loader->getPrefixesPsr4() as $prefix => $directories) {
                    foreach ($namespaces as $namespace) {
                        if (is_string($namespace) && str_starts_with($namespace.'\\', $prefix)) {
                            foreach ($directories as $directory) {
                                $paths[] = $directory.'/'.str_replace('\\', '/', substr($namespace, strlen($prefix)));
                            }
                        }
                    }
                }
            }
            foreach (array_unique([...$paths, ...$views]) as $path) {
                $files = is_dir($path) ? $this->files->allFiles($path) : (is_file($path) ? [$path] : []);
                foreach ($files as $file) {
                    $file = (string) $file;
                    if (! str_ends_with($file, '.php')) {
                        continue;
                    }
                    foreach ($this->ast->classes($file) as $node) {
                        if (isset($node->namespacedName)) {
                            $name = $node->namespacedName->toString();
                            if (is_subclass_of($name, 'Livewire\\Component')) {
                                $classes[] = $name;
                            }
                        } elseif ($node instanceof Node\Stmt\Class_ && $node->extends?->toString() === 'Livewire\\Component') {
                            $name = app(SourceLocation::class)->forFile($file, $node->getStartLine())['file'];
                            $locked = $this->locked($node);
                            foreach ($node->getMethods() as $method) {
                                if ($method->isPublic() && ! $method->isStatic() && $this->callable($method->name->toString())) {
                                    $id = 'livewire '.$name.'@'.$method->name;
                                    $result['surfaces'][$id] = $this->analyzer->action($name, $method, $file, $locked);
                                }
                            }
                        }
                    }
                }
            }
            foreach (array_unique(array_map(fn ($class) => is_object($class) ? $class::class : $class, $classes)) as $class) {
                if (! is_subclass_of($class, 'Livewire\\Component')) {
                    throw new \RuntimeException('Configured Livewire component could not be resolved: '.$class);
                }
                $reflection = new ReflectionClass($class);
                if ($reflection->isAbstract()) {
                    continue;
                }
                $locked = [];
                foreach ($reflection->getProperties() as $property) {
                    if ($property->isPublic() && $property->getAttributes('Livewire\\Attributes\\Locked') !== []) {
                        $locked[] = $property->getName();
                    }
                }
                sort($locked);
                foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    if ($method->isStatic() || ! $this->callable($method->getName())
                        || $method->getDeclaringClass()->getName() === 'Livewire\\Component') {
                        continue;
                    }
                    $node = $this->ast->method($class, $method->getName());
                    if ($node === null) {
                        throw new \RuntimeException('Livewire action source could not be resolved: '.$class.'@'.$method->getName());
                    }
                    $id = 'livewire '.$class.'@'.$method->getName();
                    $result['surfaces'][$id] = $this->analyzer->action($class, $node, $method->getFileName(), $locked);
                }
            }
        } catch (Throwable $e) {
            $result['status'] = 'error';
            $result['diagnostics'][] = 'Livewire discovery failed: '.$e::class.'; '.$e->getMessage();
        }
        ksort($result['surfaces']);

        return $result;
    }

    private function state(object $registry, string $property): array
    {
        $reflection = new ReflectionObject($registry);

        return $reflection->hasProperty($property) ? $reflection->getProperty($property)->getValue($registry) : [];
    }

    private function callable(string $name): bool
    {
        $blocked = ['render', 'mount', 'exception', 'hydrate*', 'dehydrate*', 'updating*', 'updated*', '__*'];
        if (class_exists('Livewire\\Finder\\Finder')) {
            $blocked = [...$blocked, 'boot', 'booted', 'rendering', 'rendered', 'scriptSrc'];
        }

        return ! Str::is($blocked, $name);
    }

    private function locked(Node\Stmt\ClassLike $class): array
    {
        $locked = [];
        foreach ($class->getProperties() as $property) {
            foreach ($property->attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    if ($attribute->name->toString() === 'Livewire\\Attributes\\Locked') {
                        foreach ($property->props as $prop) {
                            $locked[] = $prop->name->toString();
                        }
                    }
                }
            }
        }
        sort($locked);

        return $locked;
    }
}
