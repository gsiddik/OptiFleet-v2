<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Component Groups are soft-delete only. Every foreign key that points at
 * component_groups from a Product, mapping or transaction table previously
 * used ON DELETE CASCADE or SET NULL — so an accidental physical delete would
 * silently erase or orphan history. They become ON DELETE NO ACTION: a
 * referenced group can never be physically removed on its own, and soft
 * delete (which the application always uses) is unaffected. NO ACTION rather
 * than RESTRICT because it is checked at end of statement, so a whole-tenant
 * teardown (tenants -> ... cascades that also remove the referencing rows)
 * still succeeds. The self-referencing parent_id
 * key is left as is (it points at another master row, not at history).
 */
return new class extends Migration
{
    /** Tables whose key was ON DELETE CASCADE before this migration; the rest were SET NULL. */
    private const PREVIOUSLY_CASCADE = [
        'vehicle_category_component_groups',
        'product_component_groups',
        'product_compatibilities',
        'maintenance_package_component_groups',
        'worker_skills',
    ];

    public function up(): void
    {
        foreach ($this->foreignKeys() as $fk) {
            $this->recreate($fk, 'NO ACTION');
        }
    }

    public function down(): void
    {
        foreach ($this->foreignKeys() as $fk) {
            $this->recreate($fk, in_array($fk->table_name, self::PREVIOUSLY_CASCADE, true) ? 'CASCADE' : 'SET NULL');
        }
    }

    private function foreignKeys(): array
    {
        return DB::select(<<<'SQL'
            SELECT c.conname AS constraint_name, t.relname AS table_name, a.attname AS column_name
            FROM pg_constraint c
            JOIN pg_class t ON t.oid = c.conrelid
            JOIN pg_class r ON r.oid = c.confrelid
            JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
            WHERE c.contype = 'f' AND r.relname = 'component_groups' AND t.relname <> 'component_groups'
            SQL);
    }

    private function recreate(object $fk, string $onDelete): void
    {
        DB::statement(sprintf('ALTER TABLE "%s" DROP CONSTRAINT "%s"', $fk->table_name, $fk->constraint_name));
        DB::statement(sprintf(
            'ALTER TABLE "%s" ADD CONSTRAINT "%s" FOREIGN KEY ("%s") REFERENCES component_groups (id) ON DELETE %s',
            $fk->table_name,
            $fk->constraint_name,
            $fk->column_name,
            $onDelete
        ));
    }
};
