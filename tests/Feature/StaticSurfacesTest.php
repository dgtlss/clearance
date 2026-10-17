<?php

namespace Dgtlss\Clearance\Tests\Feature;

use Dgtlss\Clearance\Clearance;
use Dgtlss\Clearance\Diff\ApprovalStore;
use Dgtlss\Clearance\Diff\MatrixDiff;
use Dgtlss\Clearance\Discovery\FilamentCollector;
use Dgtlss\Clearance\Discovery\LivewireCollector;
use Dgtlss\Clearance\Matrix\CanonicalJson;
use Dgtlss\Clearance\Matrix\LockFile;
use Dgtlss\Clearance\Matrix\MarkdownRenderer;
use Dgtlss\Clearance\Tests\Fixtures\Components\OrderEditor;
use Dgtlss\Clearance\Tests\Fixtures\Resources\OpenOrderResource;
use Dgtlss\Clearance\Tests\Fixtures\Resources\OrderPage;
use Dgtlss\Clearance\Tests\Fixtures\Resources\OrderResource;
use Dgtlss\Clearance\Tests\Fixtures\Resources\ReviewPage;
use Dgtlss\Clearance\Tests\TestCase;
use Filament\Panel;
use Filament\PanelRegistry;
use Filament\Resources\ResourceConfiguration;

