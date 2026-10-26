<?php

use App\Support\StaffPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-staff permissions for the staff portal. Admins have everything
 * implicitly; this column only matters for non-admin staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_users', function (Blueprint $table) {
            $table->json('permissions')->nullable()->after('role');
        });

        // Existing non-admin staff could do everything except manage staff;
        // keep that, so nobody is locked out. Trim afterwards as needed.
        DB::table('staff_users')->where('role', '!=', 'admin')
            ->update(['permissions' => json_encode(StaffPermissions::all())]);
    }

    public function down(): void
    {
        Schema::table('staff_users', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }
};
