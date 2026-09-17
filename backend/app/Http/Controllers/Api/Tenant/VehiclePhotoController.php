<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Services\VehiclePhotoService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class VehiclePhotoController extends Controller
{
    public function __construct(
        private readonly VehiclePhotoService $photos,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function store(Request $request, Vehicle $vehicle)
    {
        $this->authorizeScope($vehicle);

        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png'],
        ]);

        $vehicle = $this->photos->upload($vehicle, $request->file('file'));

        return $this->ok($vehicle);
    }

    public function show(Vehicle $vehicle)
    {
        $this->authorizeScope($vehicle);
        abort_unless($vehicle->photo_path !== null, 404);

        return Storage::disk($vehicle->photo_disk)->response($vehicle->photo_path);
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
