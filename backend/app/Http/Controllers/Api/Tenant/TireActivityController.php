<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Tire\Services\TireActivityService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Tire History and the recent-activity lists of Tire Operations / Used Tire Management. */
class TireActivityController extends Controller
{
    public function __construct(private readonly TenantContext $context, private readonly TireActivityService $activity) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'type' => ['nullable', 'array'],
            'type.*' => [Rule::in(TireActivityService::TYPES)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            // Used Tire Management → Retread lists only completed retread / repair cycles.
            'completed_cycles' => ['nullable', 'boolean'],
        ]);

        return $this->paginated($this->activity->feed(
            $this->context->tenantId(),
            $this->context->user(),
            $validated['type'] ?? [],
            trim((string) ($validated['search'] ?? '')) ?: null,
            (int) ($validated['per_page'] ?? 20),
            $request->boolean('completed_cycles'),
        ));
    }
}
