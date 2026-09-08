<?php

namespace App\Domain\Analytics\Support;

use Illuminate\Support\Facades\DB;
use MongoDB\Driver\Exception\BulkWriteException;

/**
 * Shared idempotent-upsert primitive every dataset extractor uses to
 * write into its Mongo collection (Section 9). One bulkWrite of
 * updateOne(..., upsert: true) operations per extraction run:
 *
 * - a brand new key becomes an upsert -> insertedCount
 * - an existing key whose values actually changed -> updatedCount
 * - an existing key re-written with identical values (the common case
 *   when a business date is re-run with unchanged source data) ->
 *   skippedCount, not updatedCount — this is what makes idempotency
 *   observable in the run's own counters, not just "no duplicates".
 */
class AnalyticsUpsertWriter
{
    /**
     * @param  array<int, array{key: array<string,mixed>, doc: array<string,mixed>}>  $documents
     */
    public function upsertMany(string $collection, array $documents, EtlDatasetResult $result): void
    {
        if (empty($documents)) {
            return;
        }

        $operations = array_map(
            fn (array $item) => ['updateOne' => [$item['key'], ['$set' => $item['doc']], ['upsert' => true]]],
            $documents,
        );

        try {
            $writeResult = DB::connection('mongodb')->getCollection($collection)->bulkWrite($operations, ['ordered' => false]);
            $this->applyCounts($result, $writeResult->getUpsertedCount(), $writeResult->getMatchedCount(), $writeResult->getModifiedCount());
        } catch (BulkWriteException $e) {
            $writeResult = $e->getWriteResult();
            $this->applyCounts($result, $writeResult->getUpsertedCount(), $writeResult->getMatchedCount(), $writeResult->getModifiedCount());
            foreach ($writeResult->getWriteErrors() as $writeError) {
                $result->recordError($writeError->getMessage());
            }
        }
    }

    private function applyCounts(EtlDatasetResult $result, int $upserted, int $matched, int $modified): void
    {
        $result->insertedCount += $upserted;
        $result->updatedCount += $modified;
        $result->skippedCount += max(0, $matched - $modified);
    }
}
