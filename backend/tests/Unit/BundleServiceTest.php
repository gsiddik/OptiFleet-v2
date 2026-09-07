<?php

namespace Tests\Unit;

use App\Domain\ProductCatalog\Models\Bundle;
use App\Domain\ProductCatalog\Models\Module;
use App\Domain\ProductCatalog\Services\BundleException;
use App\Domain\ProductCatalog\Services\BundleService;
use Tests\TestCase;

class BundleServiceTest extends TestCase
{
    private BundleService $service;

    protected function setUp(): void
    {
        parent::setUp(); // base TestCase already seeds the module dependency graph
        $this->service = app(BundleService::class);
    }

    public function test_publish_fails_when_dependencies_missing(): void
    {
        $bundle = Bundle::query()->create(['code' => 'B1', 'name' => 'B1', 'status' => 'DRAFT']);
        $workOrder = Module::query()->where('code', 'WORK_ORDER')->first();
        $this->service->syncModules($bundle, [$workOrder->id]); // missing VEHICLE, MAINTENANCE, WORKSHOP

        $missing = $this->service->missingDependencies($bundle);
        $this->assertTrue($missing->contains('VEHICLE'));
        $this->assertTrue($missing->contains('MAINTENANCE'));
        $this->assertTrue($missing->contains('WORKSHOP'));

        $this->expectException(BundleException::class);
        $this->service->publish($bundle);
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
