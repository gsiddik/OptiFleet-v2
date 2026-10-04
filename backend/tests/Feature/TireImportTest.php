<?php

namespace Tests\Feature;

use App\Domain\ProductMaster\Models\Product;
use App\Domain\Tire\Models\ProductTireSpec;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireLoadIndex;
use App\Domain\Tire\Models\TireSpeedRating;
use App\Support\Spreadsheet\XlsxReader;
use App\Support\Spreadsheet\XlsxWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/**
 * New Stock Excel import: template download, upload validation (type, sheet, headers), preview
 * classification (valid / duplicate / invalid), and the three import outcomes.
 */
class TireImportTest extends TestCase
{
    private const HEADERS = ['Serial Number', 'Manufacture Date Code', 'Purchase Date'];

    private function scenario(array $permissions = ['tire.view', 'tire.manage']): array
    {
        $tenant = $this->makeTenant(['code' => 'TIM-'.Str::random(4)]);
        foreach (['VEHICLE', 'INVENTORY', 'TIRE'] as $module) {
            $this->grantModule($tenant, $module);
        }
        [, $token] = $this->makeTenantUser($tenant, $permissions);
        $product = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE', 'name' => 'Michelin X Multi', 'brand' => 'Michelin']);
        ProductTireSpec::query()->create([
            'product_id' => $product->id, 'vehicle_group' => 'TRUCK_BUS', 'pattern_name' => 'X Multi', 'width_mm' => 295,
            'aspect_ratio_percent' => 80, 'construction_type' => 'RADIAL', 'rim_diameter_inch' => 22.5, 'tire_type' => 'TUBELESS',
            'single_load_index_id' => TireLoadIndex::query()->firstOrCreate(['code' => '152'], ['max_load_single_kg' => 3550, 'status' => 'ACTIVE'])->id,
            'speed_rating_id' => TireSpeedRating::query()->firstOrCreate(['code' => 'M'], ['max_speed_kmh' => 130, 'status' => 'ACTIVE'])->id,
            'tire_size_computed' => '295/80 R22.5',
        ]);

        return [$tenant, $product, $this->authHeaders($token)];
    }

