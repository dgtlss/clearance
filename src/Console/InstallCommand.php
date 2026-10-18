<?php

namespace Dgtlss\Clearance\Console;

use Illuminate\Filesystem\Filesystem;

final class InstallCommand extends ClearanceCommand
{
    protected $signature = 'clearance:install';

    protected $description = 'Publish Clearance configuration, persona definitions and a CI snippet';

    protected function executeClearance(): int
    {
        $files = app(Filesystem::class);
        $root = dirname(__DIR__, 2);

        foreach ([
            $root.'/config/clearance.php' => config_path('clearance.php'),
            $root.'/stubs/Personas.php.stub' => app_path('Clearance/Personas.php'),
        ] as $from => $to) {
            if (! $files->exists($to)) {
                $files->ensureDirectoryExists(dirname($to));
                $files->copy($from, $to);
            }
        }

        $this->info('Clearance installed. Edit app/Clearance/Personas.php, then run clearance:verify.');
        $this->line("Fetch your trusted base branch (checkout fetch-depth: 0), then check after preparing the CI test database:\n  - run: php artisan clearance:check --baseline=origin/main");

        return 0;
    }
}
