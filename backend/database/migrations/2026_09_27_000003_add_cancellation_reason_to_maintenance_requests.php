<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 6 (Improvement OptiFleet - Maintenance Request dan Work Order): cancelling a
 * Draft request requires the user to supply a reason via a confirmation popup. There was
 * no column to persist it — `review_note` is reserved for the Approve/Reject workflow
 * step (MaintenanceRequestService::transition only sets it for APPROVED/REJECTED).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->text('cancellation_reason')->nullable()->after('review_note');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->dropColumn('cancellation_reason');
        });
    }
};
