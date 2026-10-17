<?php

namespace Dgtlss\Clearance;

use Dgtlss\Warden\Contracts\CustomAudit;
use Illuminate\Support\ServiceProvider;

final class ClearanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__).'/config/clearance.php', 'clearance');
        $this->app->singleton(Static\AstIndex::class);
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
            ]);
        }
    }
}
