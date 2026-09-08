<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Analytics\Services\DatasetRegistry;

/**
 * Distinct container binding from Phase 6's DatasetRegistry (same
 * behavior, reused wholesale per Section 79) so feature extractors and
 * analytics extractors are registered/resolved independently.
 */
class FeatureDatasetRegistry extends DatasetRegistry {}
