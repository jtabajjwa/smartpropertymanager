<?php

use App\Services\StandardLeaseTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives every existing landlord the standard tenancy agreement. New landlords
 * get it when they sign up or are created by staff. Safe to re-run: landlords
 * who already have it are skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        $installer = app(StandardLeaseTemplate::class);

        foreach (DB::table('landlords')->pluck('id') as $landlordId) {
            $installer->installFor((int) $landlordId);
        }
    }

    public function down(): void
    {
        // Only remove untouched copies; anything a landlord has edited stays.
        DB::table('lease_templates')
            ->where('name', StandardLeaseTemplate::NAME)
            ->where('content', StandardLeaseTemplate::content())
            ->delete();
    }
};
