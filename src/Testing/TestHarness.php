<?php

namespace Dgtlss\Clearance\Testing;

use Dgtlss\Clearance\Matrix\LockException;
use Dgtlss\Clearance\Matrix\LockFile;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Symfony\Component\Process\Process;

final class TestHarness
{
    public function __construct(private readonly Filesystem $files) {}

    public function run(?string $filter = null): array
    {
        if (! config('clearance.dynamic.enabled', true)) {
            throw new RuntimeException('Dynamic verification is disabled. Use clearance:scan for a static report.', 2);
        }

        $runner = is_file(base_path('vendor/bin/pest')) ? base_path('vendor/bin/pest') : base_path('vendor/bin/phpunit');

        if (! is_file($runner)) {
            throw new RuntimeException('Install Pest or PHPUnit in this application before running verification.', 2);
        }

        $base = config('clearance.dynamic.test_case', 'Tests\\TestCase');
        $trait = config('clearance.dynamic.database_trait', DatabaseTransactions::class);

        foreach ([$base, $trait] as $class) {
            if (! is_string($class) || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\\\\[a-zA-Z_][a-zA-Z0-9_]*)*$/D', $class)) {
                throw new RuntimeException('Configure fully qualified test_case and database_trait class names.', 2);
            }
        }

        $directory = sys_get_temp_dir().'/clearance-'.bin2hex(random_bytes(10));
        $this->files->makeDirectory($directory, 0700);
        $path = $directory.'/ClearanceGeneratedTest.php';
        $result = $directory.'/matrix.json';
        $source = <<<'PHP'
<?php

#[\PHPUnit\Framework\Attributes\Group('clearance')]
final class ClearanceGeneratedTest extends \__BASE__
{
    use \__TRAIT__;

    public function test_clearance_access_matrix(): void
    {
        $matrix = app(\Dgtlss\Clearance\Dynamic\Verifier::class)->run($this);
        $json = \Dgtlss\Clearance\Matrix\CanonicalJson::encode($matrix);
        $this->assertNotFalse(file_put_contents(getenv('CLEARANCE_RESULT_PATH'), $json));
    }
}
PHP;

        try {
            $this->files->put($path, str_replace(['__BASE__', '__TRAIT__'], [$base, $trait], $source));
            $process = new Process([PHP_BINARY, $runner, '--group=clearance', '--colors=never', $path], base_path(), [
                'APP_ENV' => 'testing',
                'CLEARANCE_RESULT_PATH' => $result,
                'CLEARANCE_ROUTE' => $filter ?? false,
            ]);
            $process->setTimeout(config('clearance.dynamic.process_timeout', 300));
            $process->run();

            if (! $process->isSuccessful() || ! is_file($result)) {
                throw new RuntimeException("Clearance test harness failed.\n".$process->getOutput().$process->getErrorOutput(), 2);
            }

            return app(LockFile::class)->read($result);
        } catch (LockException $e) {
            throw new RuntimeException('Test harness returned an invalid matrix.', 2, $e);
        } finally {
            $this->files->deleteDirectory($directory);
        }
    }
}
