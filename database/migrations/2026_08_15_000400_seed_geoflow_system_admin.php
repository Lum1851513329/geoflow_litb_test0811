<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Seeds a permanent, non-superadmin system Admin actor (`geoflow-system`) used by
 * autonomous/code-driven flows (later tasks) that need an `Admin $actor` but have no
 * human operator behind the action. Deliberately `role='system'` — Admin::isSuperAdmin()
 * only matches 'super_admin'/'superadmin', so this actor never gains the superadmin bypass.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admins')) {
            return;
        }

        $exists = DB::table('admins')->where('username', 'geoflow-system')->exists();
        if ($exists) {
            return;
        }

        DB::table('admins')->insert([
            'username' => 'geoflow-system',
            'password' => Hash::make(Str::random(64)),
            // `email` is NOT NULL with schema default '' (no unique constraint on this
            // column) — match the schema default rather than passing null.
            'email' => '',
            'display_name' => 'GEOFlow System',
            'role' => 'system',
            'status' => 'active',
            'auth_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('admins')) {
            DB::table('admins')->where('username', 'geoflow-system')->delete();
        }
    }
};
