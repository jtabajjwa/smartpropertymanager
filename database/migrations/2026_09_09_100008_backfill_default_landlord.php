<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected array $scopedTables = [
        'locations', 'estates', 'units',
        'tenants', 'customers', 'leases',
        'invoices', 'payments', 'payment_allocations', 'security_deposit_ledgers',
        'expenses', 'expense_items', 'utilities',
        'users', 'sms_logs', 'audits',
    ];

    protected array $numberedTables = [
        'leases' => 'lease',
        'invoices' => 'invoice',
        'tenants' => 'tenant',
        'payments' => 'payment',
        'expenses' => 'expense',
    ];

    public function up(): void
    {
        $landlordId = DB::table('landlords')->where('contact_email', 'default@smartpropertymanager.local')->value('id');

        if (!$landlordId) {
            $landlordId = DB::table('landlords')->insertGetId([
                'name' => config('app.name', 'Default Landlord'),
                'contact_email' => 'default@smartpropertymanager.local',
                'contact_phone' => '0000000000',
                'status' => 'active',
                'subscription_status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($this->scopedTables as $tableName) {
            DB::table($tableName)->whereNull('landlord_id')->update(['landlord_id' => $landlordId]);
        }

        foreach ($this->numberedTables as $tableName => $sequenceKey) {
            $maxId = (int) DB::table($tableName)->max('id');

            DB::table('landlord_number_sequences')->updateOrInsert(
                ['landlord_id' => $landlordId, 'sequence_key' => $sequenceKey],
                ['next_number' => $maxId + 1, 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: backfilled landlord_id values cannot be
        // unambiguously un-assigned without losing information.
    }
};
