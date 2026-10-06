<?php

namespace Tests\Feature;

use App\Domain\Tire\Models\ProductTireSpec;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireInspection;
use App\Domain\Tire\Models\TireLoadIndex;
use App\Domain\Tire\Models\TireSpeedRating;
use App\Domain\Tire\Models\TireUsedInspection;
use App\Domain\Tire\Models\UsedTireStock;
use App\Domain\Tire\Models\UsedTireStockMovement;
use App\Domain\Tire\Services\TireService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Used Tire Management → Removed → Inspect: auto-filled facts, the category rule profile (versioned,
 * snapshotted), submit → approve, and the inventory effect of each disposition.
 */
class TireUsedInspectionTest extends TestCase
{
    private const INSPECTOR = ['tire.view', 'tire.manage', 'tire.install', 'tire.remove', 'tire.inspect'];

    private const ALL = [...self::INSPECTOR, 'tire_used_inspection.approve', 'tire_rule_profile.manage'];

    private function scenario(string $group = 'TRUCK_BUS'): array
    {
        $tenant = $this->makeTenant(['code' => 'TUI-'.Str::random(4), 'timezone' => 'Asia/Jakarta']);
        foreach (['VEHICLE', 'INVENTORY', 'TIRE'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $warehouse = $this->makeWarehouse($tenant, $branch, $this->makeWorkshop($tenant, $branch));
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory(), ['registration_number' => 'B 88 TUI']);
        [$user, $token] = $this->makeTenantUser($tenant, self::ALL);
        $product = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE', 'name' => 'Highway Rib', 'brand' => 'Bridgestone', 'reference_tread_depth_mm' => '16.00']);
        ProductTireSpec::query()->create([
            'product_id' => $product->id, 'vehicle_group' => $group, 'pattern_name' => 'R150', 'width_mm' => 295, 'aspect_ratio_percent' => 80,
            'construction_type' => 'RADIAL', 'rim_diameter_inch' => 22.5, 'tire_type' => 'TUBELESS', 'tire_size_computed' => '295/80 R22.5',
            'single_load_index_id' => TireLoadIndex::query()->firstOrCreate(['code' => '152'], ['max_load_single_kg' => 3550, 'status' => 'ACTIVE'])->id,
            'speed_rating_id' => TireSpeedRating::query()->firstOrCreate(['code' => 'M'], ['max_speed_kmh' => 130, 'status' => 'ACTIVE'])->id,
        ]);

        return ['tenant' => $tenant, 'warehouse' => $warehouse, 'vehicle' => $vehicle, 'product' => $product, 'user' => $user, 'headers' => $this->authHeaders($token)];
    }

    private function profile(array $s, array $overrides = [], int $status = 201)
    {
        return $this->postJson('/api/v1/app/tire-rule-profiles', array_replace_recursive([
            'name' => 'Truck / Bus — line haul', 'tire_category' => 'TRUCK_BUS',
            'd_service_mm' => '3', 'd_pull_mm' => '5', 'a_max_months' => 120, 'a_retread_max_months' => 84, 'n_retread_max' => 2,
            'repair_limits' => [
                'allowed_locations' => ['TREAD', 'SHOULDER'], 'max_puncture_diameter_mm' => 10, 'max_cut_length_mm' => 25, 'max_cut_width_mm' => 5,
                'max_cut_depth_mm' => 8, 'max_repairs' => 2, 'allow_overlap_previous_repair' => false, 'allow_reinforcement_damage' => false,
            ],
            'application_limits' => ['positions' => ['DRIVE', 'TRAILER'], 'max_speed_kmh' => 100, 'notes' => 'Not on steer axle after repair.'],
        ], $overrides), $s['headers'])->assertStatus($status);
    }

    /** Installed at 1000 km, removed at 6000 km for "worn": status REMOVED. */
    private function removedTire(array $s, string $serial, string $dot = '2322'): Tire
    {
        $tire = Tire::query()->create(['tenant_id' => $s['tenant']->id, 'product_id' => $s['product']->id, 'serial_number' => $serial, 'manufacture_date_code' => $dot, 'construction_type' => 'RADIAL', 'current_status' => 'IN_STOCK']);
        $this->postJson("/api/v1/app/tires/{$tire->id}/install", ['vehicle_id' => $s['vehicle']->id, 'wheel_position' => 'RL', 'odometer' => 1000], $s['headers'])->assertStatus(201);
        $this->postJson("/api/v1/app/tires/{$tire->id}/remove", ['removal_reason' => 'Worn on shoulder', 'disposition' => 'REUSE', 'odometer' => 6000], $s['headers'])->assertSuccessful();

        return $tire->fresh();
    }

    private function answers(array $overrides = []): array
    {
        $points = [];
        foreach ([1, 2, 3] as $zone) {
            foreach (['INNER_MAIN', 'OUTER_MAIN'] as $groove) {
                $points[] = ['zone' => $zone, 'groove' => $groove, 'depth_mm' => $zone === 2 && $groove === 'OUTER_MAIN' ? ($overrides['min_depth'] ?? '9.0') : '11.0'];
            }
        }
        unset($overrides['min_depth']);

        return array_merge([
            'identity_status' => 'COMPLETE', 'internal_inspected' => 'YES', 'wear_pattern' => 'EVEN', 'bulge_separation' => 'NONE',
            'cord_exposure' => 'NONE', 'sidewall_condition' => 'NORMAL', 'bead_condition' => 'NORMAL', 'inner_liner_condition' => 'NORMAL',
            'run_flat_overheat' => 'NO', 'leak_foreign_object' => 'NO', 'previous_repair' => 'NONE', 'age_chemical' => 'NONE',
            'casing_compliance' => 'MEETS', 'measurements' => $points,
        ], $overrides);
    }

    private function inspect(array $s, Tire $tire, array $answers, int $status = 201)
    {
        return $this->postJson("/api/v1/app/tires/{$tire->id}/used-inspections", $answers, $s['headers'])->assertStatus($status);
    }

    private function approve(array $s, string $inspectionId, array $body = [], int $status = 200)
    {
        return $this->postJson("/api/v1/app/tire-used-inspections/{$inspectionId}/approve", $body, $s['headers'])->assertStatus($status);
    }

    private function inventory(array $s): array
    {
        return $this->getJson("/api/v1/app/tire-products/{$s['product']->id}", $s['headers'])->json('data.inventory');
    }

    public function test_context_auto_fills_the_tire_facts_and_the_category_profile(): void
    {
        $s = $this->scenario();
        $this->profile($s);
        $tire = $this->removedTire($s, 'UI-CTX');

        $ctx = $this->getJson("/api/v1/app/tires/{$tire->id}/used-inspection/context", $s['headers'])->assertOk()->json('data');
        $facts = $ctx['tire'];
        $this->assertSame(['UI-CTX', 'Bridgestone', 'R150', '295/80 R22.5', 'RADIAL', 'TRUCK_BUS', 'Truck / Bus', '2322', 0, 'B 88 TUI', 'RL', '5000.00', 'Worn on shoulder', '16.00'], [
            $facts['serial_number'], $facts['brand'], $facts['model'], $facts['size'], $facts['construction'], $facts['category'], $facts['category_label'],
            $facts['manufacture_date_code'], $facts['retread_count'], $facts['last_vehicle'], $facts['last_position'], $facts['usage_km'], $facts['removal_reason'], $facts['d_new_default_mm'],
        ]);
        $this->assertIsInt($facts['age_months']);
        $this->assertNotEmpty($facts['inspector']);
        $this->assertSame([true, 1, 'TRUCK_BUS'], [$ctx['can_inspect'], $ctx['rule_profile']['version'], $ctx['rule_profile']['tire_category']]);
    }

    /** Usage restrictions (profile positions DRIVE, TRAILER) warn at installation; they do not block yet. */
    public function test_a_reuse_tire_outside_its_allowed_positions_is_installed_with_a_warning(): void
    {
        $s = $this->scenario();
        $this->profile($s);
        $reused = [];
        foreach (['UI-POS-1', 'UI-POS-2'] as $serial) {
            $tire = $this->removedTire($s, $serial);
            $this->approve($s, $this->inspect($s, $tire, $this->answers())->json('data.id'), ['warehouse_id' => $s['warehouse']->id]);
            $reused[] = $tire->fresh();
        }

        $candidate = collect($this->getJson("/api/v1/app/tire-operations/replacement-candidates?product_id={$s['product']->id}", $s['headers'])->json('data'))->firstWhere('serial_number', 'UI-POS-1');
        $this->assertSame(['DRIVE', 'TRAILER'], $candidate['usage_restrictions']['positions']);
        $this->assertSame(100, $candidate['usage_restrictions']['max_speed_kmh']);

        $outside = $this->postJson("/api/v1/app/tires/{$reused[0]->id}/install", ['vehicle_id' => $s['vehicle']->id, 'wheel_position' => 'FL', 'odometer' => 7000], $s['headers'])->assertStatus(201);
        $this->assertCount(1, $outside->json('warnings'));
        $this->assertStringContainsString('restricted to position(s) DRIVE, TRAILER', $outside->json('warnings.0'));
        $this->assertSame('INSTALLED', $reused[0]->fresh()->current_status, 'warning only — not blocked');

        $inside = $this->postJson("/api/v1/app/tires/{$reused[1]->id}/install", ['vehicle_id' => $s['vehicle']->id, 'wheel_position' => 'drive', 'odometer' => 7000], $s['headers'])->assertStatus(201);
        $this->assertSame([], $inside->json('warnings'));

        // A new-stock tire carries no restriction.
        $fresh = Tire::query()->create(['tenant_id' => $s['tenant']->id, 'product_id' => $s['product']->id, 'serial_number' => 'UI-NEW', 'current_status' => 'IN_STOCK']);
        $this->postJson("/api/v1/app/tires/{$fresh->id}/install", ['vehicle_id' => $s['vehicle']->id, 'wheel_position' => 'FR', 'odometer' => 7000], $s['headers'])
            ->assertStatus(201)->assertJsonPath('warnings', []);
    }

    public function test_reuse_inspection_returns_the_tire_to_reusable_stock_with_the_profile_snapshot(): void
    {
        $s = $this->scenario();
        $profileId = $this->profile($s)->json('data.id');
        $tire = $this->removedTire($s, 'UI-REUSE');

        $this->postJson("/api/v1/app/tires/{$tire->id}/used-inspection/evaluate", $this->answers(), $s['headers'])->assertOk()->assertJsonPath('data.recommendation', 'REUSE');
        $inspection = $this->inspect($s, $tire, $this->answers(['d_new_mm' => '16']))->json('data');
        $this->assertSame(['SUBMITTED', 'REUSE', '9.00', '46.15', 1], [$inspection['status'], $inspection['recommendation'], $inspection['d_min_mm'], $inspection['remaining_tread_percent'], $inspection['rule_profile_version']]);
        $this->assertCount(6, $inspection['measurements']);
        $this->assertSame('REMOVED', $tire->fresh()->current_status, 'submitting does not change the tire');
        $this->assertSame(0, $this->inventory($s)['reusable_qty']);
        // The measured tread is in the tire's inspection history.
        $this->assertSame('9.00', (string) TireInspection::query()->where('tire_id', $tire->id)->latest('inspected_at')->value('tread_depth_mm'));

        $this->approve($s, $inspection['id'], [], 422); // REUSE needs a warehouse
        $approved = $this->approve($s, $inspection['id'], ['warehouse_id' => $s['warehouse']->id])->json('data');
        $this->assertSame(['APPROVED', 'REUSE'], [$approved['status'], $approved['final_disposition']]);
        $this->assertSame(['REUSE', $s['warehouse']->id], [$tire->fresh()->current_status, $tire->fresh()->current_warehouse_id]);
        // …and is received into that warehouse's used tire quantity (issued through Part Requests).
        $this->assertSame(1, UsedTireStock::query()->where('warehouse_id', $s['warehouse']->id)->where('product_id', $s['product']->id)->value('quantity_on_hand'));
        $this->assertSame('INSPECTION_RECEIPT', UsedTireStockMovement::query()->where('tire_id', $tire->id)->value('movement_type'));
        $this->assertSame(1, $this->inventory($s)['reusable_qty']);
        $this->assertSame('REUSE', collect($this->getJson("/api/v1/app/tire-operations/replacement-candidates?product_id={$s['product']->id}", $s['headers'])->json('data'))->firstWhere('serial_number', 'UI-REUSE')['source']);

        // A later rule change does not rewrite the stored result.
        $this->putJson("/api/v1/app/tire-rule-profiles/{$profileId}", $this->profilePayload(['d_pull_mm' => '10']), $s['headers'])->assertOk()->assertJsonPath('data.version', 2);
        $stored = $this->getJson("/api/v1/app/tire-used-inspections/{$inspection['id']}", $s['headers'])->json('data');
        $this->assertSame([1, '5.00', 'REUSE'], [$stored['rule_profile_version'], $stored['thresholds']['d_pull_mm'], $stored['recommendation']]);
    }

    public function test_each_disposition_sets_the_status_and_only_reuse_is_available(): void
    {
        $s = $this->scenario();
        $this->profile($s);
        $cases = [
            'HOLD' => [$this->answers(['internal_inspected' => 'NOT_YET']), 'HOLD'],
            'SCRAP' => [$this->answers(['bulge_separation' => 'PRESENT']), 'SCRAPPED'],
            'REPAIR' => [$this->answers(['leak_foreign_object' => 'YES', 'repair_eligibility' => 'YES', 'damages' => [['location' => 'TREAD', 'damage_type' => 'PUNCTURE', 'diameter_mm' => 6, 'reaches_reinforcement' => 'NO', 'overlaps_previous_repair' => 'NO']]]), 'REPAIR'],
            'RETREAD' => [$this->answers(['min_depth' => '4.5', 'specialist_result' => 'ACCEPTED']), 'RETREAD'],
        ];
        foreach ($cases as $recommendation => [$answers, $status]) {
            $tire = $this->removedTire($s, "UI-{$recommendation}");
            $inspection = $this->inspect($s, $tire, $answers)->json('data');
            $this->assertSame($recommendation, $inspection['recommendation']);
            $this->assertNotEmpty($inspection['reasons']);
            $this->approve($s, $inspection['id']);
            $this->assertSame($status, $tire->fresh()->current_status);
        }
        $this->assertSame(0, $this->inventory($s)['reusable_qty'], 'HOLD / REPAIR / RETREAD / SCRAP are not reusable stock');
        $this->assertSame([], collect($this->getJson("/api/v1/app/tire-operations/replacement-candidates?product_id={$s['product']->id}", $s['headers'])->json('data'))->pluck('serial_number')->all());
        $used = collect($this->getJson("/api/v1/app/tire-products/{$s['product']->id}/inventory?category=USED", $s['headers'])->json('data'))->pluck('current_status', 'serial_number');
        $this->assertSame(['HOLD', 'REPAIR', 'RETREAD'], $used->sort()->values()->all(), 'SCRAP is terminal (not stock), the others stay visible');
    }

    public function test_hold_explains_why_and_a_hold_tire_can_be_inspected_again(): void
    {
        $s = $this->scenario();
        $this->profile($s);
        $tire = $this->removedTire($s, 'UI-HOLD');
        $hold = $this->inspect($s, $tire, $this->answers(['min_depth' => '4.5']))->json('data');
        $this->assertSame(['HOLD', 'RETREAD_CANDIDATE'], [$hold['recommendation'], $hold['recommendation_detail']]);
        $this->assertStringContainsString('awaiting the final retreader inspection', implode(' ', $hold['reasons']));
        $this->inspect($s, $tire, $this->answers(), 422); // one open inspection per tire
        $this->approve($s, $hold['id']);
        $this->assertSame('HOLD', $tire->fresh()->current_status);

        $again = $this->inspect($s, $tire, $this->answers(['min_depth' => '4.5', 'specialist_result' => 'ACCEPTED']))->json('data');
        $this->assertSame(['RETREAD', 'HOLD'], [$again['recommendation'], $again['tire_status_before']]);
    }

    public function test_category_rules_do_not_cross_categories_and_only_removed_or_hold_tires_are_inspected(): void
    {
        $s = $this->scenario('CAR');
        $this->profile($s); // a TRUCK_BUS profile only
        $tire = $this->removedTire($s, 'UI-CAR');
        $car = $this->inspect($s, $tire, $this->answers())->json('data');
        $this->assertSame(['HOLD', 'PASSENGER_LT', null], [$car['recommendation'], $car['tire_category'], $car['rule_profile_version']]);
        $this->assertStringContainsString('No active inspection rule profile', $car['reasons'][0]);

        $new = Tire::query()->create(['tenant_id' => $s['tenant']->id, 'product_id' => $s['product']->id, 'serial_number' => 'UI-NEW', 'current_status' => 'IN_STOCK']);
        $this->inspect($s, $new, $this->answers(), 422);
    }

    public function test_rule_profile_validation_and_uniqueness(): void
    {
        $s = $this->scenario();
        $this->profile($s, ['d_service_mm' => '5', 'd_pull_mm' => '3'], 422);
        $this->profile($s);
        $this->profile($s, ['name' => 'Duplicate'], 422);
        $this->profile($s, ['name' => 'Steer', 'application' => 'STEER']); // more specific profile is allowed
        $this->profile($s, ['name' => 'Bad location', 'application' => 'X', 'repair_limits' => ['allowed_locations' => ['ROOF']]], 422);
        $this->assertCount(2, $this->getJson('/api/v1/app/tire-rule-profiles', $s['headers'])->assertOk()->json('data'));
    }

    public function test_permissions_evidence_and_tenant_isolation(): void
    {
        Storage::fake('local');
        $s = $this->scenario();
        $this->profile($s);
        $tire = $this->removedTire($s, 'UI-PERM');

        [, $viewer] = $this->makeTenantUser($s['tenant'], ['tire.view']);
        $this->postJson("/api/v1/app/tires/{$tire->id}/used-inspections", $this->answers(), $this->authHeaders($viewer))->assertForbidden();
        $this->postJson('/api/v1/app/tire-rule-profiles', [], $this->authHeaders($viewer))->assertForbidden();
        [, $inspectorOnly] = $this->makeTenantUser($s['tenant'], self::INSPECTOR);
        $inspection = $this->postJson("/api/v1/app/tires/{$tire->id}/used-inspections", $this->answers(), $this->authHeaders($inspectorOnly))->assertStatus(201)->json('data');
        $this->postJson("/api/v1/app/tire-used-inspections/{$inspection['id']}/approve", ['warehouse_id' => $s['warehouse']->id], $this->authHeaders($inspectorOnly))->assertForbidden();

        $photo = UploadedFile::fake()->image('close-up.jpg', 400, 300);
        $evidence = $this->post("/api/v1/app/tire-used-inspections/{$inspection['id']}/evidence", ['file' => $photo, 'kind' => 'CLOSE_UP_SCALE', 'notes' => 'with ruler'], $s['headers'] + ['Accept' => 'application/json'])->assertStatus(201)->json('data');
        $this->post("/api/v1/app/tire-used-inspections/{$inspection['id']}/evidence", ['file' => UploadedFile::fake()->createWithContent('n.txt', 'x'), 'kind' => 'DAMAGE_PHOTO'], $s['headers'] + ['Accept' => 'application/json'])->assertStatus(422);
        $this->get("/api/v1/app/tire-used-inspections/{$inspection['id']}/evidence/{$evidence['id']}", $s['headers'])->assertOk();
        $this->assertCount(1, $this->getJson("/api/v1/app/tire-used-inspections/{$inspection['id']}", $s['headers'])->json('data.evidence'));

        $other = $this->scenario();
        $this->getJson("/api/v1/app/tire-used-inspections/{$inspection['id']}", $other['headers'])->assertNotFound();
        $this->getJson("/api/v1/app/tires/{$tire->id}/used-inspection/context", $other['headers'])->assertNotFound();

        $this->postJson("/api/v1/app/tire-used-inspections/{$inspection['id']}/cancel", [], $s['headers'])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->assertSame('REMOVED', $tire->fresh()->current_status);
        $this->assertSame(1, TireUsedInspection::query()->where('tire_id', $tire->id)->count(), 'cancelled inspections are kept');
    }

    /** RETREAD → retread cycle → back to REMOVED (mandatory re-inspection) → inspection sees retread count 1. */
    public function test_retread_and_repair_flows_return_for_reinspection_before_reuse(): void
    {
        $s = $this->scenario();
        $this->profile($s);
        $service = app(TireService::class);
        $partner = $this->makePartner($s['tenant'], ['partner_type' => 'EXTERNAL_WORKSHOP', 'status' => 'ACTIVE']);

        $tire = $this->removedTire($s, 'UI-RT');
        $this->approve($s, $this->inspect($s, $tire, $this->answers(['min_depth' => '4.5', 'specialist_result' => 'ACCEPTED']))->json('data.id'));
        $this->assertSame('RETREAD', $tire->fresh()->current_status);
        $cycle = $service->retread($tire->fresh(), $partner->id, null, null, null);
        $cycle = $service->finalInspectRetread($service->receiveRetread($cycle, (string) Str::uuid()), 'SAFE', null, null);
        $service->approveRetread($cycle, 'RETURN_TO_SERVICE', 'Retreaded', (string) Str::uuid());
        $this->assertSame('REMOVED', $tire->fresh()->current_status, 'not reusable until inspected again');
        $this->assertSame(0, $this->inventory($s)['reusable_qty']);

        $ctx = $this->getJson("/api/v1/app/tires/{$tire->id}/used-inspection/context", $s['headers'])->json('data.tire');
        $this->assertSame([1, null], [$ctx['retread_count'], $ctx['d_new_default_mm']], 'D_new after a retread is entered, not assumed');
        $after = $this->inspect($s, $tire, $this->answers(['min_depth' => '14', 'd_new_mm' => '15']))->json('data');
        $this->assertSame('REUSE', $after['recommendation']);
        $this->approve($s, $after['id'], ['warehouse_id' => $s['warehouse']->id]);
        $this->assertSame(['REUSE', 1], [$tire->fresh()->current_status, $this->inventory($s)['reusable_qty']]);

        // REPAIR → repair cycle → REMOVED → inspection (repair history auto-filled) → REUSE.
        $repaired = $this->removedTire($s, 'UI-RP');
        $repair = ['leak_foreign_object' => 'YES', 'repair_eligibility' => 'YES', 'damages' => [['location' => 'TREAD', 'damage_type' => 'PUNCTURE', 'diameter_mm' => 5, 'reaches_reinforcement' => 'NO', 'overlaps_previous_repair' => 'NO']]];
        $this->approve($s, $this->inspect($s, $repaired, $this->answers($repair))->json('data.id'));
        $this->assertSame('REPAIR', $repaired->fresh()->current_status);
        $rc = $service->repair($repaired->fresh(), $partner->id, null, null, null);
        $rc = $service->finalInspectRepair($service->receiveRepair($rc, (string) Str::uuid()), 'SAFE', null, null);
        $service->approveRepair($rc, 'RETURN_TO_SERVICE', 'Repaired', (string) Str::uuid());
        $this->assertSame('REMOVED', $repaired->fresh()->current_status);
        $history = $this->getJson("/api/v1/app/tires/{$repaired->id}/used-inspection/context", $s['headers'])->json('data.tire.repair_history');
        $this->assertCount(2, $history, 'the repair cycle and the REPAIR disposition');
        $final = $this->inspect($s, $repaired, $this->answers(['previous_repair' => 'MEETS_STANDARD']))->json('data');
        $this->assertSame('REUSE', $final['recommendation']);
    }

    private function profilePayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => 'Truck / Bus — line haul', 'tire_category' => 'TRUCK_BUS',
            'd_service_mm' => '3', 'd_pull_mm' => '5', 'a_max_months' => 120, 'a_retread_max_months' => 84, 'n_retread_max' => 2,
            'repair_limits' => [
                'allowed_locations' => ['TREAD', 'SHOULDER'], 'max_puncture_diameter_mm' => 10, 'max_cut_length_mm' => 25, 'max_cut_width_mm' => 5,
                'max_cut_depth_mm' => 8, 'max_repairs' => 2, 'allow_overlap_previous_repair' => false, 'allow_reinforcement_damage' => false,
            ],
        ], $overrides);
    }

    public function test_reasons_are_stored_machine_readable_next_to_the_unchanged_text(): void
    {
        $s = $this->scenario();
        $this->profile($s);
        $tire = $this->removedTire($s, 'UI-CODES');
        $hold = $this->inspect($s, $tire, $this->answers(['min_depth' => '4.5']))->json('data');

        // i18n (error / reason code decoupling): every reason has a {code, params} twin whose code is an
        // EN-ID dataset key, and rendering it gives back exactly the stored English text.
        $this->assertCount(count($hold['reasons']), $hold['reason_codes']);
        $this->assertContains('tire.reasons.retreadCandidateCasingAwaitingFinalRetreader', array_column($hold['reason_codes'], 'code'));
        $this->assertSame($hold['reasons'], array_map(\App\Domain\Shared\Support\Messages::render(...), $hold['reason_codes']));
        $stored = \App\Domain\Tire\Models\TireUsedInspection::query()->findOrFail($hold['id']);
        $this->assertSame($hold['reason_codes'], $stored->reason_codes);
        $this->assertSame($stored->follow_ups, array_map(\App\Domain\Shared\Support\Messages::render(...), $stored->follow_up_codes));
    }
}
