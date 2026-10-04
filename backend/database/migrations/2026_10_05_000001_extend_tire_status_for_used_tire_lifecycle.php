<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Used Tire Management lifecycle (owner requirement 2026-10-05):
 *
 *  - tires.current_status gains REUSE (inspected, fit for reuse — the only used status that is
 *    available for installation) and HOLD (inspection incomplete / decision pending — never
 *    available). SCRAPPED stays the stored terminal value (shown as "SCRAP").
 *  - product_tires.vehicle_group gains OTR (OTR / Heavy Equipment) so the three tire categories the
 *    inspection rules distinguish — Passenger / Light Truck (CAR), Truck / Bus, OTR — exist.
 *  - Data: a tire that is IN_STOCK but was installed before is a used tire back in stock; it becomes
 *    REUSE so IN_STOCK means new stock only. History rows are not touched.
 */
return new class extends Migration
{
    private const STATUSES_BEFORE = "'IN_STOCK','RESERVED','INSTALLED','IN_USE','REMOVED','UNDER_INSPECTION','RETREAD','REPAIR','QUARANTINED','SCRAPPED','SOLD','LOST'";

    public function up(): void
    {
        DB::statement('ALTER TABLE tires DROP CONSTRAINT tires_current_status_check');
        DB::statement('ALTER TABLE tires ADD CONSTRAINT tires_current_status_check CHECK (current_status::text = ANY (ARRAY['.self::STATUSES_BEFORE.",'REUSE','HOLD']::character varying[]))");

        DB::statement('ALTER TABLE product_tires DROP CONSTRAINT product_tires_vehicle_group_check');
        DB::statement("ALTER TABLE product_tires ADD CONSTRAINT product_tires_vehicle_group_check CHECK (vehicle_group::text = ANY (ARRAY['CAR','TRUCK_BUS','OTR']::character varying[]))");

        DB::statement("UPDATE tires SET current_status = 'REUSE', updated_at = now()
            WHERE current_status = 'IN_STOCK' AND EXISTS (SELECT 1 FROM tire_installations i WHERE i.tire_id = tires.id)");
    }

    public function down(): void
    {
        DB::statement("UPDATE tires SET current_status = 'IN_STOCK' WHERE current_status = 'REUSE'");
        DB::statement("UPDATE tires SET current_status = 'QUARANTINED' WHERE current_status = 'HOLD'");
        DB::statement('ALTER TABLE tires DROP CONSTRAINT tires_current_status_check');
        DB::statement('ALTER TABLE tires ADD CONSTRAINT tires_current_status_check CHECK (current_status::text = ANY (ARRAY['.self::STATUSES_BEFORE.']::character varying[]))');

        DB::statement("UPDATE product_tires SET vehicle_group = 'TRUCK_BUS' WHERE vehicle_group = 'OTR'");
        DB::statement('ALTER TABLE product_tires DROP CONSTRAINT product_tires_vehicle_group_check');
        DB::statement("ALTER TABLE product_tires ADD CONSTRAINT product_tires_vehicle_group_check CHECK (vehicle_group::text = ANY (ARRAY['CAR','TRUCK_BUS']::character varying[]))");
    }
};
