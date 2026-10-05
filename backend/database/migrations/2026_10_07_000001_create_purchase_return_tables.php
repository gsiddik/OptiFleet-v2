<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Purchase Order Return to Vendor:
 *
 *  purchase_returns         one Return Order (server-numbered) of a Purchase Order — 1:N per PO;
 *                           option REFUND or REDELIVERY and its explicit state:
 *                             REFUND      REFUND_REQUESTED → REFUND_ACCEPTED
 *                                                          → REDELIVERY_PENDING (vendor rejected) → REDELIVERY_RECEIVED
 *                             REDELIVERY  REDELIVERY_REQUESTED → REDELIVERY_READY (Return Order printed) → REDELIVERY_RECEIVED
 *                           at most one open (non-final) Return Order per PO at a time
 *  purchase_return_items    returned quantity per PO line (+ refund amount once accepted)
 *  purchase_return_events   append-only state history (a rejected refund keeps its request)
 *
 * purchase_order_items gets quantity_returned (goods sent back) and quantity_refunded (refund
 * requested / accepted — no longer expected from the vendor), so the remaining receivable
 * quantity is ordered − (received − returned) − refunded. Stock leaves the warehouse with a
 * RETURN_TO_VENDOR movement.
 */
return new class extends Migration
{
    private const OPEN = ['REFUND_REQUESTED', 'REDELIVERY_REQUESTED', 'REDELIVERY_READY', 'REDELIVERY_PENDING'];

    private const STATUSES = ['REFUND_REQUESTED', 'REFUND_ACCEPTED', 'REDELIVERY_REQUESTED', 'REDELIVERY_READY', 'REDELIVERY_PENDING', 'REDELIVERY_RECEIVED'];

    private const PERMISSIONS = [
        'purchase_return.create' => ['goods_receipt.post', 'Return received Purchase Order goods to the vendor'],
        'purchase_return.decide' => ['purchase_order.approve', 'Record the vendor\'s decision on a refund request'],
        'purchase_return.receive_redelivery' => ['goods_receipt.post', 'Receive the vendor\'s redelivery of returned goods'],
    ];

    private const MOVEMENT_TYPES = "'OPENING','RECEIPT','RESERVATION','RELEASE_RESERVATION','ISSUE','RETURN',"
        ."'TRANSFER_OUT','TRANSFER_IN','ADJUSTMENT_PLUS','ADJUSTMENT_MINUS','STOCK_OPNAME','SCRAP','CONSUME','SALE',"
        ."'REMOVED_COMPONENT_RETURN'";

    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('return_number');
            $table->uuid('numbering_configuration_version_id')->nullable();
            $table->uuid('purchase_order_id');
            $table->uuid('partner_id');
            $table->uuid('warehouse_id');
            $table->string('return_option', 12);
            $table->string('status', 24);
            $table->timestamp('returned_at');
            $table->decimal('refunded_amount', 16, 4)->nullable();
            $table->string('vendor_decision', 10)->nullable();
            $table->uuid('vendor_decided_by')->nullable();
            $table->timestamp('vendor_decided_at')->nullable();
            $table->text('vendor_decision_note')->nullable();
            $table->uuid('printed_by')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->uuid('redelivery_received_by')->nullable();
            $table->timestamp('redelivery_received_at')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->restrictOnDelete();
            $table->foreign('partner_id')->references('id')->on('partners')->restrictOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->unique(['tenant_id', 'return_number']);
            $table->index(['purchase_order_id', 'returned_at']);
        });
        DB::statement("ALTER TABLE purchase_returns ADD CONSTRAINT purchase_returns_option_check CHECK (return_option IN ('REFUND', 'REDELIVERY'))");
        DB::statement("ALTER TABLE purchase_returns ADD CONSTRAINT purchase_returns_status_check CHECK (status IN ('".implode("','", self::STATUSES)."'))");
        DB::statement("ALTER TABLE purchase_returns ADD CONSTRAINT purchase_returns_decision_check CHECK (vendor_decision IS NULL OR vendor_decision IN ('ACCEPTED', 'REJECTED'))");
        // A refund amount exists only for an accepted refund.
        DB::statement("ALTER TABLE purchase_returns ADD CONSTRAINT purchase_returns_refund_check CHECK ((status = 'REFUND_ACCEPTED') = (refunded_amount IS NOT NULL))");
        // One open Return Order per PO at a time (concurrent "Return to Vendor" cannot open two).
        DB::statement("CREATE UNIQUE INDEX purchase_returns_one_open ON purchase_returns (purchase_order_id) WHERE status IN ('".implode("','", self::OPEN)."')");

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('purchase_return_id');
            $table->uuid('purchase_order_item_id');
            $table->uuid('product_id');
            $table->decimal('quantity', 16, 4);
            $table->decimal('refund_amount', 16, 4)->nullable();
            $table->timestamps();

            $table->foreign('purchase_return_id')->references('id')->on('purchase_returns')->cascadeOnDelete();
            $table->foreign('purchase_order_item_id')->references('id')->on('purchase_order_items')->restrictOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->unique(['purchase_return_id', 'purchase_order_item_id']);
        });
        DB::statement('ALTER TABLE purchase_return_items ADD CONSTRAINT purchase_return_items_quantity_check CHECK (quantity > 0)');

        Schema::create('purchase_return_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('purchase_return_id');
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('event', 30);
            $table->text('note')->nullable();
            $table->uuid('performed_by')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign('purchase_return_id')->references('id')->on('purchase_returns')->cascadeOnDelete();
            $table->index(['purchase_return_id', 'occurred_at']);
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->decimal('quantity_returned', 16, 4)->default(0);
            $table->decimal('quantity_refunded', 16, 4)->default(0);
        });
        DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT purchase_order_items_return_check CHECK (quantity_returned >= 0 AND quantity_refunded >= 0 AND quantity_refunded <= quantity_returned AND quantity_returned <= quantity_received)');

        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_movement_type_check');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_movement_type_check CHECK (movement_type::text = ANY (ARRAY['.self::MOVEMENT_TYPES.",'RETURN_TO_VENDOR']::character varying[]))");

        foreach (self::PERMISSIONS as $name => [$source, $description]) {
            $this->grant($name, $source, $description);
        }
    }

    private function grant(string $name, string $source, string $description): void
    {
        $id = DB::table('permissions')->where('name', $name)->where('scope', 'tenant')->value('id');
        if ($id === null) {
            $id = (string) Str::uuid();
            DB::table('permissions')->insert([
                'id' => $id, 'name' => $name, 'group' => Str::before($name, '.'), 'scope' => 'tenant',
                'description' => $description, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $sourceId = DB::table('permissions')->where('name', $source)->where('scope', 'tenant')->value('id');
        if ($sourceId === null) {
            return;
        }
        $granted = DB::table('role_permissions')->where('permission_id', $id)->pluck('role_id')->all();
        $rows = DB::table('role_permissions')->where('permission_id', $sourceId)->distinct()->pluck('role_id')
            ->reject(fn ($roleId) => in_array($roleId, $granted, true))
            ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $id, 'created_at' => now(), 'updated_at' => now()])
            ->values()->all();
        if ($rows !== []) {
            DB::table('role_permissions')->insert($rows);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_movement_type_check');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_movement_type_check CHECK (movement_type::text = ANY (ARRAY['.self::MOVEMENT_TYPES.']::character varying[]))');
        DB::statement('ALTER TABLE purchase_order_items DROP CONSTRAINT IF EXISTS purchase_order_items_return_check');
        Schema::table('purchase_order_items', fn (Blueprint $t) => $t->dropColumn(['quantity_returned', 'quantity_refunded']));
        Schema::dropIfExists('purchase_return_events');
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
        foreach (array_keys(self::PERMISSIONS) as $name) {
            $id = DB::table('permissions')->where('name', $name)->value('id');
            if ($id !== null) {
                DB::table('role_permissions')->where('permission_id', $id)->delete();
                DB::table('permissions')->where('id', $id)->delete();
            }
        }
    }
};
