<?php

namespace Tests\Feature;

use App\Domain\Tire\Models\ProductTireSpec;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireCyclePhoto;
use App\Domain\Tire\Models\TireLoadIndex;
use App\Domain\Tire\Models\TireRetread;
use App\Domain\Tire\Models\TireSpeedRating;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Used Tire Management → Retread: Open Cycle (vendor, estimated price, 1–3 photos, notes) →
 * Receive → Tire Inspection → completed cycle in "Recent Retread / Repair Cycles" and the tire's
 * Retread History.
 */
class TireRetreadProcessingTest extends TestCase
{
    private const PERMISSIONS = [
        'tire.view', 'tire.manage', 'tire.install', 'tire.remove', 'tire.inspect', 'tire_used_inspection.approve', 'tire_rule_profile.manage',
        'tire_retread.send', 'tire_retread.receive', 'tire_retread.inspect', 'tire_repair.send', 'tire_repair.receive',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function scenario(array $permissions = self::PERMISSIONS): array
    {
        $tenant = $this->makeTenant(['code' => 'TRP-'.Str::random(4), 'timezone' => 'Asia/Jakarta']);
        foreach (['VEHICLE', 'INVENTORY', 'TIRE', 'PARTNER'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $warehouse = $this->makeWarehouse($tenant, $branch, $this->makeWorkshop($tenant, $branch));
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory(), ['registration_number' => 'B 77 TRP']);
        [, $token] = $this->makeTenantUser($tenant, $permissions);
        $headers = $this->authHeaders($token);
        $product = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE', 'name' => 'Retread Casing', 'reference_tread_depth_mm' => '16.00']);
        ProductTireSpec::query()->create([
            'product_id' => $product->id, 'vehicle_group' => 'TRUCK_BUS', 'pattern_name' => 'R150', 'width_mm' => 295, 'aspect_ratio_percent' => 80,
            'construction_type' => 'RADIAL', 'rim_diameter_inch' => 22.5, 'tire_type' => 'TUBELESS', 'tire_size_computed' => '295/80 R22.5',
            'single_load_index_id' => TireLoadIndex::query()->firstOrCreate(['code' => '152'], ['max_load_single_kg' => 3550, 'status' => 'ACTIVE'])->id,
            'speed_rating_id' => TireSpeedRating::query()->firstOrCreate(['code' => 'M'], ['max_speed_kmh' => 130, 'status' => 'ACTIVE'])->id,
        ]);
        $vendor = $this->makePartner($tenant, ['name' => 'PT Vulkanisir', 'partner_type' => 'TIRE_SUPPLIER', 'status' => 'ACTIVE']);
        $this->postJson('/api/v1/app/tire-rule-profiles', [
            'name' => 'Truck / Bus', 'tire_category' => 'TRUCK_BUS', 'd_service_mm' => '3', 'd_pull_mm' => '5', 'a_max_months' => 120, 'a_retread_max_months' => 84, 'n_retread_max' => 2,
            'repair_limits' => ['allowed_locations' => ['TREAD'], 'max_puncture_diameter_mm' => 10, 'max_cut_length_mm' => 25, 'max_cut_width_mm' => 5, 'max_cut_depth_mm' => 8, 'max_repairs' => 2, 'allow_overlap_previous_repair' => false, 'allow_reinforcement_damage' => false],
            'application_limits' => [],
        ], $headers)->assertStatus(201);

        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'RTD-1', 'manufacture_date_code' => '2324', 'current_status' => 'IN_STOCK']);
        $this->postJson("/api/v1/app/tires/{$tire->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => 'RL', 'odometer' => 1000], $headers)->assertStatus(201);
        $this->postJson("/api/v1/app/tires/{$tire->id}/remove", ['removal_reason' => 'Worn tread', 'disposition' => 'RETREAD', 'odometer' => 9000], $headers)->assertSuccessful();
        $this->assertSame('RETREAD', $tire->fresh()->current_status);

        return compact('tenant', 'warehouse', 'vendor', 'tire', 'headers');
    }

    private function photo(string $name = 'casing.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 640, 480);
    }

    private function open(array $s, array $overrides = [])
    {
        return $this->post("/api/v1/app/tires/{$s['tire']->id}/cycles", array_merge([
            'partner_id' => $s['vendor']->id, 'estimated_price' => '850000.00', 'notes' => 'Bead area check', 'photos' => [$this->photo('a.jpg'), $this->photo('b.jpg')],
        ], $overrides), $s['headers'] + ['Accept' => 'application/json']);
    }

    private function inspection(array $s): string
    {
        $points = [];
        foreach ([1, 2, 3] as $zone) {
            foreach (['INNER_MAIN', 'OUTER_MAIN'] as $groove) {
                $points[] = ['zone' => $zone, 'groove' => $groove, 'depth_mm' => '12.0'];
            }
        }

        return $this->postJson("/api/v1/app/tires/{$s['tire']->id}/used-inspections", [
            'identity_status' => 'COMPLETE', 'internal_inspected' => 'YES', 'wear_pattern' => 'EVEN', 'bulge_separation' => 'NONE', 'cord_exposure' => 'NONE',
            'sidewall_condition' => 'NORMAL', 'bead_condition' => 'NORMAL', 'inner_liner_condition' => 'NORMAL', 'run_flat_overheat' => 'NO', 'leak_foreign_object' => 'NO',
            'previous_repair' => 'NONE', 'age_chemical' => 'NONE', 'casing_compliance' => 'MEETS', 'measurements' => $points,
        ], $s['headers'])->assertStatus(201)->json('data.id');
    }

    private function openList(array $s)
    {
        return collect($this->getJson('/api/v1/app/tire-cycles', $s['headers'])->assertOk()->json('data'))->keyBy('tire.serial_number');
    }

    private function completedFeed(array $s)
    {
        return $this->getJson('/api/v1/app/tire-activity?type[]=RETREAD&type[]=REPAIR&completed_cycles=1', $s['headers'])->assertOk()->json('data');
    }

    public function test_open_cycle_validates_vendor_price_and_photos(): void
    {
        $s = $this->scenario();
        $this->open($s, ['estimated_price' => ''])->assertStatus(422)->assertJsonValidationErrors('estimated_price');
        $this->open($s, ['estimated_price' => '0'])->assertStatus(422)->assertJsonValidationErrors('estimated_price');
        $this->open($s, ['photos' => []])->assertStatus(422)->assertJsonValidationErrors('photos');
        $this->open($s, ['photos' => [$this->photo('1.jpg'), $this->photo('2.jpg'), $this->photo('3.jpg'), $this->photo('4.jpg')]])->assertStatus(422)->assertJsonValidationErrors('photos');
        $this->open($s, ['photos' => [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')]])->assertStatus(422);
        $this->open($s, ['partner_id' => (string) Str::uuid()])->assertStatus(422);
        $inactive = $this->makePartner($s['tenant'], ['name' => 'Closed vendor', 'partner_type' => 'TIRE_SUPPLIER', 'status' => 'INACTIVE']);
        $this->open($s, ['partner_id' => $inactive->id])->assertStatus(422);
        $this->assertSame(0, TireRetread::query()->count());
        $this->assertSame(0, TireCyclePhoto::query()->count());

        [, $viewerToken] = $this->makeTenantUser($s['tenant'], ['tire.view']);
        $this->app['auth']->forgetGuards();
        $this->post("/api/v1/app/tires/{$s['tire']->id}/cycles", ['partner_id' => $s['vendor']->id, 'estimated_price' => '1', 'photos' => [$this->photo()]], $this->authHeaders($viewerToken) + ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_cycle_completes_only_after_receive_and_the_tire_inspection(): void
    {
        $s = $this->scenario();
        $cycle = $this->open($s)->assertStatus(201)->json('data');
        $this->assertSame(['IN_PROCESS', 'PT Vulkanisir', '850000.0000', 2], [$cycle['state'], $cycle['vendor']['name'], $cycle['estimated_price'], count($cycle['photos'])]);
        $this->assertSame('RETREAD', $s['tire']->fresh()->current_status, 'stays in Tires in Retread / Repair Cycle');
        $this->assertSame('IN_PROCESS', $this->openList($s)['RTD-1']['cycle']['state']);
        $this->open($s)->assertStatus(422); // one open cycle at a time
        // Not inspectable before Receive.
        $this->assertFalse($this->getJson("/api/v1/app/tires/{$s['tire']->id}/used-inspection/context", $s['headers'])->json('data.can_inspect'));

        $this->postJson("/api/v1/app/tire-cycles/retread/{$cycle['id']}/receive", [], $s['headers'])->assertOk()->assertJsonPath('data.state', 'RECEIVED');
        $this->postJson("/api/v1/app/tire-cycles/retread/{$cycle['id']}/receive", [], $s['headers'])->assertStatus(422);
        $this->assertSame('RETREAD', $s['tire']->fresh()->current_status);
        $this->assertSame([], $this->completedFeed($s));
        $this->assertTrue($this->getJson("/api/v1/app/tires/{$s['tire']->id}/used-inspection/context", $s['headers'])->json('data.can_inspect'));

        // Tire Inspection submitted → re-inspection; cancelled → back to received.
        $first = $this->inspection($s);
        $this->assertSame('REINSPECTION', $this->openList($s)['RTD-1']['cycle']['state']);
        $this->postJson("/api/v1/app/tire-used-inspections/{$first}/cancel", [], $s['headers'])->assertOk();
        $this->assertSame('RECEIVED', $this->openList($s)['RTD-1']['cycle']['state']);
        // The legacy final inspection cannot close a cycle opened here.
        $this->postJson("/api/v1/app/tires/{$s['tire']->id}/retreads/{$cycle['id']}/final-inspect", ['result' => 'SAFE'], $s['headers'])->assertStatus(422);

        $second = $this->inspection($s);
        $this->postJson("/api/v1/app/tire-used-inspections/{$second}/approve", ['warehouse_id' => $s['warehouse']->id], $s['headers'])->assertOk();
        $this->assertSame('REUSE', $s['tire']->fresh()->current_status);
        $this->assertFalse($this->openList($s)->has('RTD-1'), 'left Tires in Retread / Repair Cycle');
        $feed = $this->completedFeed($s);
        $this->assertCount(1, $feed);
        $this->assertSame(['RETREAD', 'REUSE'], [$feed[0]['type'], $feed[0]['status']]);

        $history = $this->getJson("/api/v1/app/tires/{$s['tire']->id}/cycle-history", $s['headers'])->assertOk()->json('data');
        $this->assertCount(1, $history);
        $row = $history[0];
        $this->assertSame(['RETREAD', 'COMPLETED', 'PT Vulkanisir', '850000.0000', 'Bead area check', 'REUSE', 'REUSE'], [$row['kind'], $row['state'], $row['vendor']['name'], $row['estimated_price'], $row['notes'], $row['inspection_result'], $row['final_status']]);
        $this->assertNotNull($row['sent_at']);
        $this->assertNotNull($row['received_at']);
        $this->assertCount(2, $row['photos']);
        $this->get("/api/v1/app/tire-cycles/retread/{$row['id']}/photos/{$row['photos'][0]['id']}", $s['headers'])->assertOk();
    }

    public function test_a_retread_recommendation_after_inspection_starts_a_new_cycle(): void
    {
        $s = $this->scenario();
        $cycle = $this->open($s)->json('data');
        $this->postJson("/api/v1/app/tire-cycles/retread/{$cycle['id']}/receive", [], $s['headers'])->assertOk();
        $id = $this->inspection($s);
        $this->postJson("/api/v1/app/tire-used-inspections/{$id}/approve", ['warehouse_id' => $s['warehouse']->id], $s['headers'])->assertOk();
        // The tire left the cycle; a tire that is neither RETREAD nor REPAIR cannot open one.
        $this->open($s)->assertStatus(422);
    }
}
