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
            $templates = [];
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
                        } elseif ($node instanceof Node\Stmt\Class_ && $node->extends !== null) {
                            $base = $node->extends->toString();
                            if (! class_exists($base)) {
                                throw new \RuntimeException('Anonymous component base class could not be resolved: '.$base);
                            }
                            if (! is_a($base, 'Livewire\\Component', true)) {
                                continue;
                            }
                            $name = app(SourceLocation::class)->forFile($file, $node->getStartLine())['file'];
                            $origin = (realpath($file) ?: $file).':'.$node->getStartFilePos();
                            if (isset($templates[$name]) && $templates[$name] !== $origin) {
                                throw new \RuntimeException('Ambiguous anonymous Livewire identity: '.$name);
                            }
                            $templates[$name] = $origin;
                            [$methods, $locked, $traits] = $this->anonymous($node, $file);
                            foreach ($methods as $methodName => [$method, $source, $public, $static]) {
                                if ($public && ! $static && $this->callable($methodName, $traits)) {
                                    $id = 'livewire '.$name.'@'.$methodName;
                                    $result['surfaces'][$id] = $this->analyzer->action($name, $method, $source, $locked);
                                    $result['surfaces'][$id]['method'] = $methodName;
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
                $locked = $this->lockedProperties($reflection);
                $traits = array_values(class_uses_recursive($class));
                foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    if ($method->isStatic() || ! $this->callable($method->getName(), $traits)
                        || $method->getDeclaringClass()->getName() === 'Livewire\\Component') {
                        continue;
                    }
                    $node = $this->ast->method($class, $method->getName());
                    if ($node === null) {
                        throw new \RuntimeException('Livewire action source could not be resolved: '.$class.'@'.$method->getName());
                    }
                    $id = 'livewire '.$class.'@'.$method->getName();
                    $result['surfaces'][$id] = $this->analyzer->action($class, $node, $method->getFileName(), $locked);
                    $result['surfaces'][$id]['method'] = $method->getName();
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

        if (! $reflection->hasProperty($property)) {
            throw new \RuntimeException('Unsupported Livewire discovery API: '.$registry::class.'::$'.$property);
        }

        return $reflection->getProperty($property)->getValue($registry);
    }

    private function callable(string $name, array $traits = []): bool
    {
        $blocked = ['render', 'mount', 'exception', 'hydrate*', 'dehydrate*', 'updating*', 'updated*', '__*'];
        if (class_exists('Livewire\\Finder\\Finder')) {
            $blocked = [...$blocked, 'boot', 'booted', 'rendering', 'rendered', 'scriptSrc'];
            foreach ($traits as $trait) {
                foreach (['mount', 'boot', 'booted'] as $hook) {
                    $blocked[] = $hook.class_basename($trait);
                }
            }
        }

        return ! Str::is($blocked, $name);
    }

    private function anonymous(Node\Stmt\Class_ $class, string $file): array
    {
        $methods = [];
        $declared = array_fill_keys(array_map(fn ($method) => $method->name->toString(), $class->getMethods()), true);
        $base = new ReflectionClass($class->extends->toString());
        $traits = array_values(class_uses_recursive($base->getName()));
        $directLocked = array_fill_keys($this->locked($class), true);
        $baseLocked = array_fill_keys($this->lockedProperties($base), true);
        $locked = $baseLocked;
        $declaredProperties = [];
        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $prop) {
                $declaredProperties[$prop->name->toString()] = true;
                unset($locked[$prop->name->toString()]);
            }
        }
        foreach ($base->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() === 'Livewire\\Component' || isset($declared[$method->getName()])
                || ! $this->callable($method->getName(), $traits)) {
                continue;
            }
            $methods[$method->getName()] = $this->reflectedMethod($base->getName(), $method);
        }
        $imported = [];
        $importedProperties = [];
        foreach ($class->stmts as $statement) {
            if (! $statement instanceof Node\Stmt\TraitUse) {
                continue;
            }
            if ($statement->adaptations !== []) {
                throw new \RuntimeException('Anonymous Livewire trait adaptations require explicit host coverage: '.$file);
            }
            foreach ($statement->traits as $trait) {
                $name = $trait->toString();
                if (! trait_exists($name)) {
                    throw new \RuntimeException('Anonymous Livewire trait could not be resolved: '.$name);
                }
                $traits = [...$traits, $name, ...array_values(class_uses_recursive($name))];
                $reflection = new ReflectionClass($name);
                $traitLocked = array_fill_keys($this->lockedProperties($reflection), true);
                foreach ($reflection->getProperties() as $property) {
                    $propertyName = $property->getName();
                    $isLocked = isset($traitLocked[$propertyName]);
                    if (isset($declaredProperties[$propertyName])) {
                        continue;
                    }
                    // PHP versions differ on attribute precedence when a trait redeclares a base property.
                    if ($base->hasProperty($propertyName) && ! $base->getProperty($propertyName)->isPrivate()
                        && isset($baseLocked[$propertyName]) !== $isLocked) {
                        throw new \RuntimeException('Conflicting anonymous Livewire property attributes: '.$propertyName);
                    }
                    if (array_key_exists($propertyName, $importedProperties) && $importedProperties[$propertyName] !== $isLocked) {
                        throw new \RuntimeException('Conflicting anonymous Livewire trait property: '.$propertyName);
                    }
                    $importedProperties[$propertyName] = $isLocked;
                    $locked[$propertyName] = $isLocked;
                }
                foreach ($reflection->getMethods() as $method) {
                    $methodName = $method->getName();
                    if (isset($declared[$methodName])) {
                        continue;
                    }
                    if (isset($imported[$methodName])) {
                        throw new \RuntimeException('Conflicting anonymous Livewire trait method: '.$methodName);
                    }
                    $imported[$methodName] = true;
                    $methods[$methodName] = $this->reflectedMethod($name, $method);
                }
            }
        }
        foreach ($class->getMethods() as $method) {
            $methods[$method->name->toString()] = [$method, $file, $method->isPublic(), $method->isStatic()];
        }
        $locked = [...array_keys($directLocked), ...array_keys(array_filter($locked))];
        sort($locked);

        return [$methods, $locked, array_unique($traits)];
    }

    private function reflectedMethod(string $class, ReflectionMethod $method): array
    {
        $node = $this->ast->method($class, $method->getName());
        if ($node === null) {
            throw new \RuntimeException('Livewire action source could not be resolved: '.$class.'@'.$method->getName());
        }

        return [$node, $method->getFileName(), $method->isPublic(), $method->isStatic()];
    }

    private function lockedProperties(ReflectionClass $class): array
    {
        $locked = [];
        foreach ($class->getProperties() as $property) {
            if ($property->isPublic() && $property->getAttributes('Livewire\\Features\\SupportLockedProperties\\BaseLocked', \ReflectionAttribute::IS_INSTANCEOF) !== []) {
                $locked[] = $property->getName();
            }
        }
        sort($locked);

        return $locked;
    }

    private function locked(Node\Stmt\ClassLike $class): array
    {
        $locked = [];
        foreach ($class->getProperties() as $property) {
            if (! $property->isPublic()) {
                continue;
            }
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
