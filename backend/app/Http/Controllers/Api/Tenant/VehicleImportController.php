<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Vehicle\Imports\VehicleImport;
use App\Http\Controllers\Controller;
use App\Support\Spreadsheet\Import\ExcelImportEngine;
use App\Support\Spreadsheet\Import\ValidatesExcelUpload;
use App\Support\Spreadsheet\XlsxWriter;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/** Vehicles → Download Template / Import (shared Excel import engine; vehicle.create permission). */
class VehicleImportController extends Controller
{
    use ValidatesExcelUpload;

    public function __construct(private readonly TenantContext $context, private readonly ExcelImportEngine $engine) {}

    public function template()
    {
        return response($this->engine->template($this->definition()), 200, [
            'Content-Type' => XlsxWriter::MIME,
            'Content-Disposition' => 'attachment; filename="vehicle-import-template.xlsx"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function preview(Request $request)
    {
        $this->validateFile($request);

        return $this->ok($this->engine->preview($this->definition(), $request->file('file')->getRealPath()));
    }

    public function import(Request $request)
    {
        $validated = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:'.ExcelImportEngine::MAX_ROWS],
            'rows.*.row' => ['nullable', 'integer', 'min:1'],
            'rows.*.values' => ['required', 'array'],
        ]);

        return $this->ok($this->engine->import($this->definition(), $validated['rows']));
    }

    private function definition(): VehicleImport
    {
        return new VehicleImport($this->context->tenantId(), $this->context->user());
    }
}
