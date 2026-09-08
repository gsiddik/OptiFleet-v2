<?php

namespace App\Domain\Analytics\Support;

/**
 * Counts contract every DatasetExtractor returns (Phase 6 Section 8).
 * source_count: rows read from PostgreSQL for the business date/tenant.
 * processed_count: rows that passed transformation + validation.
 * inserted/updated_count: how the upsert into Mongo resolved.
 * skipped_count: rows deliberately excluded (non-fatal — e.g. no
 * applicable dimension), not an error.
 * failed_count: rows that could not be validated/written (Section 16).
 */
class EtlDatasetResult
{
    public int $sourceCount = 0;

    public int $processedCount = 0;

    public int $insertedCount = 0;

    public int $updatedCount = 0;

    public int $skippedCount = 0;

    public int $failedCount = 0;

    /** @var string[] */
    public array $errors = [];

    private const MAX_ERRORS = 20;

    public function recordError(string $message): void
    {
        $this->failedCount++;
        if (count($this->errors) < self::MAX_ERRORS) {
            $this->errors[] = $message;
        }
    }

    public function toArray(): array
    {
        return [
            'source_count' => $this->sourceCount,
            'processed_count' => $this->processedCount,
            'inserted_count' => $this->insertedCount,
            'updated_count' => $this->updatedCount,
            'skipped_count' => $this->skippedCount,
            'failed_count' => $this->failedCount,
            'error_summary' => $this->errors ?: null,
        ];
    }
}
