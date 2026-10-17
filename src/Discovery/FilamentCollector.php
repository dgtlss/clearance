<?php

namespace Dgtlss\Clearance\Discovery;

use Composer\InstalledVersions;
use Dgtlss\Clearance\Static\AstIndex;
use Dgtlss\Clearance\Static\PolicyAnalyzer;
use Dgtlss\Clearance\Static\SourceLocation;
use Dgtlss\Clearance\Static\SurfaceAnalyzer;
use Filament\Facades\Filament;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

final class FilamentCollector
{
    private const ABILITIES = ['viewAny', 'create', 'view', 'update', 'delete', 'deleteAny', 'forceDelete', 'forceDeleteAny', 'restore', 'restoreAny', 'replicate', 'reorder'];

    public function __construct(private readonly AstIndex $ast, private readonly SurfaceAnalyzer $analyzer, private readonly PolicyAnalyzer $policies, private readonly SourceLocation $sources) {}

    public function collect(): array
    {
        $result = ['status' => 'complete', 'diagnostics' => [], 'surfaces' => []];
        if (! config('clearance.detect.filament', true) || ! class_exists('Filament\\Facades\\Filament')) {
            $result['status'] = config('clearance.detect.filament', true) ? 'unavailable' : 'disabled';

            return $result;
        }
        try {
            foreach (Filament::getPanels() as $panel) {
                $resources = $panel->getResources();
                $pages = $panel->getPages();
                if (method_exists($panel, 'getResourceConfigurations')) {
                    foreach ($panel->getResourceConfigurations() as $configuration) {
                        $resources[] = $configuration->resource;
                    }
                }
                if (method_exists($panel, 'getPageConfigurations')) {
                    foreach ($panel->getPageConfigurations() as $configuration) {
                        $pages[] = $configuration->page;
                    }
                }
                foreach (array_unique($resources) as $class) {
                    $profile = $this->resource($class);
                    $profile = $this->panel($profile, $panel);
                    $result['surfaces']['filament '.$panel->getId().':resource:'.$class] = $profile;
                    foreach ($class::getPages() as $page) {
                        $pageClass = $page->getPage();
                        $pageProfile = $profile;
                        $pageProfile['class'] = $pageClass;
                        $pageProfile['resource'] = $class;
                        $pageProfile['type'] = 'resource-page';
                        $pageReflection = new ReflectionClass($pageClass);
                        $pageProfile['source'] = $this->sources->forFile($pageReflection->getFileName(), $pageReflection->getStartLine());
                        $this->overrides($pageProfile, $pageClass, 'Filament\\Resources\\Pages\\Page');
                        $result['surfaces']['filament '.$panel->getId().':resource-page:'.$class.'@'.$pageClass] = $pageProfile;
                    }
                }
                foreach (array_unique($pages) as $class) {
                    $reflection = new ReflectionClass($class);
                    $profile = $this->analyzer->profile('filament', $class, $this->sources->forFile($reflection->getFileName(), $reflection->getStartLine()));
                    $profile['type'] = 'page';
                    $node = $this->ast->method($class, 'canAccess');
                    $profile['authorization'] = $this->analyzer->classification($node);
                    $profile['verdict'] = match ($profile['authorization']) {
                        'check', 'false' => 'guarded',
                        'true' => 'unguarded',
                        default => 'unknown',
                    };
                    $this->overrides($profile, $class, 'Filament\\Pages\\Page');
                    $profile['guards']['evidence'][] = 'canAccess:'.$this->ast->fingerprint($node);
                    $profile = $this->panel($profile, $panel);
                    $result['surfaces']['filament '.$panel->getId().':page:'.$class] = $profile;
                }
            }
        } catch (Throwable $e) {
            $result['status'] = 'error';
            $result['diagnostics'][] = 'Filament discovery failed: '.$e::class.'; '.$e->getMessage();
        }
        ksort($result['surfaces']);

        return $result;
    }

