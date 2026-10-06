<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\Shared\Support\Messages;
use App\Domain\Tire\Imports\RimSerialImport;
use App\Domain\Tire\Services\RimInventoryService;
use App\Domain\Tire\Services\RimRegistrationService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Http\Controllers\Controller;
use App\Support\Spreadsheet\Import\ExcelImportEngine;
use App\Support\Spreadsheet\Import\ValidatesExcelUpload;
use App\Support\Spreadsheet\XlsxWriter;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Tire Management → Rim: Rim Products (Products of Item Type RIM — the same Product master, no copy)
 * and their physical, serial-numbered rims (Component Assets): Rim List = index; Rim Detail = show +
 * inventory; Register Rim (New Stock or directly Installed); Serial Number import per mode.
 */
class RimProductController extends Controller
{
    use ValidatesExcelUpload;

    public function __construct(
        private readonly TenantContext $context,
        private readonly RimInventoryService $inventory,
        private readonly RimRegistrationService $registration,
        private readonly ExcelImportEngine $engine,
    ) {}

    public function index(Request $request)
    {
        return $this->paginated($this->inventory->productList(
            $this->context->tenantId(), $this->context->user(),
            $request->string('search')->trim()->value() ?: null,
            min(max($request->integer('per_page', 20), 1), 100),
        ));
    }

    public function show(string $rimProduct)
    {
        $product = $this->rimProduct($rimProduct);

        return $this->ok($product->load(ProductController::detailRelations($product))->toArray() + [
            'inventory' => $this->inventory->summary($this->context->tenantId(), $this->context->user(), $product->id),
        ]);
    }

    public function inventory(Request $request, string $rimProduct)
    {
        $product = $this->rimProduct($rimProduct);
        $request->validate(['category' => ['required', Rule::in(RimInventoryService::CATEGORIES)]]);

        return $this->paginated($this->inventory->rows(
            $this->context->tenantId(), $this->context->user(), $product->id,
            $request->string('category')->value(),
            min(max($request->integer('per_page', 50), 1), 200),
        ));
    }

    /** Register Rim: New Stock (serial + warehouse) or Installed (serial + vehicle + position). */
    public function register(Request $request, string $rimProduct)
    {
        $product = $this->rimProduct($rimProduct);
        $validated = $request->validate([
            'serial_number' => ['required', 'string', 'max:100'],
            'warehouse_id' => ['nullable', 'required_without:vehicle_id', 'uuid'],
            'vehicle_id' => ['nullable', 'uuid'],
            'position_code' => ['nullable', 'required_with:vehicle_id', 'string', 'max:50'],
            'purchase_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $asset = $this->registration->register($this->context->tenantId(), $product, $validated, $this->context->user());

        return $this->ok($asset->load('installations'), 201);
    }

    /** Wheel positions of a vehicle with their current rim (Register Rim → Installed). */
    public function vehiclePositions(Vehicle $vehicle, DataScopeService $scope)
    {
        abort_unless($vehicle->tenant_id === $this->context->tenantId(), 404);
        abort_unless($scope->canAccessBranch($this->context->user(), $this->context->tenantId(), (string) $vehicle->branch_id), 403, Messages::localized('rim.errors.vehicleOutOfScope', ['vehicle' => $vehicle->registration_number]));

        return $this->ok($this->registration->positions($vehicle));
    }

    public function importTemplate(Request $request, string $rimProduct)
    {
        $product = $this->rimProduct($rimProduct);
        $mode = $this->mode($request);
        $name = 'rim-'.Str::slug(Str::lower($mode)).'-'.Str::slug($product->code ?: $product->name).'.xlsx';

        return response($this->engine->template($this->definition($product, $mode)), 200, [
            'Content-Type' => XlsxWriter::MIME,
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function importPreview(Request $request, string $rimProduct)
    {
        $product = $this->importableProduct($rimProduct);
        $mode = $this->mode($request);
        $this->validateFile($request);

        return $this->ok($this->engine->preview($this->definition($product, $mode), $request->file('file')->getRealPath()));
    }

    public function import(Request $request, string $rimProduct)
    {
        $product = $this->importableProduct($rimProduct);
        $mode = $this->mode($request);
        $validated = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:'.ExcelImportEngine::MAX_ROWS],
            'rows.*.row' => ['nullable', 'integer', 'min:1'],
            'rows.*.values' => ['required', 'array'],
        ]);

        return $this->ok($this->engine->import($this->definition($product, $mode), $validated['rows']));
    }

    private function definition(Product $product, string $mode): RimSerialImport
    {
        return new RimSerialImport($this->context->tenantId(), $product, $mode, $this->context->user());
    }

    private function mode(Request $request): string
    {
        $request->validate(['mode' => ['required', Rule::in(RimSerialImport::MODES)]]);

        return $request->string('mode')->value();
    }

    private function importableProduct(string $id): Product
    {
        $product = $this->rimProduct($id);
        $this->registration->assertRimProduct($product, $this->context->tenantId());

        return $product;
    }

    /** A Rim Product visible to the tenant (own or platform); soft-deleted ones stay readable for history. */
    private function rimProduct(string $id): Product
    {
        abort_unless(Str::isUuid($id), 404);
        $product = Product::query()->withTrashed()->find($id);
        abort_unless($product && $product->product_type === 'RIM', 404);

        return $product;
    }
}
