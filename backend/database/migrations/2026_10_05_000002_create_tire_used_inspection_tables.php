<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Used Tire Management inspection (Removed / Hold → REUSE / REPAIR / RETREAD / HOLD / SCRAP):
 *
 *  tire_rule_profiles                configurable thresholds per tenant + tire category (+ optional
 *                                    tire product = brand/model, + optional application); versioned
 *  tire_used_inspections             one inspection: questionnaire answers, tread result, the rule
 *                                    profile version and thresholds applied (snapshot), the decision
 *                                    (recommendation, reasons, C/X/R/P/T/K/F) and its approval
 *  tire_used_inspection_measurements individual tread depth points (≥ 6 per inspection)
 *  tire_used_inspection_damages      one row per damage
 *  tire_used_inspection_evidence     photos (private disk)
 *
 * Permissions: tire_used_inspection.approve (granted to roles that approve retread cycles) and
 * tire_rule_profile.manage (granted to roles that manage tire scoring configuration).
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'tire_used_inspection.approve' => ['tire_retread.approve', 'Approve used tire inspection dispositions'],
        'tire_rule_profile.manage' => ['tire_scoring_configuration.manage', 'Manage used tire inspection rule profiles'],
    ];

    public function up(): void
    {
        Schema::create('tire_rule_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 150);
            $table->string('tire_category', 20);
            $table->uuid('product_id')->nullable();
            $table->string('application', 50)->nullable();
            $table->decimal('d_service_mm', 5, 2);
            $table->decimal('d_pull_mm', 5, 2);
            $table->unsignedSmallInteger('a_max_months');
            $table->unsignedSmallInteger('a_retread_max_months');
            $table->unsignedSmallInteger('n_retread_max');
            $table->jsonb('repair_limits');
            $table->jsonb('application_limits');
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 10)->default('ACTIVE');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->index(['tenant_id', 'tire_category', 'status']);
        });
        DB::statement("ALTER TABLE tire_rule_profiles ADD CONSTRAINT tire_rule_profiles_category_check CHECK (tire_category IN ('PASSENGER_LT', 'TRUCK_BUS', 'OTR'))");
        DB::statement("ALTER TABLE tire_rule_profiles ADD CONSTRAINT tire_rule_profiles_status_check CHECK (status IN ('ACTIVE', 'INACTIVE'))");
        DB::statement('ALTER TABLE tire_rule_profiles ADD CONSTRAINT tire_rule_profiles_depth_check CHECK (d_service_mm > 0 AND d_pull_mm >= d_service_mm)');
        DB::statement('ALTER TABLE tire_rule_profiles ADD CONSTRAINT tire_rule_profiles_age_check CHECK (a_max_months > 0 AND a_retread_max_months > 0)');
        // One active profile per category + product + application (product / application optional).
        DB::statement("CREATE UNIQUE INDEX tire_rule_profiles_active_unique ON tire_rule_profiles (tenant_id, tire_category, coalesce(product_id::text, ''), coalesce(lower(application), '')) WHERE status = 'ACTIVE'");

        Schema::create('tire_used_inspections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('tire_id');
            $table->string('status', 12)->default('SUBMITTED');
            $table->string('tire_status_before', 20);
            $table->uuid('inspected_by')->nullable();
            $table->timestamp('inspected_at');
            $table->string('tire_category', 20)->nullable();
            $table->string('application', 50)->nullable();
            $table->uuid('rule_profile_id')->nullable();
            $table->unsignedInteger('rule_profile_version')->nullable();
            $table->jsonb('thresholds')->nullable();
            $table->jsonb('tire_snapshot');
            // Questionnaire (one column per question; codes are CHECK-constrained below).
            $table->string('identity_status', 20);
            $table->string('internal_inspected', 10);
            $table->string('wear_pattern', 20);
            $table->string('bulge_separation', 15);
            $table->string('cord_exposure', 15);
            $table->string('sidewall_condition', 20);
            $table->string('bead_condition', 20);
            $table->string('inner_liner_condition', 25);
            $table->string('run_flat_overheat', 20);
            $table->string('leak_foreign_object', 12);
            $table->string('previous_repair', 15);
            $table->string('age_chemical', 15);
            $table->string('casing_compliance', 15);
            $table->string('repair_eligibility', 20)->nullable();
            $table->string('specialist_result', 15)->nullable();
            // Tread result.
            $table->decimal('d_min_mm', 5, 2)->nullable();
            $table->decimal('d_new_mm', 5, 2)->nullable();
            $table->decimal('remaining_tread_percent', 5, 2)->nullable();
            // Decision.
            $table->string('recommendation', 10);
            $table->string('recommendation_detail', 30)->nullable();
            $table->string('additional_work', 20)->nullable();
            $table->jsonb('reasons');
            $table->jsonb('follow_ups');
            $table->jsonb('variables');
            $table->text('notes')->nullable();
            // Approval.
            $table->string('final_disposition', 10)->nullable();
            $table->uuid('return_warehouse_id')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_note')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->uuid('tire_inspection_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('tire_id')->references('id')->on('tires')->cascadeOnDelete();
            $table->foreign('rule_profile_id')->references('id')->on('tire_rule_profiles')->nullOnDelete();
            $table->foreign('return_warehouse_id')->references('id')->on('warehouses')->nullOnDelete();
            $table->foreign('tire_inspection_id')->references('id')->on('tire_inspections')->nullOnDelete();
            $table->index(['tenant_id', 'tire_id', 'inspected_at']);
        });
        $checks = [
            'status' => ['SUBMITTED', 'APPROVED', 'CANCELLED'],
            'identity_status' => ['COMPLETE', 'PARTIALLY_UNKNOWN', 'CANNOT_VERIFY'],
            'internal_inspected' => ['YES', 'NOT_YET'],
            'wear_pattern' => ['EVEN', 'ONE_SIDED', 'CENTER', 'BOTH_SIDES', 'CUPPING_SCALLOPING', 'FLAT_SPOT', 'NOT_INSPECTED'],
            'bulge_separation' => ['NONE', 'PRESENT', 'SUSPECTED', 'NOT_INSPECTED'],
            'cord_exposure' => ['NONE', 'PRESENT', 'SUSPECTED', 'NOT_INSPECTED'],
            'sidewall_condition' => ['NORMAL', 'SURFACE_ABRASION', 'SURFACE_CRACKING', 'DEEP_CUT_CRACK', 'NOT_INSPECTED'],
            'bead_condition' => ['NORMAL', 'MINOR_ABRASION', 'TORN', 'DEFORMED', 'BEAD_WIRE_DAMAGED', 'NOT_INSPECTED'],
            'inner_liner_condition' => ['NORMAL', 'LOCAL_DAMAGE', 'CRACKED_DELAMINATED', 'WRINKLED_HEAT_DAMAGE', 'CORD_EXPOSED', 'NOT_INSPECTED'],
            'run_flat_overheat' => ['NO', 'HISTORY_NO_SIGN', 'PHYSICAL_SIGN', 'UNKNOWN'],
            'leak_foreign_object' => ['NO', 'YES', 'NOT_TESTED'],
            'previous_repair' => ['NONE', 'MEETS_STANDARD', 'QUESTIONABLE', 'DOES_NOT_MEET', 'NOT_INSPECTED'],
            'age_chemical' => ['NONE', 'SUSPECTED', 'DEGRADED', 'NOT_INSPECTED'],
            'casing_compliance' => ['MEETS', 'DOES_NOT_MEET', 'CANNOT_CONFIRM'],
            'repair_eligibility' => ['YES', 'NO', 'SPECIALIST_REQUIRED'],
            'specialist_result' => ['NOT_REQUESTED', 'PENDING', 'ACCEPTED', 'REJECTED'],
            'recommendation' => ['REUSE', 'REPAIR', 'RETREAD', 'SCRAP', 'HOLD'],
            'final_disposition' => ['REUSE', 'REPAIR', 'RETREAD', 'SCRAP', 'HOLD'],
        ];
        foreach ($checks as $column => $values) {
            $list = "'".implode("','", $values)."'";
            DB::statement("ALTER TABLE tire_used_inspections ADD CONSTRAINT tui_{$column}_check CHECK ({$column} IS NULL OR {$column} IN ({$list}))");
        }
        DB::statement("ALTER TABLE tire_used_inspections ADD CONSTRAINT tui_approval_check CHECK ((status = 'APPROVED') = (final_disposition IS NOT NULL AND approved_at IS NOT NULL))");
        // One open (submitted, not yet approved) inspection per tire.
        DB::statement("CREATE UNIQUE INDEX tire_used_inspections_one_open ON tire_used_inspections (tire_id) WHERE status = 'SUBMITTED'");

        Schema::create('tire_used_inspection_measurements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('tire_used_inspection_id');
            $table->unsignedSmallInteger('zone');
            $table->string('groove', 12);
            $table->decimal('depth_mm', 5, 2);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('tire_used_inspection_id')->references('id')->on('tire_used_inspections')->cascadeOnDelete();
            $table->unique(['tire_used_inspection_id', 'zone', 'groove'], 'tuim_point_unique');
        });
        DB::statement("ALTER TABLE tire_used_inspection_measurements ADD CONSTRAINT tuim_point_check CHECK (zone BETWEEN 1 AND 3 AND groove IN ('INNER_MAIN', 'OUTER_MAIN', 'CENTER') AND depth_mm >= 0)");

        Schema::create('tire_used_inspection_damages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('tire_used_inspection_id');
            $table->unsignedSmallInteger('sequence');
            $table->string('location', 15);
            $table->string('damage_type', 25);
            $table->decimal('diameter_mm', 6, 2)->nullable();
            $table->decimal('length_mm', 6, 2)->nullable();
            $table->decimal('width_mm', 6, 2)->nullable();
            $table->decimal('depth_mm', 6, 2)->nullable();
            $table->string('reaches_reinforcement', 10);
            $table->string('overlaps_previous_repair', 10);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('tire_used_inspection_id')->references('id')->on('tire_used_inspections')->cascadeOnDelete();
            $table->unique(['tire_used_inspection_id', 'sequence'], 'tuid_sequence_unique');
        });
        DB::statement("ALTER TABLE tire_used_inspection_damages ADD CONSTRAINT tuid_codes_check CHECK (
            location IN ('TREAD', 'SHOULDER', 'SIDEWALL', 'BEAD', 'INNER_LINER')
            AND damage_type IN ('PUNCTURE', 'CUT', 'CRACK', 'ABRASION', 'SEPARATION', 'PREVIOUS_REPAIR_DAMAGE', 'OTHER')
            AND reaches_reinforcement IN ('NO', 'YES', 'UNKNOWN') AND overlaps_previous_repair IN ('NO', 'YES', 'UNKNOWN'))");

        Schema::create('tire_used_inspection_evidence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('tire_used_inspection_id');
            $table->uuid('tire_used_inspection_damage_id')->nullable();
            $table->string('kind', 20);
            $table->string('disk', 20);
            $table->string('path');
            $table->string('original_filename', 200);
            $table->string('mime_type', 50);
            $table->unsignedInteger('size');
            $table->text('notes')->nullable();
            $table->uuid('uploaded_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('tire_used_inspection_id')->references('id')->on('tire_used_inspections')->cascadeOnDelete();
            $table->foreign('tire_used_inspection_damage_id', 'tuie_damage_fk')->references('id')->on('tire_used_inspection_damages')->nullOnDelete();
        });
        DB::statement("ALTER TABLE tire_used_inspection_evidence ADD CONSTRAINT tuie_kind_check CHECK (kind IN ('DAMAGE_PHOTO', 'CLOSE_UP_SCALE'))");

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
        Schema::dropIfExists('tire_used_inspection_evidence');
        Schema::dropIfExists('tire_used_inspection_damages');
        Schema::dropIfExists('tire_used_inspection_measurements');
        Schema::dropIfExists('tire_used_inspections');
        Schema::dropIfExists('tire_rule_profiles');
        foreach (array_keys(self::PERMISSIONS) as $name) {
            $id = DB::table('permissions')->where('name', $name)->value('id');
            if ($id !== null) {
                DB::table('role_permissions')->where('permission_id', $id)->delete();
                DB::table('permissions')->where('id', $id)->delete();
            }
        }
    }
};
