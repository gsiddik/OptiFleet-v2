<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Vendor invoice payment (full settlement): a payment belongs to the INVOICE, never to a single
 * Goods Receipt row — an invoice shared by several receipts is paid once. The unique key on
 * vendor_invoice_reference_id makes "zero or one payment per invoice" a database guarantee
 * (double submit / concurrent requests cannot create a second payment).
 *
 * Permissions: vendor_invoice.pay is granted to the roles that managed invoice references
 * before (goods_receipt.create). goods_receipt.create has no remaining use once its last route
 * (the retired RECEIVED/VERIFIED/DISPUTED flag) is gone, so it is removed; stored invoice data is
 * untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_invoice_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('vendor_invoice_reference_id');
            $table->date('payment_date');
            $table->decimal('amount', 16, 4);
            $table->string('proof_disk');
            $table->string('proof_path');
            $table->string('proof_original_name')->nullable();
            $table->string('proof_mime_type', 100)->nullable();
            $table->unsignedBigInteger('proof_size')->nullable();
            $table->uuid('paid_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('vendor_invoice_reference_id')->references('id')->on('vendor_invoice_references')->restrictOnDelete();
            $table->foreign('paid_by')->references('id')->on('users')->nullOnDelete();
            $table->unique('vendor_invoice_reference_id');
            $table->index(['tenant_id', 'payment_date']);
        });

        $this->grantPay();
    }

    private function grantPay(): void
    {
        $id = DB::table('permissions')->where('name', 'vendor_invoice.pay')->where('scope', 'tenant')->value('id');
        if ($id === null) {
            $id = (string) Str::uuid();
            DB::table('permissions')->insert([
                'id' => $id, 'name' => 'vendor_invoice.pay', 'group' => 'vendor_invoice', 'scope' => 'tenant',
                'description' => 'Record vendor invoice payments',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $legacy = DB::table('permissions')->where('name', 'goods_receipt.create')->where('scope', 'tenant')->value('id');
        if ($legacy === null) {
            return;
        }
        $granted = DB::table('role_permissions')->where('permission_id', $id)->pluck('role_id')->all();
        $rows = DB::table('role_permissions')->where('permission_id', $legacy)->distinct()->pluck('role_id')
            ->reject(fn ($roleId) => in_array($roleId, $granted, true))
            ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $id, 'created_at' => now(), 'updated_at' => now()])
            ->values()->all();
        if ($rows !== []) {
            DB::table('role_permissions')->insert($rows);
        }
        DB::table('role_permissions')->where('permission_id', $legacy)->delete();
        DB::table('permissions')->where('id', $legacy)->delete();
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_invoice_payments');
        if (! DB::table('permissions')->where('name', 'goods_receipt.create')->exists()) {
            DB::table('permissions')->insert([
                'id' => (string) Str::uuid(), 'name' => 'goods_receipt.create', 'group' => 'goods_receipt', 'scope' => 'tenant',
                'description' => 'goods receipt create', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $pay = DB::table('permissions')->where('name', 'vendor_invoice.pay')->value('id');
        if ($pay !== null) {
            DB::table('role_permissions')->where('permission_id', $pay)->delete();
            DB::table('permissions')->where('id', $pay)->delete();
        }
    }
};
