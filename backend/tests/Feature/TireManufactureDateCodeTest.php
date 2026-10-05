<?php

namespace Tests\Feature;

use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireUsedInspection;
use App\Domain\Tire\Services\TireAgeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tire Inspection → Tire Identity: a missing Manufacture Date Code (DOT "WWYY") is filled in from
 * the inspection page and saved on the physical tire; Tire Age comes from TireAgeService (the one
 * backend formula); inspection snapshots taken before keep their original facts.
 */
class TireManufactureDateCodeTest extends TestCase
{
    private function scenario(array $permissions = ['tire.view', 'tire.manage', 'tire.install', 'tire.remove', 'tire.inspect']): array
    {
        $tenant = $this->makeTenant(['code' => 'MDC-'.Str::random(4)]);
        foreach (['VEHICLE', 'INVENTORY', 'TIRE'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory());
        [, $token] = $this->makeTenantUser($tenant, $permissions);
        $product = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE', 'name' => 'Highway Rib', 'brand' => 'Bridgestone']);
        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'MDC-'.Str::upper(Str::random(6)), 'current_status' => 'IN_STOCK']);
        $headers = $this->authHeaders($token);

        return compact('tenant', 'vehicle', 'tire', 'headers');
    }

    private function removed(array $s): Tire
    {
        $this->postJson("/api/v1/app/tires/{$s['tire']->id}/install", ['vehicle_id' => $s['vehicle']->id, 'wheel_position' => 'RL', 'odometer' => 1000], $s['headers'])->assertStatus(201);
        $this->postJson("/api/v1/app/tires/{$s['tire']->id}/remove", ['removal_reason' => 'Worn', 'disposition' => 'REUSE', 'odometer' => 6000], $s['headers'])->assertSuccessful();

        return $s['tire']->fresh();
    }

    public function test_dot_code_parsing_and_age_are_computed_in_one_place(): void
    {
        $age = app(TireAgeService::class);
        $now = CarbonImmutable::parse('2026-10-05 10:00:00');

        $this->assertSame('2025-03-17', $age->manufactureDate('1225', $now)?->toDateString());
        $this->assertSame(18, $age->ageMonths('1225', $now));
        $this->assertSame(0, $age->ageMonths('4026', $now), 'week 40 of 2026 started on 28 Sep 2026');
        $this->assertSame('2020-12-21', $age->manufactureDate('5220', $now)?->toDateString());
        $this->assertNotNull($age->manufactureDate('5320', $now), '2020 has an ISO week 53');
        foreach (['5321', '4226', '0025', '5425', '1227', 'AB25', '12-25', '', null] as $invalid) {
            $this->assertNull($age->manufactureDate($invalid, $now), "'{$invalid}' is not a valid DOT code");
            $this->assertNull($age->ageMonths($invalid, $now));
        }
    }

    public function test_missing_code_is_saved_to_the_physical_tire_and_the_age_refreshes(): void
    {
        $s = $this->scenario();
        $tire = $this->removed($s);
        $context = $this->getJson("/api/v1/app/tires/{$tire->id}/used-inspection/context", $s['headers'])->assertOk()->json('data.tire');
        $this->assertSame([null, null], [$context['manufacture_date_code'], $context['age_months']]);

        $this->putJson("/api/v1/app/tires/{$tire->id}/manufacture-date-code", ['manufacture_date_code' => '5425'], $s['headers'])
            ->assertStatus(422)->assertJsonValidationErrors('manufacture_date_code');
        $this->putJson("/api/v1/app/tires/{$tire->id}/manufacture-date-code", [], $s['headers'])->assertStatus(422);
        $this->assertNull($tire->fresh()->manufacture_date_code);

        $facts = $this->putJson("/api/v1/app/tires/{$tire->id}/manufacture-date-code", ['manufacture_date_code' => ' 1225 '], $s['headers'])->assertOk()->json('data');
        $expected = app(TireAgeService::class)->ageMonths('1225');
        $this->assertSame(['1225', $expected], [$facts['manufacture_date_code'], $facts['age_months']]);
        $this->assertSame('1225', $tire->fresh()->manufacture_date_code, 'saved on the physical tire record');

        // Reload: the value persists and the age is recalculated from it.
        $reloaded = $this->getJson("/api/v1/app/tires/{$tire->id}/used-inspection/context", $s['headers'])->json('data.tire');
        $this->assertSame(['1225', $expected], [$reloaded['manufacture_date_code'], $reloaded['age_months']]);
        $this->assertSame('1225', $this->getJson("/api/v1/app/tires/{$tire->id}", $s['headers'])->json('data.manufacture_date_code'));

        // Fill-only: an existing code is not overwritten from the inspection page.
        $this->putJson("/api/v1/app/tires/{$tire->id}/manufacture-date-code", ['manufacture_date_code' => '0124'], $s['headers'])->assertStatus(422);
        $this->assertSame('1225', $tire->fresh()->manufacture_date_code);
    }

    public function test_recorded_inspection_snapshot_is_not_rewritten(): void
    {
        $s = $this->scenario();
        $tire = $this->removed($s);
        $points = collect([1, 2, 3])->flatMap(fn ($z) => [['zone' => $z, 'groove' => 'INNER_MAIN', 'depth_mm' => '9'], ['zone' => $z, 'groove' => 'OUTER_MAIN', 'depth_mm' => '9']])->all();
        $id = $this->postJson("/api/v1/app/tires/{$tire->id}/used-inspections", [
            'identity_status' => 'PARTIALLY_UNKNOWN', 'internal_inspected' => 'YES', 'wear_pattern' => 'EVEN', 'bulge_separation' => 'NONE',
            'cord_exposure' => 'NONE', 'sidewall_condition' => 'NORMAL', 'bead_condition' => 'NORMAL', 'inner_liner_condition' => 'NORMAL',
            'run_flat_overheat' => 'NO', 'leak_foreign_object' => 'NO', 'previous_repair' => 'NONE', 'age_chemical' => 'NONE',
            'casing_compliance' => 'CANNOT_CONFIRM', 'measurements' => $points,
        ], $s['headers'])->assertStatus(201)->json('data.id');

        $this->putJson("/api/v1/app/tires/{$tire->id}/manufacture-date-code", ['manufacture_date_code' => '1225'], $s['headers'])->assertOk();

        $snapshot = TireUsedInspection::query()->findOrFail($id)->tire_snapshot;
        $this->assertNull($snapshot['manufacture_date_code'], 'the recorded inspection keeps the facts it was taken with');
        $this->assertNull($snapshot['age_months']);
        $this->assertSame('1225', $tire->fresh()->manufacture_date_code);
    }

    public function test_permission_tenant_and_scope_are_enforced(): void
    {
        $s = $this->scenario(['tire.view']);
        $this->putJson("/api/v1/app/tires/{$s['tire']->id}/manufacture-date-code", ['manufacture_date_code' => '1225'], $s['headers'])->assertStatus(403);

        $other = $this->scenario();
        $this->putJson("/api/v1/app/tires/{$s['tire']->id}/manufacture-date-code", ['manufacture_date_code' => '1225'], $other['headers'])->assertStatus(404);
        $this->assertNull($s['tire']->fresh()->manufacture_date_code);
    }
}
