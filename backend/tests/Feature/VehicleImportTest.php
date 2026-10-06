<?php

namespace Tests\Feature;

use App\Domain\MasterData\Models\VehicleBrand;
use App\Domain\MasterData\Models\VehicleModel;
use App\Domain\Vehicle\Models\Vehicle;
use App\Support\Spreadsheet\XlsxReader;
use App\Support\Spreadsheet\XlsxWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Vehicle Excel template + bulk import: exact How To / Fill Here sheets, the New Vehicle form's mandatory
 * fields, code-based master references, the same validation as Create Vehicle, branch scope, preview
 * classification and row-level isolation on import.
 */
class VehicleImportTest extends TestCase
{
    private const HEADERS = ['Branch Code', 'Vehicle Category Code', 'Brand Code', 'Model Code', 'Registration Number', 'VIN', 'Current Odometer (km)', 'Purchase Month', 'Purchase Year'];

    private function scenario(?array $scopes = null): array
    {
        $tenant = $this->makeTenant(['code' => 'VIM-'.Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $branch = $this->makeBranch($tenant, ['code' => 'BR-JKT']);
        $other = $this->makeBranch($tenant, ['code' => 'BR-SBY']);
        $category = $this->makeVehicleCategory(['code' => 'VC-T'.Str::upper(Str::random(3))]);
        $brand = VehicleBrand::query()->create(['tenant_id' => $tenant->id, 'code' => 'HINO', 'name' => 'Hino', 'is_system' => false, 'status' => 'ACTIVE']);
        $isuzu = VehicleBrand::query()->create(['tenant_id' => $tenant->id, 'code' => 'ISUZU', 'name' => 'Isuzu', 'is_system' => false, 'status' => 'ACTIVE']);
        VehicleModel::query()->create(['tenant_id' => $tenant->id, 'vehicle_brand_id' => $brand->id, 'code' => 'H500', 'name' => 'Hino 500', 'is_system' => false, 'status' => 'ACTIVE']);
        VehicleModel::query()->create(['tenant_id' => $tenant->id, 'vehicle_brand_id' => $isuzu->id, 'code' => 'ELF', 'name' => 'Elf', 'is_system' => false, 'status' => 'ACTIVE']);
        [$user, $token] = $this->makeTenantUser($tenant, ['vehicle.view', 'vehicle.create'], $scopes === null ? null : ['BRANCH' => $branch->id]);
        $this->makeVehicle($tenant, $branch, $category, ['registration_number' => 'B 1000 OLD', 'vin' => 'VIN-OLD']);

        return [$tenant, $branch, $other, $category, $this->authHeaders($token), $user];
    }

    private function upload(array $rows): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('vehicles.xlsx', (new XlsxWriter)->addSheet('How To', [['x']])->addSheet('Fill Here', $rows)->toString());
    }

    private function row(string $category, array $overrides = []): array
    {
        return array_replace(['BR-JKT', $category, 'HINO', 'H500', 'B 9001 TXA', '', '125000', '7', '2024'], $overrides);
    }

    public function test_template_has_exact_sheets_form_fields_and_valid_codes(): void
    {
        [, , , $category, $headers] = $this->scenario();
        $response = $this->get('/api/v1/app/vehicles/import-template', $headers)->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'veh');
        file_put_contents($path, $response->getContent());
        $reader = XlsxReader::open($path);
        $this->assertSame(['How To', 'Fill Here'], $reader->sheetNames());
        $this->assertSame(self::HEADERS, array_column($reader->rows('Fill Here')[1], 'value'));
        $howTo = implode("\n", array_map(fn ($r) => implode(' | ', array_column($r, 'value')), $reader->rows('How To')));
        // Mandatory = the New Vehicle required fields; optional = the rest of the form.
        foreach (['Branch Code | Required', 'Vehicle Category Code | Required', 'Brand Code | Required', 'Model Code | Required', 'Registration Number | Required', 'VIN | Optional', 'Purchase Year | Optional'] as $line) {
            $this->assertStringContainsString($line, $howTo);
        }
        $this->assertStringContainsString('BR-JKT (Test Branch)', $howTo);
        $this->assertStringContainsString($category->code, $howTo);
        $this->assertStringContainsString('Brand HINO — Hino — Model codes: H500 (Hino 500)', $howTo);
        unlink($path);
    }

