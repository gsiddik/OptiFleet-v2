<?php

namespace App\Domain\Intelligence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use MongoDB\Laravel\Eloquent\Model;

/**
 * Phase 7 Section 9-10: the model registry. Every training run creates a
 * NEW document (never overwrites a prior version — unique key includes
 * `version`), so historical predictions stay attributable to the exact
 * model that produced them. "One ACTIVE per (model_code, scope,
 * tenant_id)" is enforced by ModelRegistryService::activate(), not by a
 * unique index, because many RETIRED/EVALUATED/FAILED versions
 * legitimately coexist.
 *
 * The `artifact` field holds the trained parameters directly as an
 * embedded document (e.g. logistic-regression coefficients + feature
 * means/stddevs for normalization) rather than a filesystem/S3 path.
 * This is a deliberate security choice (Section 45/64): there is no
 * "load artifact from path" step anywhere, so a corrupted or
 * user-influenced path can never be used to load an arbitrary file.
 * Interpretable linear models are small enough that this fits
 * comfortably in one Mongo document.
 */
class IntelligenceModel extends Model
{
    use HasUuids;

    protected $connection = 'mongodb';

    protected $collection = 'intelligence_models';

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_TRAINING = 'TRAINING';

    public const STATUS_EVALUATED = 'EVALUATED';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_RETIRED = 'RETIRED';

    public const STATUS_FAILED = 'FAILED';

    public const SCOPE_GLOBAL = 'GLOBAL';

    public const SCOPE_TENANT = 'TENANT';

    protected $fillable = [
        'model_code', 'model_type', 'target', 'entity_type', 'algorithm',
        'version', 'feature_set_version', 'scope', 'tenant_id',
        'training_period_from', 'training_period_to', 'trained_at',
        'dataset_size', 'positive_count', 'metrics', 'business_metrics',
        'acceptance_criteria', 'status', 'artifact', 'artifact_checksum',
        'created_by', 'activated_at', 'retired_at', 'failure_reason',
    ];

    protected $casts = [
        'trained_at' => 'datetime',
        'activated_at' => 'datetime',
        'retired_at' => 'datetime',
        'dataset_size' => 'integer',
        'positive_count' => 'integer',
    ];
}