class StaticSurfacesTest extends TestCase
{
    public function test_livewire_real_registry_and_blade_sources_are_static_only(): void
    {
        if (! class_exists('Livewire\\Component')) {
            $this->markTestSkipped('Optional Livewire integration job installs its real package.');
        }
        app('livewire')->component('fixture.order-editor', OrderEditor::class);
        config(['clearance.detect.livewire_paths' => [dirname(__DIR__).'/Fixtures/Components']]);
        $result = app(LivewireCollector::class)->collect();
        $this->assertSame('complete', $result['status'], implode(' ', $result['diagnostics']));
        $save = $result['surfaces']['livewire '.OrderEditor::class.'@saveOrder'];
        $this->assertSame('static', $save['evidence']);
        $this->assertSame([], $save['access']);
        $this->assertSame(['orderId'], $save['locked_properties']);
        $this->assertSame('guarded', $save['verdict']);
        $this->assertSame('unguarded', $result['surfaces']['livewire '.OrderEditor::class.'@removeOrder']['verdict']);
        $this->assertArrayNotHasKey('livewire '.OrderEditor::class.'@hydrate', $result['surfaces']);
        $this->assertArrayNotHasKey('livewire '.OrderEditor::class.'@internalHelper', $result['surfaces']);
        $this->assertCount(1, array_filter($result['surfaces'], fn ($profile) => str_ends_with($profile['class'], 'editor.blade.php')));
        $this->assertSame(CanonicalJson::encode($result), CanonicalJson::encode(app(LivewireCollector::class)->collect()));
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_filament_real_panel_resources_capture_policy_and_unconditional_overrides(): void
    {
        if (! class_exists('Filament\\Panel')) {
            $this->markTestSkipped('Optional Filament integration job installs its real package.');
        }
        app(PanelRegistry::class)->register(Panel::make()->id('fixture')->resources([OrderResource::class, OpenOrderResource::class])->pages([ReviewPage::class]));
        app(PanelRegistry::class)->register(Panel::make()->id('other')->authGuard('other')->resources([OrderResource::class]));
        $result = app(FilamentCollector::class)->collect();
        $this->assertSame('complete', $result['status'], implode(' ', $result['diagnostics']));
        $order = $result['surfaces']['filament fixture:resource:'.OrderResource::class];
        $this->assertSame('guarded', $order['verdict']);
        $this->assertContains('view', array_column($order['guards']['policies'], 'method'));
        $this->assertSame([], $order['access']);
        $this->assertSame('guarded', $result['surfaces']['filament fixture:page:'.ReviewPage::class]['verdict']);
        $page = $result['surfaces']['filament fixture:resource-page:'.OrderResource::class.'@'.OrderPage::class];
        $this->assertSame(OrderResource::class, $page['resource']);
        $this->assertStringEndsWith('OrderPage.php', $page['source']['file']);
        $this->assertSame(OpenOrderResource::class, $result['surfaces']['filament fixture:resource-page:'.OpenOrderResource::class.'@'.OrderPage::class]['resource']);
        $this->assertSame('other', $result['surfaces']['filament other:resource:'.OrderResource::class]['guard']);
        $open = $result['surfaces']['filament fixture:resource:'.OpenOrderResource::class];
        $this->assertSame('unguarded', $open['verdict']);
        $this->assertSame('true', $open['overrides']['canEdit']);
        OrderResource::skipAuthorization();
        try {
            $after = app(FilamentCollector::class)->collect();
            $this->assertTrue($after['surfaces']['filament fixture:resource:'.OrderResource::class]['skip_authorization']);
        } finally {
            OrderResource::skipAuthorization(false);
        }
    }

    public function test_filament_five_configured_instances_cannot_disappear_from_evidence(): void
    {
        if (! class_exists('Filament\\Resources\\ResourceConfiguration')) {
            $this->markTestSkipped('Filament 5 adds configured resource instances.');
        }
        $panel = Panel::make()->id('configured')->resources([
            ResourceConfiguration::make(OrderResource::class, 'active')->slug('active-orders'),
            ResourceConfiguration::make(OrderResource::class, 'archived')->slug('archived-orders'),
        ]);
        app(PanelRegistry::class)->register($panel);
        $result = app(FilamentCollector::class)->collect();
        $this->assertSame('complete', $result['status'], implode(' ', $result['diagnostics']));
        $resource = $result['surfaces']['filament configured:resource:'.OrderResource::class];
        $this->assertSame(['active', 'archived'], array_column($resource['configurations'], 'key'));
        $this->assertSame(['active-orders', 'archived-orders'], array_column($resource['configurations'], 'slug'));
    }

    public function test_static_guard_drift_and_lost_collector_coverage_block_without_runtime_claims(): void
    {
        $matrix = app(Clearance::class)->scan();
        $matrix['routes'] = [];
        $id = 'livewire Fixture@save';
        $surface = ['kind' => 'livewire', 'class' => 'Fixture', 'method' => 'save', 'evidence' => 'static',
            'guards' => ['middleware' => [], 'checks' => ['method:authorize'], 'policies' => [], 'scoping' => ['locked:id'], 'evidence' => ['body:original']],
            'access' => [], 'source' => null, 'verdict' => 'guarded', 'approved' => null];
        $matrix['surfaces'] = [$id => $surface];
        $matrix['surface_inventory'] = [$id];
        $matrix['collectors']['livewire']['status'] = 'complete';
        $after = $matrix;
        $after['surfaces'][$id]['guards']['evidence'] = ['body:changed'];
        $diff = app(MatrixDiff::class);
        $this->assertSame('static_changed', $diff->blocking($diff->compare($matrix, $after))[0]['type']);
        $after = $matrix;
        $after['surfaces'] = [];
        $after['surface_inventory'] = [];
        $after['collectors']['livewire']['status'] = 'unavailable';
        $this->assertSame('coverage_lost', $diff->blocking($diff->compare($matrix, $after))[0]['type']);
        $after['collectors']['livewire']['status'] = 'complete';
        $this->assertSame([], $diff->blocking($diff->compare($matrix, $after)));
        $added = $matrix;
        $added['surfaces'] = [];
        $this->assertSame('surface_added', $diff->blocking($diff->compare($added, $matrix))[0]['type']);
        $markdown = app(MarkdownRenderer::class)->render($matrix);
        $this->assertStringContainsString('No persona access', $markdown);
        $this->assertSame(0, Clearance::errors($matrix));
    }

    public function test_schema_migration_is_lossless_and_does_not_approve_new_surface_evidence(): void
    {
        $directory = sys_get_temp_dir().'/clearance-migration-'.bin2hex(random_bytes(6));
        mkdir($directory);
        config(['clearance.lock_path' => $directory.'/lock.json', 'clearance.markdown_path' => $directory.'/matrix.md']);
        try {
            $before = app(Clearance::class)->scan();
            $route = $before['routes']['GET protected/{order}'];
            $route['access'] = array_fill_keys($before['personas'], 'deny');
            $before['routes'] = [];
            $before['schema'] = 1;
            unset($before['surfaces'], $before['surface_inventory'], $before['collectors']);
            $before['routes']['GET fixture'] = $route;
            $before['inventory'][] = 'GET fixture';
            $before['routes']['GET fixture'] = app(ApprovalStore::class)->approve($before['routes']['GET fixture'], 'Prior review', 'reviewer');
            app(LockFile::class)->write($before);
            $this->artisan('clearance:migrate', ['--reason' => 'Upgrade format', '--by' => 'reviewer'])->assertSuccessful();
            $after = app(LockFile::class)->read();
            $this->assertSame(2, $after['schema']);
            $this->assertSame(CanonicalJson::sort($before['routes']), $after['routes']);
            $this->assertSame([], $after['surfaces']);
            $this->assertTrue(app(ApprovalStore::class)->valid($after['routes']['GET fixture']));
        } finally {
            app('files')->deleteDirectory($directory);
        }
    }

    public function test_filament_trait_authorization_body_changes_invalidate_approvals(): void
    {
        if (! class_exists(Panel::class)) {
            $this->markTestSkipped('Optional Filament integration job installs its real package.');
        }
        $directory = sys_get_temp_dir().'/clearance-trait-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $namespace = 'ClearanceTraitFixture'.bin2hex(random_bytes(6));
        $class = $namespace.'\\Resource';
        $file = $directory.'/Resource.php';
        $source = '<?php namespace '.$namespace.'; trait Decision { protected static function decide(\\Illuminate\\Database\\Eloquent\\Model $record): bool { return false; } } class Resource extends \\Dgtlss\\Clearance\\Tests\\Fixtures\\Resources\\OrderResource { use Decision { decide as public canEdit; } public static function getPages(): array { return []; } }';
        file_put_contents($file, $source);
        require $file;
        app(PanelRegistry::class)->register(Panel::make()->id('trait')->resources([$class]));
        try {
            $before = app(Clearance::class)->scan();
            $before['routes'] = [];
            $id = 'filament trait:resource:'.$class;
            $this->assertSame('false', $before['surfaces'][$id]['overrides']['canEdit']);
            $before['surfaces'][$id] = app(ApprovalStore::class)->approve($before['surfaces'][$id], 'Denial reviewed', 'reviewer');
            file_put_contents($file, str_replace('return false;', 'return true;', $source));
            $after = app(Clearance::class)->scan();
            $after['routes'] = [];
            $this->assertSame('true', $after['surfaces'][$id]['overrides']['canEdit']);
            $changes = app(MatrixDiff::class)->blocking(app(MatrixDiff::class)->compare($before, $after));
            $this->assertContains('static_changed', array_column($changes, 'type'));
            $applied = app(ApprovalStore::class)->apply($after, $before);
            $this->assertFalse(app(ApprovalStore::class)->valid($applied['surfaces'][$id]));
        } finally {
            app('files')->deleteDirectory($directory);
        }
    }

    public function test_anonymous_livewire_actions_remain_inventoried_when_moved_into_traits(): void
    {
        if (! class_exists('Livewire\\Component')) {
            $this->markTestSkipped('Optional Livewire integration job installs its real package.');
        }
        $directory = sys_get_temp_dir().'/clearance-anonymous-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $namespace = 'ClearanceActionFixture'.bin2hex(random_bytes(6));
        $trait = $directory.'/Edits.php';
        file_put_contents($trait, '<?php namespace '.$namespace.'; trait Edits { #[\\Livewire\\Attributes\\Locked] public int $recordId = 1; public function saveOrder(): string { return "dummy"; } }');
        require $trait;
        $file = $directory.'/component.blade.php';
        file_put_contents($file, '<?php new class extends \\Livewire\\Component { public function saveOrder(): void { \\Illuminate\\Support\\Facades\\Gate::authorize("edit"); } };');
        config(['clearance.detect.livewire_paths' => [$file]]);
        try {
            $before = app(Clearance::class)->scan();
            $before['routes'] = [];
            $id = array_key_first($before['surfaces']);
            $this->assertNotNull($id);
            file_put_contents($file, '<?php new class extends \\Livewire\\Component { use \\'.$namespace.'\\Edits; };');
            $after = app(Clearance::class)->scan();
            $after['routes'] = [];
            $this->assertSame('complete', $after['collectors']['livewire']['status'], implode(' ', $after['collectors']['livewire']['diagnostics']));
            $this->assertContains($id, $after['surface_inventory']);
            $this->assertArrayHasKey($id, $after['surfaces']);
            $this->assertSame(['recordId'], $after['surfaces'][$id]['locked_properties']);
            $changes = app(MatrixDiff::class)->blocking(app(MatrixDiff::class)->compare($before, $after));
            $this->assertContains('guard_removed', array_column($changes, 'type'));
            file_put_contents($file, '<?php new class extends \\Livewire\\Component { use \\'.$namespace.'\\Edits { saveOrder as private; } };');
            $incomplete = app(Clearance::class)->scan();
            $this->assertSame('error', $incomplete['collectors']['livewire']['status']);
            $this->assertContains('coverage_lost', array_column(app(MatrixDiff::class)->compare($after, $incomplete), 'type'));
        } finally {
            app('files')->deleteDirectory($directory);
        }
    }

    public function test_ambiguous_external_template_identities_report_incomplete_discovery(): void
    {
        if (! class_exists('Livewire\\Component')) {
            $this->markTestSkipped('Optional Livewire integration job installs its real package.');
        }
        $directory = sys_get_temp_dir().'/clearance-identity-'.bin2hex(random_bytes(6));
        mkdir($directory);
        mkdir($directory.'/first');
        mkdir($directory.'/second');
        $paths = [$directory.'/first/component.blade.php', $directory.'/second/component.blade.php'];
        foreach ($paths as $index => $path) {
            file_put_contents($path, '<?php new class extends \\Livewire\\Component { public function action'.$index.'(): void {} };');
        }
        config(['clearance.detect.livewire_paths' => $paths]);
        try {
            $result = app(LivewireCollector::class)->collect();
            $this->assertSame('error', $result['status']);
            $this->assertStringContainsString('Ambiguous anonymous Livewire identity', implode(' ', $result['diagnostics']));
        } finally {
            app('files')->deleteDirectory($directory);
        }
    }
}
