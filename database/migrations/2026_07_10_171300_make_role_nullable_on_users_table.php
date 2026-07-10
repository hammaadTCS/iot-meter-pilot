<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Transitional step for the hybrid access-control migration
     * (docs/FGAC_IMPLEMENTATION_PLAN.md, Phase 1): accounts created after
     * the Spatie cutover no longer carry a role, so the legacy enum must
     * accept NULL until drop_role_from_users_table removes it in Phase 7.
     */
    public function up(): void
    {
        // SQLite stores enums as TEXT with a CHECK constraint and does not
        // support ALTER COLUMN — inserts there fall back to the column
        // default, so no DDL change is needed for the test environment.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role ENUM('super_admin', 'admin', 'user') NULL DEFAULT 'user'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("UPDATE users SET role = 'user' WHERE role IS NULL");
            DB::statement("ALTER TABLE users MODIFY role ENUM('super_admin', 'admin', 'user') NOT NULL DEFAULT 'user'");
        }
    }
};
