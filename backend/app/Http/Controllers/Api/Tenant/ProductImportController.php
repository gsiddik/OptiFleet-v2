<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\ProductMaster\Imports\ProductImport;
use App\Domain\Shared\Support\Messages;
use App\Http\Controllers\Controller;
use App\Support\Spreadsheet\Import\ExcelImportEngine;
use App\Support\Spreadsheet\Import\ValidatesExcelUpload;
use App\Support\Spreadsheet\XlsxWriter;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Products → Import: one template per Item Type. The uploaded workbook identifies its Item Type (the
 * "How To" marker); when the user is in an Item Type context (item_type given) a template of another
 * Item Type is rejected.
 */
class ProductImportController extends Controller
{
    use ValidatesExcelUpload;

    public function __construct(private readonly TenantContext $context, private readonly ExcelImportEngine $engine) {}

    public function template(Request $request)
    {
        $itemType = $this->itemType($request, true);

        return response($this->engine->template($this->definition($itemType)), 200, [
            'Content-Type' => XlsxWriter::MIME,
            'Content-Disposition' => 'attachment; filename="product-import-'.Str::slug(Str::lower($itemType)).'.xlsx"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function preview(Request $request)
    {
        $this->validateFile($request);
        $requested = $this->itemType($request, false);
        $path = $request->file('file')->getRealPath();
        $found = ProductImport::itemTypeFromMarker($this->engine->markerOf($path));
        if ($found === null) {
            throw ValidationException::withMessages(['file' => Messages::localized('productImport.errors.notProductTemplate', ['expected' => $requested ?? '—'])]);
        }
        if ($requested !== null && $requested !== $found) {
            throw ValidationException::withMessages(['file' => Messages::localized('productImport.errors.wrongItemType', ['found' => $found, 'expected' => $requested])]);
        }

        return $this->ok(['item_type' => $found] + $this->engine->preview($this->definition($found), $path));
    }

    public function import(Request $request)
    {
        $itemType = $this->itemType($request, true);
        $validated = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:'.ExcelImportEngine::MAX_ROWS],
            'rows.*.row' => ['nullable', 'integer', 'min:1'],
            'rows.*.values' => ['required', 'array'],
        ]);

        return $this->ok($this->engine->import($this->definition($itemType), $validated['rows']));
    }

    private function itemType(Request $request, bool $required): ?string
    {
        $request->validate(['item_type' => [$required ? 'required' : 'nullable', Rule::in(ProductImport::ITEM_TYPES)]]);

        return $request->input('item_type') ?: null;
    }

    private function definition(string $itemType): ProductImport
    {
        return new ProductImport($this->context->tenantId(), $itemType);
    }
}
