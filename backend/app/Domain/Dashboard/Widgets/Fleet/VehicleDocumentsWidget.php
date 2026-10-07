<?php

namespace App\Domain\Dashboard\Widgets\Fleet;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\Widgets\Widget;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * FL-06 Expiring Vehicle Documents. Vehicle documents have no superseded/lifecycle state, so the
 * active document per vehicle + type is the one with the latest issue_date (then created_at) — owner
 * decision 8; OTHER documents are each assessed on their own. Two separate deadlines are measured:
 * the expiry date ("Have an Expiry Date?") and the extension deadline ("Need to be extended?").
 */
class VehicleDocumentsWidget extends Widget
{
    /** Day buckets relative to today (fixed defaults, owner decision 5). */
    public const BUCKETS = ['expired', 'd30', 'd60', 'd90'];

    public function id(): string
    {
        return 'FL-06';
    }

    public function modules(): array
    {
        return ['VEHICLE'];
    }

    public function permissions(): array
    {
        return ['vehicle.view'];
    }

    public function compute(DashboardContext $context): array
    {
        return ['data' => [
            'expiry' => $this->bucketCounts($context, 'expiry_date'),
            'extension' => $this->bucketCounts($context, 'extension_deadline'),
        ]];
    }

    public function detailRules(): ?array
    {
        return [
            'measure' => ['required', 'in:expiry,extension'],
            'bucket' => ['nullable', 'in:'.implode(',', self::BUCKETS)],
        ];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        $column = $params['measure'] === 'extension' ? 'extension_deadline' : 'expiry_date';
        $query = $this->documents($context, $column)
            ->leftJoin('branches as b', 'b.id', '=', 'v.branch_id')
            ->orderBy("d.{$column}")
            ->select(['d.id', 'd.vehicle_id', 'v.registration_number', 'b.name as branch_name', 'd.document_type', 'd.document_number',
                DB::raw("d.{$column} as deadline")]);
        if ($bucket = $params['bucket'] ?? null) {
            $this->constrainBucket($query, $column, $bucket, $context);
        } else {
            $query->where("d.{$column}", '<=', $context->today()->addDays(90)->toDateString());
        }

        return $this->paginate($query, $params, fn ($r) => [
            'id' => $r->id, 'vehicle_id' => $r->vehicle_id, 'registration_number' => $r->registration_number, 'branch_name' => $r->branch_name,
            'document_type' => $r->document_type, 'document_number' => $r->document_number, 'deadline' => $r->deadline,
            'days_left' => -self::daysSince($context, $r->deadline),
        ]);
    }

    private function bucketCounts(DashboardContext $context, string $column): array
    {
        $counts = [];
        foreach (self::BUCKETS as $bucket) {
            $query = $this->documents($context, $column);
            $this->constrainBucket($query, $column, $bucket, $context);
            $counts[$bucket] = $query->count();
        }

        return $counts;
    }

    private function constrainBucket(Builder $query, string $column, string $bucket, DashboardContext $context): void
    {
        $today = $context->today();
        match ($bucket) {
            'expired' => $query->where("d.{$column}", '<', $today->toDateString()),
            'd30' => $query->whereBetween("d.{$column}", [$today->toDateString(), $today->addDays(30)->toDateString()]),
            'd60' => $query->whereBetween("d.{$column}", [$today->addDays(31)->toDateString(), $today->addDays(60)->toDateString()]),
            'd90' => $query->whereBetween("d.{$column}", [$today->addDays(61)->toDateString(), $today->addDays(90)->toDateString()]),
        };
    }

    /** Active documents of in-scope, non-disposed vehicles that carry the given deadline. */
    private function documents(DashboardContext $context, string $column): Builder
    {
        $query = DB::table('vehicle_documents as d')
            ->join('vehicles as v', 'v.id', '=', 'd.vehicle_id')
            ->where('d.tenant_id', $context->tenantId)->whereNull('d.deleted_at')
            ->whereNull('v.deleted_at')->where('v.status', '!=', 'DISPOSED')
            ->whereNotNull("d.{$column}")
            ->where(fn (Builder $q) => $q->where('d.document_type', 'OTHER')->orWhereRaw(
                'd.id = (SELECT d2.id FROM vehicle_documents d2 WHERE d2.vehicle_id = d.vehicle_id AND d2.document_type = d.document_type
                  AND d2.deleted_at IS NULL ORDER BY d2.issue_date DESC NULLS LAST, d2.created_at DESC, d2.id DESC LIMIT 1)'
            ));

        return $context->scopeBranch($query, 'v.branch_id');
    }
}
