<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E (G-29/G-32/G-33/G-36): a retread cycle previously only recorded
 * sent_at/received_at with no actor trail and no gate between "received"
 * and "back in stock" — receiveRetread() set the tire straight to
 * IN_STOCK. This adds the full send -> receive -> final inspect -> approve
 * governance trail, so a tire only returns to available stock after an
 * explicit final inspection (with a critical-safety verdict) and an
 * approval decision — recorded with its reason — by an actor distinct
 * from the receiver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tire_retreads', function (Blueprint $table) {
            $table->uuid('sent_by')->nullable()->after('sent_at');
            $table->uuid('received_by')->nullable()->after('received_at');
            $table->enum('status', ['SENT', 'RECEIVED', 'FINAL_INSPECTED', 'APPROVED', 'REJECTED'])->default('SENT')->after('notes');
            $table->uuid('final_inspected_by')->nullable()->after('status');
            $table->timestamp('final_inspected_at')->nullable()->after('final_inspected_by');
            $table->enum('final_inspection_result', ['SAFE', 'UNSAFE'])->nullable()->after('final_inspected_at');
            $table->text('final_inspection_notes')->nullable()->after('final_inspection_result');
            $table->uuid('approved_by')->nullable()->after('final_inspection_notes');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->enum('approval_disposition', ['RETURN_TO_SERVICE', 'SCRAP', 'QUARANTINE'])->nullable()->after('approved_at');
            $table->text('approval_reason')->nullable()->after('approval_disposition');
        });
    }

    public function down(): void
    {
        Schema::table('tire_retreads', function (Blueprint $table) {
            $table->dropColumn([
                'sent_by', 'received_by', 'status', 'final_inspected_by', 'final_inspected_at',
                'final_inspection_result', 'final_inspection_notes', 'approved_by', 'approved_at',
                'approval_disposition', 'approval_reason',
            ]);
        });
    }
};
