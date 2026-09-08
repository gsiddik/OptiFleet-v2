<?php

namespace App\Domain\Intelligence\DataReadiness;

use App\Domain\Intelligence\Labels\LabelBuilderRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7 Section 41, 66-67: the single place historical feature rows
 * are paired with an outcome label for a given tenant+target. Used by
 * both DataReadinessAssessmentService (Batch B) and the training
 * pipeline (Batch C) so the two can never disagree about what counts as
 * a usable row.
 *
 * Rows whose label is not yet observable (label() returned null — the
 * horizon has not elapsed) are dropped, never coerced into a negative.
 */
class TrainingDatasetBuilder
{
    public function __construct(private readonly LabelBuilderRegistry $labels) {}

    /** @return TrainingRow[] */
    public function build(string $tenantId, string $target): array
    {
        $labelBuilder = $this->labels->get($target);
        $entityType = $labelBuilder->entityType();
        $collection = config("intelligence.feature_collections.{$entityType}");
        $idField = config("intelligence.feature_entity_id_field.{$entityType}");
        $now = CarbonImmutable::now();

        $documents = DB::connection('mongodb')->table($collection)
            ->where('tenant_id', $tenantId)
            ->orderBy('feature_date')
            ->get();

        $rows = [];
        foreach ($documents as $doc) {
            $doc = (array) $doc;
            $entityId = $doc[$idField] ?? null;
            if (! $entityId) {
                continue;
            }

            $featureDate = CarbonImmutable::createFromFormat('Y-m-d', $doc['feature_date'], 'UTC');
            $label = $labelBuilder->label($tenantId, $entityId, $featureDate, $now);
            if ($label === null) {
                continue;
            }

            $rows[] = new TrainingRow($entityId, $doc['feature_date'], $doc, $label);
        }

        return $rows;
    }
}
