<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner decision (final): the Return popup's "Used Qty" is NOT unused
 * issued stock being returned — it is the OLD/REMOVED COMPONENT taken off
 * the vehicle when a replacement part is installed, later returned from
 * the vehicle to the warehouse. This is architecturally a different
 * transaction than `work_order_part_returns` (which only ever represents
 * warehouse-issued stock flowing back, whether ever installed or not) and
 * is never a reversal of the newly-installed replacement's own
 * consumption — that Job/Part stays CONSUMED.
 *
 * Two new tables, mirroring the "Removal" and "Return" split the existing
 * (heavier, serialized-asset) ComponentAsset/ComponentRemoval domain
 * already uses for the same real-world event, but sized for ordinary
 * quantity-tracked spare parts rather than individually serial-numbered
 * assets:
 *
 * - work_order_removed_components: the removal event itself (which
 *   product came off the vehicle, how much, in what condition,
 *   optionally which newly-installed Planned Part replaced it — nullable,
 *   since a removal is not always a like-for-like replacement and the old
 *   product is never assumed to equal the new one).
 * - work_order_removed_component_returns: the physical return of that
 *   removed quantity to a warehouse. Writes exactly one zero-balance-
 *   effect stock_movements row (new REMOVED_COMPONENT_RETURN type,
 *   following the exact precedent CONSUME/SALE already established) so
 *   it is fully traceable but never silently becomes available stock —
 *   the actual inspect/dispose/repair/scrap/sell decision is deliberately
 *   out of scope here (the existing Used Sparepart Processing pipeline is
 *   the natural place to extend into this next, not duplicated now).
 *
 * work_order_removed_component_evidence mirrors
 * work_order_part_return_evidence exactly (private disk, upload before
 * confirm, removable until linked) for the same JPG/PNG-upload-not-a-URL-
 * textfield requirement, scoped to the specific removed-component record
 * rather than the Work Order globally, since one Work Order can replace
 * several components.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_movement_type_check');
        DB::statement(
            'ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_movement_type_check '.
            'CHECK (movement_type::text = ANY (ARRAY['.
            "'OPENING','RECEIPT','RESERVATION','RELEASE_RESERVATION','ISSUE','RETURN',".
            "'TRANSFER_OUT','TRANSFER_IN','ADJUSTMENT_PLUS','ADJUSTMENT_MINUS','STOCK_OPNAME','SCRAP','CONSUME','SALE',".
            "'REMOVED_COMPONENT_RETURN'".
            ']::character varying[]))'
        );

        Schema::create('work_order_removed_components', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('work_order_id');
            $table->uuid('maintenance_job_id')->nullable();
            // The Planned Part for the NEW part that replaced this one, if any — establishes
            // replacement traceability without assuming the old and new products are the same.
            $table->uuid('replaced_by_planned_part_id')->nullable();
            $table->uuid('product_id');
            $table->decimal('quantity', 16, 4);
            // Reuses the same GOOD/FAULTY vocabulary work_order_part_returns.condition already
            // established for USED_GOOD/USED_FAULTY, rather than inventing new terminology.
            $table->enum('condition', ['GOOD', 'FAULTY']);
            $table->text('notes')->nullable();
            $table->enum('status', ['PENDING_RETURN', 'RETURNED'])->default('PENDING_RETURN');
            $table->uuid('removed_by')->nullable();
            $table->timestamp('removed_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            $table->foreign('maintenance_job_id')->references('id')->on('maintenance_jobs')->nullOnDelete();
            $table->foreign('replaced_by_planned_part_id')->references('id')->on('work_order_planned_parts')->nullOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->index(['tenant_id', 'work_order_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('work_order_removed_component_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('work_order_removed_component_id');
            $table->uuid('warehouse_id');
            $table->decimal('quantity', 16, 4);
            $table->uuid('stock_movement_id')->nullable();
            $table->text('reason')->nullable();
            $table->uuid('returned_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('work_order_removed_component_id')->references('id')->on('work_order_removed_components')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->foreign('stock_movement_id')->references('id')->on('stock_movements')->nullOnDelete();
            $table->index(['tenant_id', 'work_order_removed_component_id']);
        });

        Schema::create('work_order_removed_component_evidence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('work_order_removed_component_id');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->uuid('uploaded_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('work_order_removed_component_id')->references('id')->on('work_order_removed_components')->cascadeOnDelete();
            $table->index(['tenant_id', 'work_order_removed_component_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_removed_component_evidence');
        Schema::dropIfExists('work_order_removed_component_returns');
        Schema::dropIfExists('work_order_removed_components');

        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_movement_type_check');
        DB::statement(
            'ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_movement_type_check '.
            'CHECK (movement_type::text = ANY (ARRAY['.
            "'OPENING','RECEIPT','RESERVATION','RELEASE_RESERVATION','ISSUE','RETURN',".
            "'TRANSFER_OUT','TRANSFER_IN','ADJUSTMENT_PLUS','ADJUSTMENT_MINUS','STOCK_OPNAME','SCRAP','CONSUME','SALE'".
            ']::character varying[]))'
        );
    }
};
