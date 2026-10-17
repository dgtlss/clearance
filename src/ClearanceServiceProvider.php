<?php

namespace Dgtlss\Clearance;

use Composer\Autoload\ClassLoader;
use Dgtlss\Warden\Contracts\CustomAudit;
use Illuminate\Support\ServiceProvider;

final class ClearanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/clearance.php', 'clearance');
        $this->app->singleton(Static\AstIndex::class);
        // Advisor 1.1 publishes a lowercase Composer prefix for its mixed-case namespace.
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $prefixes = $loader->getPrefixesPsr4();
            if (isset($prefixes['dgtlss\\OWASPAdvisor\\']) && ! isset($prefixes['Dgtlss\\OWASPAdvisor\\'])) {
                $loader->addPsr4('Dgtlss\\OWASPAdvisor\\', $prefixes['dgtlss\\OWASPAdvisor\\']);
            }
        }
        if (class_exists('dgtlss\\OWASPAdvisor\\OWASPAdvisor') && interface_exists('dgtlss\\OWASPAdvisor\\Contracts\\SecurityCheckService')) {
            $decorate = fn ($advisor) => config('clearance.report.owasp_advisor', true) && ! $advisor instanceof Integrations\AdvisorBridge
                ? new Integrations\AdvisorBridge($advisor) : $advisor;
            $this->app->extend('Dgtlss\\OWASPAdvisor\\OWASPAdvisor', $decorate);
            $this->app->extend('owaspadvisor', $decorate);
        }
    }

    public function boot(): void
    {
        $this->app->booted(function () {
            if (config('clearance.report.warden', true) && interface_exists(CustomAudit::class)) {
                config(['warden.custom_audits' => array_values(array_unique([
                    ...config('warden.custom_audits', []),
                    Integrations\WardenAudit::class,
                ]))]);
            }
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([dirname(__DIR__).'/config/clearance.php' => config_path('clearance.php')], 'clearance-config');
            $this->commands([
                Console\InstallCommand::class,
                Console\ScanCommand::class,
                Console\VerifyCommand::class,
                Console\LockCommand::class,
                Console\CheckCommand::class,
                Console\ApproveCommand::class,
                Console\ExplainCommand::class,
                Console\PersonasCommand::class,
                Console\MigrateCommand::class,
            ]);
        }
    }
}
