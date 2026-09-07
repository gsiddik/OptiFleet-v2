<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\ProductMaster\Models\Uom;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UomController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request)
    {
        $query = Uom::query();
        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }

        return $this->paginated($query->orderBy('code')->paginate($request->integer('per_page', 50)));
    }

    public function store(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('uoms', 'code')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:100'],
        ]);

        $uom = Uom::query()->create($validated + ['tenant_id' => $tenantId, 'is_system' => false, 'status' => 'ACTIVE']);

        return $this->ok($uom, 201);
    }
}
