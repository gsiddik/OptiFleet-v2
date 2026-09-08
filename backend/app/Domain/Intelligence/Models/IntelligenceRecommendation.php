<?php

namespace App\Domain\Intelligence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use MongoDB\Laravel\Eloquent\Model;

/**
 * Phase 7 Section 37-39: a prescriptive suggestion derived from a
 * prediction/insight. Never itself an authoritative action — Section 2:
 * accepting one only creates a Maintenance Request through the existing
 * Phase 3 workflow, it never closes/approves anything directly.
 */
class IntelligenceRecommendation extends Model
{
    use HasUuids;

    protected $connection = 'mongodb';

    protected $collection = 'intelligence_recommendations';

    public const STATUS_NEW = 'NEW';

    public const STATUS_REVIEWED = 'REVIEWED';

    public const STATUS_ACCEPTED = 'ACCEPTED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_CONVERTED = 'CONVERTED_TO_ACTION';

    public const STATUS_EXPIRED = 'EXPIRED';

    protected $fillable = [
        'tenant_id', 'prediction_id', 'entity_type', 'entity_id', 'insight_level',
        'recommendation_type', 'priority', 'description', 'evidence', 'suggested_parts',
        'suggested_due_at', 'status', 'reviewed_by', 'reviewed_at', 'review_note',
        'maintenance_request_id', 'expires_at',
    ];

    protected $casts = [
        'suggested_due_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
