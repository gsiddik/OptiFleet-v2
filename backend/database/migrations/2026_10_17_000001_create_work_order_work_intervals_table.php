<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Auditable work time of a Work Order (owner rule): an interval opens on every transition into
 * IN_PROGRESS (start, resume after a hold / waiting for parts, rework start) and closes on every
 * transition out of IN_PROGRESS (QC_PENDING, ON_HOLD, WAITING_PART, CANCELLED, ...). Waiting for or
 * performing QC is therefore never work time; rework cycles add new intervals. Written only by
 * WorkOrderTransitionService inside the transition's row-locked transaction. At most one open
 * interval per Work Order (partial unique index). Work Orders started before this table existed
 * have no (or partial) intervals and are reported as "history incomplete" — nothing is backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_work_intervals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('work_order_id');
            $table->unsignedSmallInteger('cycle')->default(1); // 1 = initial work, 2+ = rework cycles
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('start_from_status', 32);
            $table->string('end_to_status', 32)->nullable();
            $table->uuid('started_by')->nullable();
            $table->uuid('ended_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            $table->index(['tenant_id', 'ended_at']);
            $table->index(['work_order_id', 'started_at']);
        });
        DB::statement('CREATE UNIQUE INDEX work_order_work_intervals_one_open ON work_order_work_intervals (work_order_id) WHERE ended_at IS NULL');
        DB::statement('ALTER TABLE work_order_work_intervals ADD CONSTRAINT work_order_work_intervals_order CHECK (ended_at IS NULL OR ended_at >= started_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_work_intervals');
    }
};
