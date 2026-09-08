<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Intelligence\Models\IntelligenceModel;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 7 Section 9-10, 16: model lifecycle. Versions are monotonically
 * incrementing integers per (model_code, scope, tenant_id) — never
 * reused, never overwritten. activate() is the one release-critical
 * invariant in this class: exactly one ACTIVE version may exist per
 * (model_code, scope, tenant_id) at a time, enforced by retiring the
 * previous active version in the same call.
 */
class ModelRegistryService
{
    public function nextVersion(string $modelCode, string $scope, ?string $tenantId): int
    {
        $last = IntelligenceModel::query()->withoutGlobalScopes()
            ->where('model_code', $modelCode)->where('scope', $scope)->where('tenant_id', $tenantId)
            ->orderByDesc('version')->first();

        return $last ? ((int) $last->version + 1) : 1;
    }

    public function createDraft(array $attributes): IntelligenceModel
    {
        $version = $this->nextVersion($attributes['model_code'], $attributes['scope'], $attributes['tenant_id'] ?? null);

        return IntelligenceModel::query()->create(array_merge($attributes, [
            'version' => $version,
            'status' => IntelligenceModel::STATUS_DRAFT,
        ]));
    }

    public function markTraining(IntelligenceModel $model): IntelligenceModel
    {
        $model->update(['status' => IntelligenceModel::STATUS_TRAINING]);

        return $model->fresh();
    }

    public function recordEvaluation(IntelligenceModel $model, array $metrics, array $businessMetrics, array $artifact, int $datasetSize, int $positiveCount): IntelligenceModel
    {
        $model->update([
            'status' => IntelligenceModel::STATUS_EVALUATED,
            'metrics' => $metrics,
            'business_metrics' => $businessMetrics,
            'artifact' => $artifact,
            'artifact_checksum' => hash('sha256', json_encode($artifact)),
            'dataset_size' => $datasetSize,
            'positive_count' => $positiveCount,
            'trained_at' => now(),
        ]);

        return $model->fresh();
    }

    public function markFailed(IntelligenceModel $model, string $reason): IntelligenceModel
    {
        $model->update(['status' => IntelligenceModel::STATUS_FAILED, 'failure_reason' => $reason]);

        return $model->fresh();
    }

    /**
     * Section 16 (model performance gate): only a model meeting its own
     * documented acceptance_criteria may become ACTIVE. Activating
     * atomically retires whatever was previously ACTIVE for the same
     * (model_code, scope, tenant_id) — Section 16's "one explicitly
     * active production version" invariant.
     */
    public function activate(IntelligenceModel $model): IntelligenceModel
    {
        if ($model->status !== IntelligenceModel::STATUS_EVALUATED) {
            throw new RuntimeException("Only an EVALUATED model can be activated (current status: {$model->status}).");
        }

        $criteria = $model->acceptance_criteria ?? [];
        $metrics = $model->metrics ?? [];
        foreach ($criteria as $metricName => $minimum) {
            $actual = $metrics[$metricName] ?? null;
            if ($actual === null || $actual < $minimum) {
                throw new InvalidArgumentException(
                    "Model does not meet acceptance criteria: {$metricName}={$actual} is below required minimum {$minimum}."
                );
            }
        }

        $previousActive = IntelligenceModel::query()->withoutGlobalScopes()
            ->where('model_code', $model->model_code)->where('scope', $model->scope)->where('tenant_id', $model->tenant_id)
            ->where('status', IntelligenceModel::STATUS_ACTIVE)
            ->first();

        if ($previousActive) {
            $previousActive->update(['status' => IntelligenceModel::STATUS_RETIRED, 'retired_at' => now()]);
        }

        $model->update(['status' => IntelligenceModel::STATUS_ACTIVE, 'activated_at' => now()]);

        return $model->fresh();
    }

    public function retire(IntelligenceModel $model): IntelligenceModel
    {
        $model->update(['status' => IntelligenceModel::STATUS_RETIRED, 'retired_at' => now()]);

        return $model->fresh();
    }

    /** The one model that may actually serve production inference for this target/scope. */
    public function active(string $modelCode, string $scope, ?string $tenantId): ?IntelligenceModel
    {
        return IntelligenceModel::query()->withoutGlobalScopes()
            ->where('model_code', $modelCode)->where('scope', $scope)->where('tenant_id', $tenantId)
            ->where('status', IntelligenceModel::STATUS_ACTIVE)
            ->first();
    }

    public function generateModelCode(string $target): string
    {
        return Str::slug($target, '_');
    }
}
