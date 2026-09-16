<?php

namespace Tests\Unit;

use App\Domain\ProductCatalog\Models\Bundle;
use App\Domain\ProductCatalog\Models\Module;
use App\Domain\ProductCatalog\Services\BundleException;
use App\Domain\ProductCatalog\Services\BundleService;
use App\Domain\ProductCatalog\Services\ModuleDependencyException;
use App\Domain\ProductCatalog\Services\ModuleDependencyService;
use Tests\TestCase;

class BundleServiceTest extends TestCase
{
    private BundleService $service;

    protected function setUp(): void
    {
        parent::setUp(); // base TestCase already seeds the module dependency graph
        $this->service = app(BundleService::class);
    }

    public function test_sync_modules_auto_adds_transitive_dependencies(): void
    {
        $bundle = Bundle::query()->create(['code' => 'B1', 'name' => 'B1', 'status' => 'DRAFT']);
        $workOrder = Module::query()->where('code', 'WORK_ORDER')->first();

        $autoAdded = $this->service->syncModules($bundle, [$workOrder->id]);

        // WORK_ORDER directly depends on VEHICLE, MAINTENANCE, WORKSHOP
        // (MAINTENANCE also transitively depends on VEHICLE) — all three
        // must be auto-added and checked without the user selecting them.
        $addedCodes = $autoAdded->pluck('module.code')->all();
        $this->assertEqualsCanonicalizing(['MAINTENANCE', 'VEHICLE', 'WORKSHOP'], $addedCodes);

        $entry = $autoAdded->firstWhere('module.code', 'VEHICLE');
        $this->assertContains('WORK_ORDER', $entry['required_by']);

        $composedCodes = $bundle->fresh()->modules()->pluck('code')->all();
        $this->assertEqualsCanonicalizing(['MAINTENANCE', 'VEHICLE', 'WORK_ORDER', 'WORKSHOP'], $composedCodes);

        // Composition is now dependency-complete by construction, so it
        // publishes without any manual gap-filling.
        $this->assertTrue($this->service->missingDependencies($bundle)->isEmpty());
        $version = $this->service->publish($bundle);
        $this->assertCount(4, $version->modules);
    }

    public function test_removing_a_module_still_needed_by_another_selection_keeps_it(): void
    {
        $bundle = Bundle::query()->create(['code' => 'B1B', 'name' => 'B1B', 'status' => 'DRAFT']);
        $workOrder = Module::query()->where('code', 'WORK_ORDER')->first();
        $vehicle = Module::query()->where('code', 'VEHICLE')->first();
        $this->service->syncModules($bundle, [$workOrder->id]);

        // User "removes" VEHICLE by re-submitting without it, but WORK_ORDER
        // (still selected) transitively needs it, so it must be kept.
        $autoAdded = $this->service->syncModules($bundle, [$workOrder->id]);
        $this->assertTrue($autoAdded->pluck('module.code')->contains('VEHICLE'));
        $this->assertTrue($bundle->fresh()->modules()->where('code', 'VEHICLE')->exists());
        $this->assertNotNull($vehicle);
    }

    public function test_missing_dependencies_guard_still_catches_a_graph_change_after_composition(): void
    {
        $bundle = Bundle::query()->create(['code' => 'B1C', 'name' => 'B1C', 'status' => 'DRAFT']);
        $partner = Module::query()->where('code', 'PARTNER')->first();
        $this->service->syncModules($bundle, [$partner->id]);
        $this->assertTrue($this->service->missingDependencies($bundle)->isEmpty());

        // Simulate the module catalog evolving after this bundle was
        // composed: PARTNER now also requires WARRANTY.
        $warranty = Module::query()->where('code', 'WARRANTY')->first();
        app(ModuleDependencyService::class)->addDependency($partner->id, $warranty->id);

        $missing = $this->service->missingDependencies($bundle);
        $this->assertTrue($missing->contains('WARRANTY'));

        $this->expectException(BundleException::class);
        $this->service->publish($bundle);
    }

    public function test_sync_modules_rejects_when_module_graph_has_a_cycle(): void
    {
        $bundle = Bundle::query()->create(['code' => 'B1D', 'name' => 'B1D', 'status' => 'DRAFT']);
        $vehicle = Module::query()->where('code', 'VEHICLE')->first();
        $maintenance = Module::query()->where('code', 'MAINTENANCE')->first();

        // Force a cycle directly at the pivot-table level, bypassing
        // ModuleDependencyService::addDependency's own guard, to prove
        // BundleService::syncModules independently rejects it too.
        $edge = new \App\Domain\ProductCatalog\Models\ModuleDependency([
            'module_id' => $vehicle->id,
            'depends_on_module_id' => $maintenance->id,
        ]);
        $edge->save();

        $this->expectException(ModuleDependencyException::class);
        $this->service->syncModules($bundle, [$vehicle->id]);
    }

    public function test_publish_succeeds_with_complete_composition(): void
    {
        $bundle = Bundle::query()->create(['code' => 'B2', 'name' => 'B2', 'status' => 'DRAFT']);
        $moduleIds = Module::query()->whereIn('code', ['CORE', 'ORGANIZATION', 'VEHICLE', 'MAINTENANCE', 'WORKSHOP', 'WORK_ORDER'])->pluck('id');
        $this->service->syncModules($bundle, $moduleIds->all());

        $version = $this->service->publish($bundle);

        $this->assertSame(1, $version->version_number);
        $this->assertSame('PUBLISHED', $bundle->fresh()->status);
        $this->assertCount(6, $version->modules);
    }

    public function test_publishing_twice_creates_new_version_preserving_old_snapshot(): void
    {
        $bundle = Bundle::query()->create(['code' => 'B3', 'name' => 'B3', 'status' => 'DRAFT']);
        $this->service->syncModules($bundle, Module::query()->whereIn('code', ['CORE'])->pluck('id')->all());
        $v1 = $this->service->publish($bundle);

        $bundle->update(['status' => 'DRAFT']); // simulate reopening for edits
        $this->service->syncModules($bundle, Module::query()->whereIn('code', ['CORE', 'ORGANIZATION'])->pluck('id')->all());
        $v2 = $this->service->publish($bundle);

        $this->assertSame(1, $v1->version_number);
        $this->assertSame(2, $v2->version_number);
        $this->assertCount(1, $v1->fresh()->modules);
        $this->assertCount(2, $v2->fresh()->modules);
    }

    public function test_cannot_publish_empty_bundle(): void
    {
        $bundle = Bundle::query()->create(['code' => 'B4', 'name' => 'B4', 'status' => 'DRAFT']);

        $this->expectException(BundleException::class);
        $this->service->publish($bundle);
    }
}
