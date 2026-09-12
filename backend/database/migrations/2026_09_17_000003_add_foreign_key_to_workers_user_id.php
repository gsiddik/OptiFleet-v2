<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G-14: workers.user_id has existed since Phase 4 ("optional link to a
 * login account") but was never given a foreign key, a model relation, or
 * any controller/route to set it — it was fully dead. This adds the
 * missing constraint; the application-level link/unlink endpoint and
 * relation are added alongside this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
    }
};
