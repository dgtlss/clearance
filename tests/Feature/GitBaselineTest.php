<?php

namespace Dgtlss\Clearance\Tests\Feature;

use Dgtlss\Clearance\Diff\ApprovalStore;
use Dgtlss\Clearance\Dynamic\Verifier;
use Dgtlss\Clearance\Matrix\CanonicalJson;
use Dgtlss\Clearance\Matrix\LockFile;
use Dgtlss\Clearance\Testing\TestHarness;
use Dgtlss\Clearance\Tests\TestCase;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\Process\Process;

class GitBaselineTest extends TestCase
{
    private string $directory;

    private string $originalBase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalBase = base_path();
        $this->directory = sys_get_temp_dir().'/clearance-git-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        mkdir($this->directory.'/hooks');
        app()->setBasePath($this->directory);
        config(['clearance.lock_path' => $this->directory.'/clearance.lock.json', 'clearance.markdown_path' => $this->directory.'/CLEARANCE.md', 'clearance.routes.include' => ['fixture.protected']]);
        $this->git(['init', '--initial-branch=main']);
    }

    protected function tearDown(): void
    {
        app()->setBasePath($this->originalBase);
        app('files')->deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function git(array $arguments): string
    {
        $process = new Process(['git', '-c', 'core.hooksPath='.$this->directory.'/hooks', '-c', 'commit.gpgSign=false', '-c', 'user.name=Clearance fixture', '-c', 'user.email=fixture@example.invalid', ...$arguments], $this->directory);
        $process->setTimeout(10);
        $process->mustRun();

        return $process->getOutput();
    }

    private function commit(string $path = 'clearance.lock.json'): void
    {
        $this->git(['add', '--', $path]);
        $this->git(['commit', '-m', 'Fixture baseline']);
    }

    private function baseline(): array
    {
        $matrix = app(Verifier::class)->run($this);
        app(LockFile::class)->write($matrix);
        $this->commit();
        $this->git(['update-ref', 'refs/remotes/origin/main', 'HEAD']);
        $this->git(['checkout', '-b', 'feature']);

        return $matrix;
    }

    private function harness(array $matrix): void
    {
        $this->app->instance(TestHarness::class, new class($matrix)
        {
            public function __construct(private readonly array $matrix) {}

            public function run(): array
            {
                return $this->matrix;
            }
        });
    }

    public function test_self_approved_widening_in_the_pr_lock_cannot_replace_the_git_baseline(): void
    {
        $before = $this->baseline();
        $this->assertSame('deny', $before['routes']['GET protected/{order}']['access']['customer']['foreign']);
        Gate::before(fn () => true);
        $after = app(Verifier::class)->run($this);
        $this->assertSame('allow', $after['routes']['GET protected/{order}']['access']['customer']['foreign']);
        $this->harness($after);
        foreach (['GET', 'HEAD'] as $verb) {
            $this->artisan('clearance:approve', ['route' => $verb.' protected/{order}', '--persona' => 'customer', '--by' => 'pr-author', '--reason' => 'Self-approved widening'])->assertSuccessful();
        }
        $this->commit();
        $local = file_get_contents(config('clearance.lock_path'));
        $this->assertTrue(app(ApprovalStore::class)->valid(app(LockFile::class)->read()['routes']['GET protected/{order}']));
        $this->artisan('clearance:check')->assertSuccessful();
        $this->artisan('clearance:check', ['--baseline' => 'origin/main', '--json' => true])
            ->expectsOutputToContain('widening')->assertExitCode(1);
        $this->assertSame($local, file_get_contents(config('clearance.lock_path')));
    }

    public function test_an_unchanged_committed_lock_passes_without_rewriting_it(): void
    {
        $this->harness($this->baseline());
        $local = file_get_contents(config('clearance.lock_path'));
        $this->artisan('clearance:check', ['--baseline' => 'origin/main', '--json' => true])
            ->expectsOutputToContain('"lock_changed": false')->assertSuccessful();
        $this->assertSame($local, file_get_contents(config('clearance.lock_path')));
    }

    public function test_new_approval_metadata_is_reported_even_without_access_changes(): void
    {
        $this->harness($this->baseline());
        $this->artisan('clearance:approve', ['route' => 'GET protected/{order}', '--reason' => 'Review unchanged access', '--by' => 'pr-author'])->assertSuccessful();
        $this->artisan('clearance:check', ['--baseline' => 'origin/main', '--json' => true])
            ->expectsOutputToContain('"lock_changed": true')->assertSuccessful();
        config(['clearance.report.github_annotations' => true]);
        $this->artisan('clearance:check', ['--baseline' => 'origin/main'])
            ->expectsOutputToContain('::warning')->assertSuccessful();
    }

    public function test_missing_empty_and_option_like_refs_error_before_verification(): void
    {
        foreach (['missing-ref', '', null, '--help'] as $ref) {
            $this->artisan('clearance:check', ['--baseline' => $ref, '--json' => true])
                ->expectsOutputToContain('Baseline ref')->assertExitCode(3);
        }
    }

    public function test_missing_and_malformed_baseline_locks_error_before_verification(): void
    {
        file_put_contents($this->directory.'/README.md', 'No baseline lock.');
        $this->commit('README.md');
        $this->artisan('clearance:check', ['--baseline' => 'main', '--json' => true])
            ->expectsOutputToContain('Baseline lock file [clearance.lock.json] is missing')->assertExitCode(3);
        file_put_contents(config('clearance.lock_path'), '{bad json');
        $this->commit();
        $this->artisan('clearance:check', ['--baseline' => 'main', '--json' => true])
            ->expectsOutputToContain('invalid JSON')->assertExitCode(3);
    }

    public function test_a_monorepo_custom_lock_path_works_when_the_checkout_lock_is_deleted(): void
    {
        mkdir($this->directory.'/baselines');
        mkdir($this->directory.'/apps/web', 0777, true);
        app()->setBasePath($this->directory.'/apps/web');
        config(['clearance.lock_path' => $this->directory.'/baselines/access lock.json']);
        $matrix = app(Verifier::class)->run($this);
        app(LockFile::class)->write($matrix);
        $this->commit('baselines/access lock.json');
        unlink(config('clearance.lock_path'));
        rmdir($this->directory.'/baselines');
        $this->harness($matrix);
        $this->artisan('clearance:check', ['--baseline' => 'main', '--json' => true])
            ->expectsOutputToContain('"lock_changed": true')->assertSuccessful();
        $this->assertFileDoesNotExist(config('clearance.lock_path'));
    }

    public function test_checkout_symlinks_cannot_redirect_the_baseline_to_another_blob(): void
    {
        $before = $this->baseline();
        Gate::before(fn () => true);
        $after = app(Verifier::class)->run($this);
        app(LockFile::class)->write($after, $this->directory.'/other.lock.json');
        $this->commit('other.lock.json');
        $this->git(['update-ref', 'refs/remotes/origin/main', 'HEAD']);
        unlink(config('clearance.lock_path'));
        symlink('other.lock.json', config('clearance.lock_path'));
        $this->assertSame(CanonicalJson::sort($before), app(LockFile::class)->readFromGit('origin/main'));
        $this->harness($after);
        $this->artisan('clearance:check', ['--baseline' => 'origin/main', '--json' => true])
            ->expectsOutputToContain('widening')->assertExitCode(1);
    }

    public function test_a_lock_outside_the_repository_is_not_a_git_baseline(): void
    {
        $this->baseline();
        config(['clearance.lock_path' => dirname($this->directory).'/outside.lock.json']);
        $this->artisan('clearance:check', ['--baseline' => 'origin/main', '--json' => true])
            ->expectsOutputToContain('inside the application Git repository')->assertExitCode(3);
    }
}