    /** A workbook as the user saves it: "Fill Here" with the given rows (header row included). */
    private function upload(array $rows, string $sheet = 'Fill Here', string $name = 'tires.xlsx'): UploadedFile
    {
        $bytes = (new XlsxWriter)->addSheet('How To', [['instructions']])->addSheet($sheet, $rows)->toString();
        $path = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, XlsxWriter::MIME, null, true);
    }

    private function preview(array $headers, Product $product, UploadedFile $file)
    {
        return $this->post("/api/v1/app/tire-products/{$product->id}/import/preview", ['file' => $file], $headers + ['Accept' => 'application/json']);
    }

    private function import(array $headers, Product $product, array $rows)
    {
        return $this->postJson("/api/v1/app/tire-products/{$product->id}/import", ['rows' => $rows], $headers);
    }

    public function test_template_download_is_an_xlsx_with_how_to_and_an_empty_fill_here_sheet(): void
    {
        [, $product, $headers] = $this->scenario();

        $response = $this->get("/api/v1/app/tire-products/{$product->id}/import-template", $headers)->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('attachment; filename="tire-import-', $response->headers->get('Content-Disposition'));

        $path = tempnam(sys_get_temp_dir(), 'tpl');
        file_put_contents($path, $response->getContent());
        $reader = XlsxReader::open($path);
        $this->assertSame(['How To', 'Fill Here'], $reader->sheetNames());

        $fill = $reader->rows('Fill Here');
        $this->assertSame([1], array_keys($fill), 'Fill Here holds only the header row');
        $this->assertSame(self::HEADERS, array_column($fill[1], 'value'));

        $howTo = array_values($reader->rows('How To'));
        $headerAt = collect($howTo)->search(fn ($r) => array_column($r, 'value') === self::HEADERS);
        $this->assertNotFalse($headerAt, 'How To shows the example header');
        $this->assertGreaterThanOrEqual(10, count($howTo) - $headerAt - 1, 'at least 10 examples');
        $this->assertStringContainsString('Michelin X Multi', $howTo[0][0]['value']);
    }

    public function test_preview_reads_shared_strings_excel_dates_and_numeric_serials(): void
    {
        [, $product, $headers] = $this->scenario();
        // Written the way Excel saves it: shared strings, a numeric serial, a real date cell (serial 46204 = 2026-07-01).
        $path = tempnam(sys_get_temp_dir(), 'xl');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook '.$ns.' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Fill Here" sheetId="1" r:id="rId7"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId7" Type="worksheet" Target="/xl/worksheets/data.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst '.$ns.'><si><t>Serial Number</t></si><si><r><t>Manufacture </t></r><r><t>Date Code</t></r></si><si><t>Purchase Date</t></si><si><t> abc-001 </t></si><si><t>2326</t></si></sst>');
        $zip->addFromString('xl/worksheets/data.xml', '<?xml version="1.0"?><worksheet '.$ns.'><sheetData>'
            .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c></row>'
            .'<row r="2"><c r="A2" t="s"><v>3</v></c><c r="B2" t="s"><v>4</v></c><c r="C2" s="3"><v>46204</v></c></row>'
            .'<row r="4"><c r="A4"><v>778899</v></c><c r="C4" t="inlineStr"><is><t>2026-02-28</t></is></c></row>'
            .'</sheetData></worksheet>');
        $zip->close();

        $response = $this->preview($headers, $product, new UploadedFile($path, 'excel.xlsx', XlsxWriter::MIME, null, true))->assertOk();
        $this->assertSame(['total' => 2, 'valid' => 2, 'duplicate' => 0, 'invalid' => 0], $response->json('data.summary'));
        $this->assertSame(
            [[2, 'abc-001', '2326', '2026-07-01', 'VALID'], [4, '778899', null, '2026-02-28', 'VALID']],
            collect($response->json('data.rows'))->map(fn ($r) => [$r['row'], $r['serial_number'], $r['manufacture_date_code'], $r['purchase_date'], $r['status']])->all()
        );
        $this->assertSame(0, Tire::query()->count(), 'preview saves nothing');
    }

    public function test_upload_rejects_a_file_that_is_not_xlsx(): void
    {
        [, $product, $headers] = $this->scenario();

        $this->preview($headers, $product, UploadedFile::fake()->createWithContent('tires.csv', "Serial Number\nX1"))
            ->assertStatus(422)->assertJsonPath('errors.file.0', 'Only Excel files (.xlsx) can be imported.');
        $this->preview($headers, $product, UploadedFile::fake()->createWithContent('renamed.xlsx', 'not a zip at all'))
            ->assertStatus(422)->assertJsonPath('errors.file.0', 'The file is not a valid Excel (.xlsx) workbook.');
        $this->preview($headers, $product, UploadedFile::fake()->create('missing.xlsx', 0))->assertStatus(422);
    }

    public function test_upload_rejects_a_workbook_with_a_doctype_entity_declaration(): void
    {
        [, $product, $headers] = $this->scenario();
        $path = tempnam(sys_get_temp_dir(), 'xxe');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><!DOCTYPE w [<!ENTITY x SYSTEM "file:///etc/passwd">]><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheets/></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
        $zip->close();

        $this->preview($headers, $product, new UploadedFile($path, 'xxe.xlsx', XlsxWriter::MIME, null, true))
            ->assertStatus(422)->assertJsonPath('errors.file.0', 'The file is not a valid Excel (.xlsx) workbook.');
    }

    public function test_upload_requires_the_fill_here_sheet(): void
    {
        [, $product, $headers] = $this->scenario();

        $this->preview($headers, $product, $this->upload([self::HEADERS, ['X1', '', '']], 'Sheet1'))
            ->assertStatus(422)->assertJsonPath('errors.file.0', 'The workbook has no sheet named "Fill Here". Download the template and fill that sheet.');
    }

    public function test_upload_requires_the_exact_header_row(): void
    {
        [, $product, $headers] = $this->scenario();
        $message = 'The header row of "Fill Here" must be exactly: Serial Number, Manufacture Date Code, Purchase Date.';

        foreach ([
            ['Serial', 'Manufacture Date Code', 'Purchase Date'],
            ['Serial Number', 'Purchase Date', 'Manufacture Date Code'],
            ['Serial Number', 'Manufacture Date Code', 'Purchase Date', 'Notes'],
            ['Serial Number', 'Manufacture Date Code'],
        ] as $header) {
            $this->preview($headers, $product, $this->upload([$header, ['X1', '', '']]))->assertStatus(422)->assertJsonPath('errors.file.0', $message);
        }
    }

    public function test_upload_without_tire_rows_is_rejected(): void
    {
        [, $product, $headers] = $this->scenario();

        $this->preview($headers, $product, $this->upload([self::HEADERS]))
            ->assertStatus(422)->assertJsonPath('errors.file.0', 'The sheet "Fill Here" has no tire rows. Fill one tire per row below the header.');
    }

    public function test_preview_flags_duplicates_against_the_tenant_and_within_the_file_and_invalid_rows(): void
    {
        [$tenant, $product, $headers] = $this->scenario();
        Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'EXIST-01', 'current_status' => 'IN_STOCK']);
        // Same serial in another tenant does not count.
        $other = $this->makeTenant(['code' => 'TIO-'.Str::random(4)]);
        Tire::query()->create(['tenant_id' => $other->id, 'product_id' => $product->id, 'serial_number' => 'OTHER-01', 'current_status' => 'IN_STOCK']);

        $rows = $this->preview($headers, $product, $this->upload([
            self::HEADERS,
            ['NEW-01', '2326', '2026-07-01'],
            [' exist-01 ', '', ''],
            ['new-01', '', ''],
            ['OTHER-01', '', ''],
            ['', '2326', ''],
            ['BAD-DATE', '', '01/07/2026'],
            ['LONG-CODE', str_repeat('9', 21), ''],
            ['NO-SUCH-DAY', '', '2026-02-30'],
        ]))->assertOk()->json('data');

        $this->assertSame(['total' => 8, 'valid' => 2, 'duplicate' => 2, 'invalid' => 4], $rows['summary']);
        $byRow = collect($rows['rows'])->keyBy('row');
        $this->assertSame('VALID', $byRow[2]['status']);
        $this->assertSame(['DUPLICATE', ['This serial number is already registered.']], [$byRow[3]['status'], $byRow[3]['errors']]);
        $this->assertSame(['DUPLICATE', ['This serial number is repeated in the file (row 2).']], [$byRow[4]['status'], $byRow[4]['errors']]);
        $this->assertSame('VALID', $byRow[5]['status']);
        $this->assertSame(['Serial Number is required.'], $byRow[6]['errors']);
        $this->assertSame(['Purchase Date must be a date in YYYY-MM-DD format.'], $byRow[7]['errors']);
        $this->assertSame('01/07/2026', $byRow[7]['purchase_date'], 'the unparseable value is kept for the import to reject again');

        // Importing the preview rows as returned saves only the valid ones.
        $result = $this->import($headers, $product, collect($rows['rows'])->map(fn ($r) => array_intersect_key($r, array_flip(['row', 'serial_number', 'manufacture_date_code', 'purchase_date'])))->all())->assertOk()->json('data');
        $this->assertSame(['PARTIAL', 2, [3, 4, 6, 7, 8, 9]], [$result['status'], $result['imported'], array_column($result['failed'], 'row')]);
        $this->assertSame(['Manufacture Date Code may not be longer than 20 characters.'], $byRow[8]['errors']);
        $this->assertSame('INVALID', $byRow[9]['status']);
    }

    public function test_import_all_success_registers_new_stock_tires_with_the_product_specification(): void
    {
        [$tenant, $product, $headers] = $this->scenario();

        $result = $this->import($headers, $product, [
            ['row' => 2, 'serial_number' => 'IMP-0001', 'manufacture_date_code' => '2326', 'purchase_date' => '2026-07-01'],
            ['row' => 3, 'serial_number' => ' IMP-0002 ', 'manufacture_date_code' => null, 'purchase_date' => null],
        ])->assertOk()->json('data');

        $this->assertSame(['SUCCESS', 2, []], [$result['status'], $result['imported'], $result['failed']]);
        $tire = Tire::query()->where('tenant_id', $tenant->id)->where('serial_number', 'IMP-0001')->firstOrFail();
        $this->assertSame(['IN_STOCK', $product->id, '2326', '2026-07-01', '295/80 R22.5', 152], [$tire->current_status, $tire->product_id, $tire->manufacture_date_code, $tire->purchase_date->toDateString(), $tire->tire_size, $tire->load_index]);
        $this->assertTrue(Tire::query()->where('serial_number', 'IMP-0002')->exists(), 'serial stored trimmed');

        $new = $this->getJson("/api/v1/app/tire-products/{$product->id}/inventory?category=NEW", $headers)->assertOk()->json('data');
        $row = collect($new)->firstWhere('serial_number', 'IMP-0001');
        $this->assertSame(['2326', '2026-07-01'], [$row['manufacture_date_code'], substr($row['purchase_date'], 0, 10)]);
    }

    public function test_import_partial_saves_valid_rows_and_reports_the_failed_ones(): void
    {
        [$tenant, $product, $headers] = $this->scenario();
        Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'DUP-1', 'current_status' => 'IN_STOCK']);

        $result = $this->import($headers, $product, [
            ['row' => 2, 'serial_number' => 'OK-1', 'manufacture_date_code' => null, 'purchase_date' => null],
            ['row' => 3, 'serial_number' => 'dup-1', 'manufacture_date_code' => null, 'purchase_date' => null],
            ['row' => 4, 'serial_number' => 'OK-2', 'manufacture_date_code' => null, 'purchase_date' => '2026-13-01'],
        ])->assertOk()->json('data');

        $this->assertSame(['PARTIAL', 1], [$result['status'], $result['imported']]);
        $this->assertSame([[3, 'DUPLICATE'], [4, 'INVALID']], collect($result['failed'])->map(fn ($f) => [$f['row'], $f['status']])->all());
        $this->assertSame(['DUP-1', 'OK-1'], Tire::query()->where('tenant_id', $tenant->id)->orderBy('serial_number')->pluck('serial_number')->all());
    }

    public function test_import_where_every_row_is_duplicate_saves_nothing(): void
    {
        [$tenant, $product, $headers] = $this->scenario();
        foreach (['A-1', 'A-2'] as $serial) {
            Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => $serial, 'current_status' => 'IN_STOCK']);
        }

        $result = $this->import($headers, $product, [
            ['row' => 2, 'serial_number' => 'a-1', 'manufacture_date_code' => null, 'purchase_date' => null],
            ['row' => 3, 'serial_number' => 'A-2', 'manufacture_date_code' => null, 'purchase_date' => null],
            ['row' => 4, 'serial_number' => 'A-2', 'manufacture_date_code' => null, 'purchase_date' => null],
        ])->assertOk()->json('data');

        $this->assertSame(['FAILED', 0, 3], [$result['status'], $result['imported'], count($result['failed'])]);
        $this->assertSame(2, Tire::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_import_requires_tire_manage_and_a_visible_live_product_with_specification(): void
    {
        [, $product, $headers] = $this->scenario(['tire.view']);
        $this->get("/api/v1/app/tire-products/{$product->id}/import-template", $headers + ['Accept' => 'application/json'])->assertForbidden();
        $this->preview($headers, $product, $this->upload([self::HEADERS, ['X1', '', '']]))->assertForbidden();
        $this->import($headers, $product, [['serial_number' => 'X1']])->assertForbidden();

        [, , $otherHeaders] = $this->scenario();
        $this->import($otherHeaders, $product, [['serial_number' => 'X1']])->assertNotFound();

        [$tenant, $ownProduct, $headers] = $this->scenario();
        $noSpec = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE', 'name' => 'No Spec Tire']);
        $this->import($headers, $noSpec, [['serial_number' => 'X1']])->assertStatus(422)->assertJsonValidationErrors('product');
        $ownProduct->delete();
        $this->import($headers, $ownProduct, [['serial_number' => 'X1']])->assertStatus(422)->assertJsonValidationErrors('product');
        $this->assertSame(0, Tire::query()->count());
    }
}
