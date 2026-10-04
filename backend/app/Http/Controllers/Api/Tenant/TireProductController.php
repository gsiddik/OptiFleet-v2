<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\ProductMaster\Models\Product;
use App\Domain\Tire\Services\TireImportService;
use App\Domain\Tire\Services\TireInventoryService;
use App\Http\Controllers\Controller;
use App\Support\Spreadsheet\XlsxWriter;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tire Products: Products of Item Type TIRE (the same Product master as Products — no copy), with
 * the inventory of their physical tires. Tire List = this index; Tire Detail = show + inventory.
 */
class TireProductController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TireInventoryService $inventory,
    ) {}

    public function index(Request $request)
    {
        return $this->paginated($this->inventory->productList(
            $this->context->tenantId(), $this->context->user(),
            $request->string('search')->trim()->value() ?: null,
            min(max($request->integer('per_page', 20), 1), 100),
        ));
    }

    /** Product details (same payload as Product Detail) + inventory counts. */
    public function show(string $tireProduct)
    {
        $product = $this->tireProduct($tireProduct);

        return $this->ok($product->load(ProductController::detailRelations($product))->toArray() + [
            'inventory' => $this->inventory->summary($this->context->tenantId(), $this->context->user(), $product->id),
        ]);
    }

    /** One inventory table (NEW / INSTALLED / USED) of the product's physical tires, paginated. */
    public function inventory(Request $request, string $tireProduct)
    {
        $product = $this->tireProduct($tireProduct);
        $request->validate(['category' => ['required', Rule::in(TireInventoryService::CATEGORIES)]]);

        return $this->paginated($this->inventory->rows(
            $this->context->tenantId(), $this->context->user(), $product->id,
            $request->string('category')->value(),
            min(max($request->integer('per_page', 50), 1), 200),
        ));
    }

    /** New Stock import template: "How To" (instructions + examples) and an empty "Fill Here". */
    public function importTemplate(string $tireProduct, TireImportService $import)
    {
        $product = $this->tireProduct($tireProduct);
        $name = 'tire-import-'.Str::slug($product->code ?: $product->name).'.xlsx';

        return response($import->template($product), 200, [
            'Content-Type' => XlsxWriter::MIME,
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Reads an uploaded template and classifies every row; nothing is saved. */
    public function importPreview(Request $request, string $tireProduct, TireImportService $import)
    {
        $this->importableProduct($tireProduct);
        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'extensions:xlsx'],
        ], [
            'file.extensions' => 'Only Excel files (.xlsx) can be imported.',
        ]);

        return $this->ok($import->preview($this->context->tenantId(), $request->file('file')->getRealPath()));
    }

    /** Registers the selected preview rows as New Stock tires (each row validated again). */
    public function import(Request $request, string $tireProduct, TireImportService $import)
    {
        $product = $this->importableProduct($tireProduct);
        $validated = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:'.TireImportService::MAX_ROWS],
            'rows.*.row' => ['nullable', 'integer', 'min:1'],
            'rows.*.serial_number' => ['present', 'nullable', 'string'],
            'rows.*.manufacture_date_code' => ['nullable', 'string'],
            'rows.*.purchase_date' => ['nullable', 'string'],
        ]);

        return $this->ok($import->import($this->context->tenantId(), $product, $validated['rows']));
    }

    /** Tires can only be added to a live product that has its tire specification. */
    private function importableProduct(string $id): Product
    {
        $product = $this->tireProduct($id);
        if ($product->trashed()) {
            throw ValidationException::withMessages(['product' => 'This product was deleted; tires can no longer be added to it.']);
        }
        if (! $product->tireSpec()->exists()) {
            throw ValidationException::withMessages(['product' => 'This product has no tire specification yet — complete it with Edit Product first.']);
        }

        return $product;
    }

    /**
     * A Tire Product visible to the tenant (own or platform). Soft-deleted products stay readable
     * here so their physical tires and history remain reachable.
     */
    private function tireProduct(string $id): Product
    {
        $product = Product::query()->withTrashed()->find($id);
        abort_unless($product && $product->product_type === 'TIRE', 404);

        return $product;
    }
}