    private function resource(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $profile = $this->analyzer->profile('filament', $class, $this->sources->forFile($reflection->getFileName(), $reflection->getStartLine()));
        $profile['type'] = 'resource';
        $profile['model'] = $class::getModel();
        $profile['guards']['evidence'][] = 'model:'.$profile['model'];
        // These are configuration getters; authorization methods are inspected, never executed.
        $profile['skip_authorization'] = $class::shouldSkipAuthorization();
        $profile['check_policy_existence'] = $class::shouldCheckPolicyExistence();
        $profile['guards']['evidence'][] = 'authorization-config:'.json_encode([$profile['skip_authorization'], $profile['check_policy_existence']], JSON_THROW_ON_ERROR);
        foreach (self::ABILITIES as $ability) {
            $policy = $this->policies->analyze($profile['model'], $ability);
            if ($policy !== null) {
                $profile['guards']['policies'][] = $policy;
                $profile['guards']['evidence'][] = 'policy:'.$policy['class'].'@'.$ability.':'.$this->ast->fingerprint($this->ast->method($policy['class'], $ability));
                if (method_exists($policy['class'], 'before')) {
                    $profile['guards']['evidence'][] = 'policy-before:'.$policy['class'].':'.$this->ast->fingerprint($this->ast->method($policy['class'], 'before'));
                }
            }
        }
        $profile['verdict'] = $profile['skip_authorization'] || $profile['guards']['policies'] === [] ? 'unguarded' : 'guarded';
        $this->overrides($profile, $class, 'Filament\\Resources\\Resource');

        return $profile;
    }

    private function overrides(array &$profile, string $class, string $base): void
    {
        $profile['overrides'] ??= [];
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
            $name = $method->getName();
            if ((! str_starts_with($name, 'can') && ! str_starts_with($name, 'authorize') && ! str_starts_with($name, 'get') && ! str_starts_with($name, 'should') && $name !== 'mount')
                || ! is_subclass_of($method->getDeclaringClass()->getName(), $base)) {
                continue;
            }
            // Only authorization overrides affect this profile; unrelated UI getters do not.
            if (str_starts_with($name, 'get') && ! str_contains($name, 'AuthorizationResponse')) {
                continue;
            }
            if (str_starts_with($name, 'should') && ! in_array($name, ['shouldSkipAuthorization', 'shouldCheckPolicyExistence'], true)) {
                continue;
            }
            $node = $this->ast->method($class, $name);
            $profile['overrides'][$name] = $this->analyzer->classification($node);
            $profile['guards']['checks'] = array_values(array_unique([...$profile['guards']['checks'], ...($node ? $this->analyzer->checks($node) : [])]));
            $profile['guards']['evidence'][] = 'override:'.$name.':'.$this->ast->fingerprint($node);
            if ($profile['overrides'][$name] === 'true' && str_starts_with($name, 'can')) {
                $profile['verdict'] = 'unguarded';
            }
        }
        ksort($profile['overrides']);
        sort($profile['guards']['checks']);
        sort($profile['guards']['evidence']);
    }

    private function panel(array $profile, object $panel): array
    {
        $profile['panel'] = $panel->getId();
        $profile['guard'] = $panel->getAuthGuard();
        $profile['guards']['middleware'] = array_values(array_unique([...$panel->getMiddleware(), ...$panel->getAuthMiddleware()]));
        sort($profile['guards']['middleware']);
        $profile['guards']['evidence'][] = 'auth-guard:'.$profile['guard'];
        $profile['configurations'] = [];
        $getter = ($profile['type'] === 'page') ? 'getPageConfigurations' : 'getResourceConfigurations';
        $property = ($profile['type'] === 'page') ? 'page' : 'resource';
        $class = $profile['resource'] ?? $profile['class'];
        if (method_exists($panel, $getter)) {
            foreach ($panel->$getter() as $configuration) {
                if ($class === $configuration->$property) {
                    $profile['configurations'][] = ['key' => $configuration->getKey(), 'slug' => $configuration->getSlug()];
                }
            }
        }
        usort($profile['configurations'], fn ($a, $b) => $a['key'] <=> $b['key']);
        $profile['guards']['evidence'][] = 'configurations:'.json_encode($profile['configurations'], JSON_THROW_ON_ERROR);
        $profile['guards']['evidence'][] = 'framework:filament/filament@'.(InstalledVersions::isInstalled('filament/filament') ? InstalledVersions::getPrettyVersion('filament/filament') : 'unknown');
        $profile['guards']['evidence'] = array_values(array_unique($profile['guards']['evidence']));
        sort($profile['guards']['evidence']);

        return $profile;
    }
}
