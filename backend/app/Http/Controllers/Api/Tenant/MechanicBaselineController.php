<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Dashboard\Models\MechanicPerformanceBaseline;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Mechanic Performance baselines (WS-07): expected work hours per maintenance type, set by users
 * holding mechanic_baseline.manage (route middleware). Empty = "baseline not set" — never a default.
 */
class MechanicBaselineController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): JsonResponse
    {
        return $this->ok($this->rows());
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'baselines' => ['required', 'array', 'min:1'],
            'baselines.*.maintenance_type' => ['required', 'distinct', Rule::in(MechanicPerformanceBaseline::MAINTENANCE_TYPES)],
            'baselines.*.baseline_hours' => ['nullable', 'numeric', 'gt:0', 'max:999999'],
        ]);
        $tenantId = $this->context->tenantId();
        $userId = $this->context->user()?->id;

        DB::transaction(function () use ($validated, $tenantId, $userId) {
            foreach ($validated['baselines'] as $row) {
                $query = MechanicPerformanceBaseline::query()->where('tenant_id', $tenantId)->where('maintenance_type', $row['maintenance_type']);
                if (($row['baseline_hours'] ?? null) === null) {
                    $query->delete();

                    continue;
                }
                MechanicPerformanceBaseline::query()->updateOrCreate(
                    ['tenant_id' => $tenantId, 'maintenance_type' => $row['maintenance_type']],
                    ['baseline_hours' => (string) $row['baseline_hours'], 'updated_by' => $userId],
                );
            }
        });

        return $this->ok($this->rows());
    }

    /** @return list<array{maintenance_type: string, baseline_hours: ?string, updated_at: ?string}> */
    private function rows(): array
    {
        $set = MechanicPerformanceBaseline::query()->where('tenant_id', $this->context->tenantId())->get()->keyBy('maintenance_type');

        return array_map(fn (string $type) => [
            'maintenance_type' => $type,
            'baseline_hours' => $set->get($type)?->baseline_hours,
            'updated_at' => $set->get($type)?->updated_at?->toIso8601String(),
        ], MechanicPerformanceBaseline::MAINTENANCE_TYPES);
    }
}
