<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // Which platform entry point the contract was drafted from
            // (Section 14 of the Perbaikan OptiFleet spec). Every contract
            // created before this column existed was created from Contract
            // Management, since Tenant Management had no contract-creation
            // capability yet — hence the backfill default below.
            $table->enum('source_context', ['CONTRACT_MANAGEMENT', 'TENANT_MANAGEMENT'])
                ->default('CONTRACT_MANAGEMENT')
                ->after('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('source_context');
        });
    }
};
