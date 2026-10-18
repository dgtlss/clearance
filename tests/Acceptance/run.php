<?php

use Composer\InstalledVersions;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$options = getopt('', ['package:', 'benchmark:']);
$benchmark = isset($options['benchmark']) ? max(1, min(1000, (int) $options['benchmark'])) : 0;

$files = new Filesystem;
$directory = sys_get_temp_dir().'/clearance-acceptance-'.bin2hex(random_bytes(8));
$files->copyDirectory(__DIR__.'/application', $directory);
foreach (['bootstrap/cache', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'config'] as $path) {
    $files->ensureDirectoryExists($directory.'/'.$path);
}
touch($directory.'/database/database.sqlite');

$manifest = [
    'name' => 'clearance/acceptance-app', 'type' => 'project', 'license' => 'MIT',
    'require' => ['php' => '^8.3', 'laravel/framework' => InstalledVersions::getVersion('laravel/framework'), 'spatie/laravel-permission' => '^6.0 || ^7.0 || ^8.0'],
    'require-dev' => ['dgtlss/clearance' => 'dev-main', 'phpunit/phpunit' => InstalledVersions::getVersion('phpunit/phpunit'), 'pestphp/pest' => InstalledVersions::getVersion('pestphp/pest'), 'fakerphp/faker' => '^1.24'],
    'repositories' => [['type' => 'path', 'url' => dirname(__DIR__, 2), 'options' => ['symlink' => true, 'versions' => ['dgtlss/clearance' => 'dev-main']]]],
    'autoload' => ['psr-4' => ['App\\' => 'app/', 'Database\\Factories\\' => 'database/factories/']],
    'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']],
    'scripts' => ['post-autoload-dump' => ['@php artisan package:discover --ansi']],
    'config' => ['allow-plugins' => ['pestphp/pest-plugin' => true], 'audit' => ['block-insecure' => true]],
];
if (isset($options['package'])) {
    $archive = realpath($options['package']);
    if ($archive === false || ! is_file($archive)) {
        throw new RuntimeException('Supply an existing package archive.');
    }
    $package = json_decode(file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $package['version'] = '1.0.0';
    $package['dist'] = ['type' => 'zip', 'url' => 'file://'.$archive, 'reference' => hash_file('sha256', $archive)];
    unset($package['require-dev'], $package['autoload-dev'], $package['scripts'], $package['config'], $package['repositories']);
    $manifest['repositories'] = [['type' => 'package', 'package' => $package]];
    $manifest['require-dev']['dgtlss/clearance'] = '1.0.0';
}
$files->put($directory.'/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
$env = [
    'APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('a', 32)), 'APP_URL' => 'http://localhost',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $directory.'/database/database.sqlite',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'log',
    'CLEARANCE_ROUTE' => false, 'CLEARANCE_EXCLUDE' => false, 'CLEARANCE_OMIT_STAFF' => false,
];
$check = function (bool $condition, string $message) use ($directory): void {
    if (! $condition) {
        throw new RuntimeException($message.' Inspect '.$directory);
    }
};
$run = function (array $arguments, int $expected = 0, array $extraEnv = []) use ($directory, $env, $check): string {
    $process = new Process($arguments, $directory, array_replace($env, $extraEnv));
    $process->setTimeout(600);
    $process->run();
    $output = $process->getOutput();
    $check($process->getExitCode() === $expected, implode(' ', $arguments).' returned '.$process->getExitCode()."\n".substr($output.$process->getErrorOutput(), -8000));

    return $output;
};
$artisan = fn (array $arguments, int $expected = 0, array $extraEnv = []) => $run([PHP_BINARY, 'artisan', ...$arguments], $expected, $extraEnv);
$json = fn (string $output): array => json_decode($output, true, 512, JSON_THROW_ON_ERROR);

fwrite(STDOUT, 'Fresh app: Laravel '.InstalledVersions::getPrettyVersion('laravel/framework').' / PHP '.PHP_VERSION."\n");
$run(['composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress', '--quiet']);
$artisan(['clearance:install']);
$files->copy(__DIR__.'/Personas.php.stub', $directory.'/app/Clearance/Personas.php');
$files->put($directory.'/config/clearance.php', <<<'PHP'
<?php
$config = require __DIR__.'/../vendor/dgtlss/clearance/config/clearance.php';
$config['routes']['include'] = ['acceptance.*'];
$config['routes']['exclude'] = getenv('CLEARANCE_EXCLUDE') ? [getenv('CLEARANCE_EXCLUDE')] : [];
$config['dynamic']['timeout_ms'] = 10000;
return $config;
PHP);
$configHash = hash_file('sha256', $directory.'/config/clearance.php');
$personaHash = hash_file('sha256', $directory.'/app/Clearance/Personas.php');
$artisan(['clearance:install']);
$check($configHash === hash_file('sha256', $directory.'/config/clearance.php') && $personaHash === hash_file('sha256', $directory.'/app/Clearance/Personas.php'), 'Installer overwrote host configuration.');
$artisan(['vendor:publish', '--provider=Spatie\Permission\PermissionServiceProvider', '--tag=permission-migrations']);
$artisan(['migrate', '--force']);
if ($benchmark > 0) {
    $files->append($directory.'/routes/web.php', "\nfor (\$i = 0; \$i < ".$benchmark."; \$i++) {\n    Route::get('/benchmark/'.\$i.'/{order}', [OrderController::class, 'show'])->middleware('auth')->name('acceptance.benchmark.'.\$i);\n}\n");
}
$started = hrtime(true);
$matrix = $json($artisan(['clearance:verify', '--json']));
$seconds = (hrtime(true) - $started) / 1e9;
$routes = 7 + 2 * $benchmark;
$scenarios = 7 * $routes;
$check(count($matrix['routes']) === $routes && $matrix['summary']['scenarios'] === $scenarios && $matrix['summary']['responses'] === $scenarios, 'Expected a complete '.$routes.'-route, '.$scenarios.'-scenario baseline.');
$check($matrix['summary']['incomplete'] === [], 'Fresh app verification is incomplete.');
foreach ($matrix['routes'] as $id => $route) {
    $check($route['access']['guest'] === 'deny', 'Guest reached '.$id);
    $check($route['access']['customer'] === ['foreign' => 'deny', 'owned' => str_contains($id, 'staff/') ? 'deny' : 'allow'], 'Customer ownership boundary failed: '.$id);
    foreach (['staff', 'admin'] as $role) {
        $check($route['access'][$role] === ['foreign' => 'allow', 'owned' => 'allow'], $role.' could not access '.$id);
    }
}

if ($benchmark > 0) {
    $check($seconds < 300, 'Verification exceeded the five-minute target.');
    $db = new PDO('sqlite:'.$directory.'/database/database.sqlite');
    $check((int) $db->query('select count(*) from orders')->fetchColumn() === 0, 'Benchmark left fixture records.');
    $db = null;
    fwrite(STDOUT, json_encode(['routes' => $routes, 'scenarios' => $scenarios, 'seconds' => round($seconds, 3), 'php' => PHP_VERSION, 'laravel' => InstalledVersions::getPrettyVersion('laravel/framework'), 'database' => 'sqlite', 'fixture' => 'synthetic Spatie ownership routes; warm dependencies; includes host bootstrap'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    $files->deleteDirectory($directory);

    exit(0);
}

// Exercise both supported harness entry points using the same host app and middleware.
rename($directory.'/vendor/bin/pest', $directory.'/vendor/bin/pest.disabled');
try {
    $phpunit = $json($artisan(['clearance:verify', '--json']));
    $check($matrix === $phpunit, 'Pest and PHPUnit produced different matrices.');
} finally {
    rename($directory.'/vendor/bin/pest.disabled', $directory.'/vendor/bin/pest');
}
$artisan(['clearance:lock']);
$lock = $files->get($directory.'/clearance.lock.json');
$markdown = $files->get($directory.'/CLEARANCE.md');
$artisan(['clearance:lock']);
$check($lock === $files->get($directory.'/clearance.lock.json') && $markdown === $files->get($directory.'/CLEARANCE.md'), 'Baseline files changed on an identical rerun.');
$artisan(['clearance:check']);
$run(['git', 'init', '--initial-branch=main']);
$run(['git', 'config', 'user.name', 'Clearance fixture']);
$run(['git', 'config', 'user.email', 'fixture@example.invalid']);
$run(['git', 'config', 'commit.gpgSign', 'false']);
$files->ensureDirectoryExists($directory.'/.git/clearance-hooks');
$run(['git', 'config', 'core.hooksPath', $directory.'/.git/clearance-hooks']);
$run(['git', 'add', '--', 'clearance.lock.json', 'CLEARANCE.md']);
$run(['git', 'commit', '-m', 'Reviewed access baseline']);
$run(['git', 'update-ref', 'refs/remotes/origin/main', 'HEAD']);
$run(['git', 'checkout', '-b', 'feature-self-approval']);
$trusted = $json($artisan(['clearance:check', '--baseline=origin/main', '--json']));
$check($trusted['baseline_ref'] === 'origin/main' && $trusted['lock_changed'] === false && $trusted['changes'] === [], 'Unchanged trusted baseline did not pass.');
$missing = $json($artisan(['clearance:check', '--baseline=missing-ref', '--json'], 3));
$check(str_contains($missing['error'], 'Baseline ref'), 'Missing Git baseline did not fail clearly.');
$missing = $json($artisan(['clearance:check', '--baseline', '--json'], 3));
$check(str_contains($missing['error'], 'Baseline ref'), 'A baseline option without a ref fell back to the checkout lock.');
$deleted = $json($artisan(['clearance:check', '--json'], 0, ['CLEARANCE_OMIT_STAFF' => '1']));
$check(array_unique(array_column($deleted['changes'], 'type')) === ['narrowing'], 'Physical route deletion was not recognized as narrowing.');

$controller = $directory.'/app/Http/Controllers/OrderController.php';
$source = $files->get($controller);
$needle = "        Gate::authorize('view-order', \$order);";
$position = strpos($source, $needle);
$check($position !== false, 'Regression fixture authorization call was not found.');
$files->put($controller, substr_replace($source, '', $position, strlen($needle)));
$regression = $json($artisan(['clearance:check', '--json'], 1));
$check(in_array('widening', array_column($regression['changes'], 'type'), true) && in_array('guard_removed', array_column($regression['changes'], 'type'), true), 'Removed authorization was not detected.');
$artisan(['clearance:lock'], 1);
$check($lock === $files->get($directory.'/clearance.lock.json'), 'Lock silently accepted the regression.');
$artisan(['clearance:approve', 'GET orders/{order}', '--reason=Acceptance test deliberate widening', '--by=fixture-reviewer']);
$artisan(['clearance:check'], 1);
$artisan(['clearance:approve', 'HEAD orders/{order}', '--reason=Acceptance test deliberate widening', '--by=fixture-reviewer']);
$artisan(['clearance:check']);
$run(['git', 'add', '--', 'clearance.lock.json', 'CLEARANCE.md']);
$run(['git', 'commit', '-m', 'PR author approves their own widened access']);
$selfApproved = $json($artisan(['clearance:check', '--baseline=origin/main', '--json'], 1));
$check($selfApproved['lock_changed'] === true && in_array('widening', array_column($selfApproved['changes'], 'type'), true)
    && in_array('guard_removed', array_column($selfApproved['changes'], 'type'), true), 'PR approval replaced the trusted access baseline.');

$excluded = ['CLEARANCE_EXCLUDE' => 'acceptance.staff'];
$scopeDiff = $json($artisan(['clearance:check', '--json'], 1, $excluded));
$check(in_array('coverage_lost', array_column($scopeDiff['changes'], 'type'), true), 'Registered routes could be hidden by an exclusion.');
$artisan(['clearance:lock'], 1, $excluded);
$artisan(['clearance:approve', '--coverage', '--reason=Acceptance test dedicated host coverage', '--by=fixture-reviewer'], 0, $excluded);
$artisan(['clearance:check'], 0, $excluded);
$artisan(['clearance:lock'], 0, $excluded);
$check(isset($json($files->get($directory.'/clearance.lock.json'))['coverage_approval']), 'Scope review was lost on lock.');

$db = new PDO('sqlite:'.$directory.'/database/database.sqlite');
foreach (['users', 'orders', 'order_lines', 'roles', 'permissions'] as $table) {
    $check((int) $db->query('select count(*) from '.$table)->fetchColumn() === 0, 'Verification left records in '.$table);
}
$check(! is_file($directory.'/storage/app/private/outbound.txt'), 'Route wrote to the real storage disk.');
$db = null;
$files->deleteDirectory($directory);
fwrite(STDOUT, "Passed: installation, Spatie roles, 49 verified scenarios, nested ownership, rollback, both runners, stable lock, trusted Git baseline, regression, approvals and coverage protection.\n");
