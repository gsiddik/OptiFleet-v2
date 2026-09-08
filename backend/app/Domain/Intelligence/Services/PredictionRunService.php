<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Analytics\Models\EtlRun;
use App\Domain\Analytics\Services\EtlRunService;
use App\Domain\Analytics\Support\BusinessDateResolver;
use App\Domain\Entitlement\Services\EntitlementService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Intelligence\Risk\RiskScorerRegistry;
use App\Jobs\Intelligence\RunPredictionJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 7 Section 46-47: batch inference, the primary prediction mode
 * (Section 46 — "do not recalculate every vehicle prediction on every
 * dashboard request"). One EtlRun per tenant+target+date (job_type =
 * "predict_{target}"), mirroring the Phase 6/Batch A run-tracking shape.
 */
class PredictionRunService
{
    public function __construct(
        private readonly RiskScorerRegistry $scorers,
        private readonly PredictionService $predictor,
        private readonly EtlRunService $runs,
        private readonly EntitlementService $entitlements,
        private readonly BusinessDateResolver $businessDates,
    ) {}

    public function eligibleTenants(?string $tenantId = null)
    {
        $query = Tenant::query()->withoutGlobalScopes()->where('status', 'ACTIVE');
        if ($tenantId) {
            $query->where('id', $tenantId);
        }

        return $query->get()->filter(fn (Tenant $t) => $this->entitlements->tenantHasModule($t->id, 'MAINTENANCE_INTELLIGENCE'))->values();
    }

    public function run(?string $dateOverride, ?string $tenantId, ?string $target, bool $sync = false): array
    {
        $targets = $target ? [$target] : $this->scorers->targets();
        $tenants = $this->eligibleTenants($tenantId);
        $summary = [];

        foreach ($tenants as $tenant) {
            $businessDate = $dateOverride ?: $this->businessDates->yesterdayFor($tenant)->format('Y-m-d');

            foreach ($targets as $t) {
                if (! $sync) {
                    RunPredictionJob::dispatch($tenant->id, $t, $businessDate);

                    continue;
                }

                $run = $this->runForTenant($tenant->id, $t, $businessDate);
                $summary[] = ['tenant_id' => $tenant->id, 'target' => $t, 'status' => $run->status, 'processed' => $run->processed_count];
            }
        }

        return $summary;
    }

    public function runForTenant(string $tenantId, string $target, string $businessDate): EtlRun
    {
        $run = $this->runs->start("predict_{$target}", $tenantId, $businessDate, 'v1', 'schedule');

        try {
            $entityType = config("intelligence.model_targets.{$target}.entity_type");
            $collection = config("intelligence.feature_collections.{$entityType}");
            $idField = config("intelligence.feature_entity_id_field.{$entityType}");

            $documents = DB::connection('mongodb')->table($collection)
                ->where('tenant_id', $tenantId)->where('feature_date', $businessDate)->get();

            $count = 0;
            foreach ($documents as $doc) {
                $doc = (array) $doc;
                $entityId = $doc[$idField] ?? null;
                if (! $entityId) {
                    continue;
                }
                $asOf = CarbonImmutable::parse($doc['source_data_as_of']);
                $this->predictor->predict($tenantId, $entityType, $entityId, $target, $doc, $asOf, $businessDate);
                $count++;
            }

            $result = new \App\Domain\Analytics\Support\EtlDatasetResult;
            $result->sourceCount = $documents->count();
            $result->processedCount = $count;
            $result->insertedCount = $count;
            $run = $this->runs->complete($run, $result);

            Log::info('intelligence.prediction.completed', ['tenant_id' => $tenantId, 'target' => $target, 'business_date' => $businessDate, 'count' => $count]);

            return $run;
        } catch (Throwable $e) {
            $this->runs->fail($run, $e);
            Log::warning('intelligence.prediction.failed', ['tenant_id' => $tenantId, 'target' => $target, 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}
