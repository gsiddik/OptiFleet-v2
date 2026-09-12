<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G-05: previously dispatch()/receive() persisted only a timestamp — the
 * actor who dispatched or received the transfer was never recorded, even
 * though both service methods already receive a $userId.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->uuid('dispatched_by')->nullable()->after('dispatched_at');
            $table->uuid('received_by')->nullable()->after('received_at');
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropColumn(['dispatched_by', 'received_by']);
        });
    }
};
