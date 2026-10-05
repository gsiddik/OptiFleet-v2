<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Models\VehicleDocument;
use App\Domain\Vehicle\Services\VehicleDocumentService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class VehicleDocumentController extends Controller
{
    public function __construct(
        private readonly VehicleDocumentService $documents,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Vehicle $vehicle)
    {
        $this->authorizeScope($vehicle);

        return $this->ok($vehicle->documents()->latest('created_at')->get());
    }

    public function store(Request $request, Vehicle $vehicle)
    {
        $this->authorizeScope($vehicle);

        // "Have an Expiry Date?" / "Need to be extended?": each checked box makes its date mandatory;
        // an unchecked box stores no date. A legacy client sending only expiry_date is treated as
        // "has an expiry date".
        $request->merge(['has_expiry' => $request->boolean('has_expiry', $request->filled('expiry_date')), 'needs_extension' => $request->boolean('needs_extension')]);
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf'],
            'document_type' => ['required', 'in:'.implode(',', VehicleDocument::TYPES)],
            'document_number' => ['nullable', 'string', 'max:100'],
            'issue_date' => ['nullable', 'date'],
            'has_expiry' => ['boolean'],
            'expiry_date' => ['nullable', 'date', 'required_if:has_expiry,true'],
            'needs_extension' => ['boolean'],
            'extension_deadline' => ['nullable', 'date', 'required_if:needs_extension,true'],
            'notes' => ['nullable', 'string'],
        ], [
            'expiry_date.required_if' => 'Expiry Date is required when "Have an Expiry Date?" is checked.',
            'extension_deadline.required_if' => 'Extension Deadline is required when "Need to be extended?" is checked.',
        ]);
        $attributes = collect($validated)->only(['document_type', 'document_number', 'issue_date', 'has_expiry', 'expiry_date', 'needs_extension', 'extension_deadline', 'notes'])->all();
        if (! $attributes['has_expiry']) {
            $attributes['expiry_date'] = null;
        }
        if (! $attributes['needs_extension']) {
            $attributes['extension_deadline'] = null;
        }

        $document = $this->documents->upload($vehicle, $request->file('file'), $attributes, $this->context->user()->id);

        return $this->ok($document, 201);
    }

    public function download(Vehicle $vehicle, VehicleDocument $document)
    {
        $this->authorizeScope($vehicle);
        abort_unless($document->vehicle_id === $vehicle->id, 404);

        return Storage::disk($document->disk)->response($document->path, $document->original_filename);
    }

    public function destroy(Vehicle $vehicle, VehicleDocument $document)
    {
        $this->authorizeScope($vehicle);
        abort_unless($document->vehicle_id === $vehicle->id, 404);

        $document->delete();

        return $this->message('Document removed.');
    }

    private function authorizeScope(Vehicle $vehicle): void
    {
        abort_unless($vehicle->tenant_id === $this->context->tenantId(), 404);
        abort_unless(
            $this->scope->canAccessBranch($this->context->user(), $this->context->tenantId(), $vehicle->branch_id),
            403,
            'This vehicle is outside your assigned data scope.'
        );
    }
}
