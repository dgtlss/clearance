<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$directory = sys_get_temp_dir().'/clearance-package-'.bin2hex(random_bytes(8));
$files = new Filesystem;
$files->makeDirectory($directory, 0700);
$process = new Process(['composer', 'archive', '--format=zip', '--file=clearance', '--dir='.$directory], dirname(__DIR__, 2));
$process->setTimeout(120);
$process->mustRun();
$path = $directory.'/clearance.zip';
$archive = new PharData($path);
$required = ['composer.json', 'LICENSE', 'README.md', 'SECURITY.md', 'src/ClearanceServiceProvider.php', 'config/clearance.php', 'stubs/Personas.php.stub'];
foreach ($required as $file) {
    if (! isset($archive[$file])) {
        throw new RuntimeException('Package artifact is missing '.$file);
    }
}
$count = 0;
foreach (new RecursiveIteratorIterator($archive) as $entry) {
    $name = substr($entry->getPathname(), strlen('phar://'.$path.'/'));
    if (preg_match('~^(vendor/|tests/|\.git/|\.phpunit|\.env|auth\.json|node_modules/)|(^|/)\.DS_Store$~', $name)) {
        throw new RuntimeException('Package artifact includes development or private data: '.$name);
    }
    $count++;
}
if ($count > 200 || filesize($path) > 1024 * 1024) {
    throw new RuntimeException('Package artifact unexpectedly exceeds its file or size budget.');
}
fwrite(STDOUT, 'Artifact checked: '.$count.' files, '.filesize($path)." bytes.\n");
$test = new Process([PHP_BINARY, __DIR__.'/run.php', '--package='.$path], dirname(__DIR__, 2));
$test->setTimeout(600);
$test->run(fn ($type, $output) => fwrite($type === Process::ERR ? STDERR : STDOUT, $output));
if (! $test->isSuccessful()) {
    throw new RuntimeException('Install-from-artifact acceptance failed; inspect '.$directory);
}
$files->deleteDirectory($directory);