    public function test_preview_classifies_rows_with_the_create_vehicle_rules(): void
    {
        [, , , $category, $headers] = $this->scenario();
        $c = $category->code;
        $file = $this->upload([
            self::HEADERS,
            $this->row($c),                                                    // 2 VALID
            $this->row($c, [4 => 'b9001txa']),                                 // 3 DUPLICATE: repeated plate (spaces/case ignored)
            $this->row($c, [4 => 'B 1000 OLD']),                               // 4 DUPLICATE: already registered
            $this->row($c, [0 => 'BR-NOPE', 4 => 'B 9004 TXA']),               // 5 INVALID: unknown branch
            $this->row($c, [1 => 'VC-NOPE', 4 => 'B 9005 TXA']),               // 6 INVALID: unknown category
            $this->row($c, [3 => 'ELF', 4 => 'B 9006 TXA']),                   // 7 INVALID: model of another brand
            $this->row($c, [4 => '', 5 => 'X']),                               // 8 INVALID: missing registration
            $this->row($c, [4 => 'B 9008 TXA', 6 => 'abc']),                   // 9 INVALID: odometer not a number
            $this->row($c, [4 => 'B 9009 TXA', 7 => '13']),                    // 10 INVALID: month out of range (StoreVehicleRequest)
            $this->row($c, [4 => 'B 9010 TXA', 5 => 'VIN-OLD']),               // 11 INVALID: VIN already used (StoreVehicleRequest)
            $this->row($c, [4 => 'B 9011 TXA', 8 => '2026.5']),                // 12 INVALID: year not a whole number
            $this->row($c, [4 => 'B 9012 TXA', 2 => 'isuzu', 3 => 'elf']),     // 13 VALID (codes case-insensitive)
        ]);
        $preview = $this->post('/api/v1/app/vehicles/import/preview', ['file' => $file], $headers)->assertOk()->json('data');
        $this->assertSame(['VALID', 'DUPLICATE', 'DUPLICATE', 'INVALID', 'INVALID', 'INVALID', 'INVALID', 'INVALID', 'INVALID', 'INVALID', 'INVALID', 'VALID'], array_column($preview['rows'], 'status'));
        $this->assertStringContainsString('Model ELF is not a model of brand HINO', implode(' ', $preview['rows'][5]['errors']));
        $this->assertStringContainsString('Registration Number is required', implode(' ', $preview['rows'][6]['errors']));

        $result = $this->postJson('/api/v1/app/vehicles/import', ['rows' => array_map(fn ($r) => ['row' => $r['row'], 'values' => $r['values']], $preview['rows'])], $headers)->assertOk()->json('data');
        $this->assertSame(['PARTIAL', 2, 10], [$result['status'], $result['imported'], count($result['failed'])]);
        $created = Vehicle::query()->where('registration_number', 'B 9001 TXA')->sole();
        $this->assertSame(['Hino', 'Hino 500', 'ACTIVE', 'AVAILABLE', '125000.00', 7, 2024], [$created->brand, $created->model, $created->status, $created->operational_status, (string) $created->current_odometer, (int) $created->purchase_month, (int) $created->purchase_year]);
        $this->assertSame('Isuzu', Vehicle::query()->where('registration_number', 'B 9012 TXA')->sole()->brand);
    }

    public function test_branch_scope_and_permission_are_enforced(): void
    {
        [$tenant, , , $category, $headers] = $this->scenario(['BRANCH']);
        $file = $this->upload([self::HEADERS, $this->row($category->code), $this->row($category->code, [0 => 'BR-SBY', 4 => 'B 7777 SBY'])]);
        $rows = $this->post('/api/v1/app/vehicles/import/preview', ['file' => $file], $headers)->assertOk()->json('data.rows');
        $this->assertSame(['VALID', 'INVALID'], array_column($rows, 'status'));
        // A crafted import request is validated again on the server.
        $result = $this->postJson('/api/v1/app/vehicles/import', ['rows' => [['row' => 3, 'values' => $rows[1]['values']]]], $headers)->assertOk()->json('data');
        $this->assertSame(['FAILED', 0], [$result['status'], $result['imported']]);
        $this->assertFalse(Vehicle::query()->where('registration_number', 'B 7777 SBY')->exists());

        [, $viewerToken] = $this->makeTenantUser($tenant, ['vehicle.view']);
        $this->app['auth']->forgetGuards();
        $this->get('/api/v1/app/vehicles/import-template', $this->authHeaders($viewerToken))->assertForbidden();
    }

    public function test_new_vehicle_form_keeps_working_through_the_shared_creation_service(): void
    {
        [, $branch, , $category, $headers] = $this->scenario();
        $brand = VehicleBrand::query()->where('code', 'HINO')->first();
        $model = VehicleModel::query()->where('code', 'H500')->first();
        $this->postJson('/api/v1/app/vehicles', ['branch_id' => $branch->id, 'vehicle_category_id' => $category->id, 'vehicle_brand_id' => $brand->id, 'vehicle_model_id' => $model->id, 'registration_number' => 'B 5555 NEW'], $headers)
            ->assertStatus(201)->assertJsonPath('data.brand', 'Hino')->assertJsonPath('data.model', 'Hino 500');
    }
}
