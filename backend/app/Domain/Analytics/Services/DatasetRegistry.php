<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Contracts\DatasetExtractor;
use InvalidArgumentException;

/**
 * Registry of all Phase 6 dataset extractors, keyed by
 * DatasetExtractor::key(). Populated in AnalyticsServiceProvider so
 * Jobs/Commands never hard-code a list of extractor classes — adding a new
 * dataset (Batches C-F) means registering it here once.
 */
class DatasetRegistry
{
    /** @var array<string, DatasetExtractor> */
    private array $extractors = [];

    public function register(DatasetExtractor $extractor): void
    {
        $this->extractors[$extractor->key()] = $extractor;
    }

    public function get(string $key): DatasetExtractor
    {
        return $this->extractors[$key]
            ?? throw new InvalidArgumentException("No analytics dataset extractor registered for key [{$key}].");
    }

    public function has(string $key): bool
    {
        return isset($this->extractors[$key]);
    }

    /** @return string[] */
    public function keys(): array
    {
        return array_keys($this->extractors);
    }

    /** @return DatasetExtractor[] */
    public function all(): array
    {
        return array_values($this->extractors);
    }
}
