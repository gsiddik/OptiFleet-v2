<?php

namespace Tests\Unit;

use App\Domain\ProductCatalog\Models\Module;
use App\Domain\ProductCatalog\Services\ModuleDependencyException;
use App\Domain\ProductCatalog\Services\ModuleDependencyService;
use Tests\TestCase;

class ModuleDependencyServiceTest extends TestCase
{
    private ModuleDependencyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ModuleDependencyService::class);
    }

    private function module(string $code): Module
    {
        return Module::query()->where('code', $code)->firstOrFail();
    }

    public function test_direct_dependency_resolution(): void
    {
        $workOrder = $this->module('WORK_ORDER');
        $codes = $this->service->directDependencies($workOrder)->pluck('code')->sort()->values();

        $this->assertEquals(['MAINTENANCE', 'VEHICLE', 'WORKSHOP'], $codes->all());
    }

    public function test_transitive_dependency_resolution(): void
    {
        // MAINTENANCE_INTELLIGENCE -> VEHICLE, MAINTENANCE, TELEMATICS, HISTORY
        // MAINTENANCE -> VEHICLE (transitively repeats VEHICLE, should dedupe)
        $mi = $this->module('MAINTENANCE_INTELLIGENCE');
        $codes = $this->service->transitiveDependencies($mi)->pluck('code')->sort()->values();

        $this->assertEquals(['HISTORY', 'MAINTENANCE', 'TELEMATICS', 'VEHICLE'], $codes->all());
    }

    public function test_reverse_dependents(): void
    {
        $vehicle = $this->module('VEHICLE');
        $dependents = $this->service->directDependents($vehicle)->pluck('code')->sort()->values();

        $this->assertTrue($dependents->contains('WORK_ORDER'));
        $this->assertTrue($dependents->contains('INSPECTION'));
        $this->assertTrue($dependents->contains('MAINTENANCE'));
    }

    public function test_circular_dependency_is_rejected(): void
    {
        $vehicle = $this->module('VEHICLE');
        $maintenance = $this->module('MAINTENANCE'); // MAINTENANCE already depends on VEHICLE

        $this->expectException(ModuleDependencyException::class);
        // Adding VEHICLE -> MAINTENANCE would create a cycle since MAINTENANCE -> VEHICLE already exists
        $this->service->addDependency($vehicle->id, $maintenance->id);
    }

    public function test_self_dependency_is_rejected(): void
    {
        $vehicle = $this->module('VEHICLE');

        $this->expectException(ModuleDependencyException::class);
        $this->service->addDependency($vehicle->id, $vehicle->id);
    }

    public function test_valid_new_dependency_is_accepted(): void
    {
        $report = $this->module('REPORT');
        $history = $this->module('HISTORY');

        $dependency = $this->service->addDependency($report->id, $history->id);

        $this->assertSame($report->id, $dependency->module_id);
        $this->assertSame($history->id, $dependency->depends_on_module_id);
    }
}
