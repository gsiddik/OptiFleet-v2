<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 14 image-URL sweep: `products.image_url` is a plain text field —
 * the one remaining "Image URL" edit box in the repository, everywhere
 * else already converted to a real upload (Tenant Logo, Vehicle Brand
 * Logo, Vehicle Photo, Work Order Return/Removed-Component evidence,
 * Consumable SDS). A Product image is tenant-internal catalog data (like
 * Vehicle Brand Logo / Vehicle Photo), not public branding (unlike Tenant
 * Logo, which deliberately needs an unauthenticated public URL for the
 * favicon/pre-login paint) — so it belongs on the private `local` disk,
 * served only through an authenticated controller action, not a public
 * URL written into `image_url` the way TenantLogoService does for Tenant.
 *
 * `image_url` itself is left untouched (existing rows with a real
 * external URL keep rendering exactly as before) — these two columns are
 * purely additive, populated only once a tenant actually uploads a
 * replacement image through the new flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('image_url');
            $table->string('image_original_filename')->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['image_path', 'image_original_filename']);
        });
    }
};
