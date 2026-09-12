<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * G-07: PartnerPerformanceService::record() is a generic append-only event
 * log, but Postgres backs the `enum()` column with a CHECK constraint, so a
 * new event_type literal (EXTERNAL_SERVICE_COMPLETED, for completed
 * external-service work) needs its own migration rather than just being a
 * new string constant in application code.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE partner_performance_events DROP CONSTRAINT partner_performance_events_event_type_check');
        DB::statement("ALTER TABLE partner_performance_events ADD CONSTRAINT partner_performance_events_event_type_check CHECK (event_type IN ('PO_ISSUED', 'DELIVERY_ON_TIME', 'DELIVERY_LATE', 'GOODS_ACCEPTED', 'GOODS_REJECTED', 'RETURN', 'EXTERNAL_SERVICE_COMPLETED'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE partner_performance_events DROP CONSTRAINT partner_performance_events_event_type_check');
        DB::statement("ALTER TABLE partner_performance_events ADD CONSTRAINT partner_performance_events_event_type_check CHECK (event_type IN ('PO_ISSUED', 'DELIVERY_ON_TIME', 'DELIVERY_LATE', 'GOODS_ACCEPTED', 'GOODS_REJECTED', 'RETURN'))");
    }
};
