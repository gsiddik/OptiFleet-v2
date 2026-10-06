<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * i18n rollout (notifications): the language each delivery is rendered in — the recipient's resolved
 * locale at dispatch time (user preference → tenant default → en). Nullable and additive: rows queued
 * before this column existed resolve the recipient's locale when they are sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('notification_delivery_logs', 'locale')) {
            Schema::table('notification_delivery_logs', fn (Blueprint $table) => $table->string('locale', 5)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('notification_delivery_logs', 'locale')) {
            Schema::table('notification_delivery_logs', fn (Blueprint $table) => $table->dropColumn('locale'));
        }
    }
};
