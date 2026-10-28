<?php

use App\Support\UnitCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every landlord gets a permanent number (100, 101, …) and every unit a
 * platform-wide unique, digits-only ID built from it (see UnitCode). Tenants
 * sign in with the unit ID, e.g. on USSD, instead of a unit name that other
 * landlords may also use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landlords', function (Blueprint $table) {
            $table->unsignedInteger('number')->nullable()->unique()->after('id');
        });
        Schema::table('units', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->unique()->after('name');
        });

        $number = UnitCode::FIRST_LANDLORD_NUMBER;
        foreach (DB::table('landlords')->orderBy('id')->pluck('id') as $landlordId) {
            DB::table('landlords')->where('id', $landlordId)->update(['number' => $number]);

            $seq = 0;
            foreach (DB::table('units')->where('landlord_id', $landlordId)->orderBy('id')->pluck('id') as $unitId) {
                do {
                    $code = UnitCode::make($number, ++$seq);
                } while (DB::table('units')->where('code', $code)->exists());
                DB::table('units')->where('id', $unitId)->update(['code' => $code]);
            }

            DB::table('landlord_number_sequences')->updateOrInsert(
                ['landlord_id' => $landlordId, 'sequence_key' => UnitCode::SEQUENCE_KEY],
                ['next_number' => $seq + 1, 'created_at' => now(), 'updated_at' => now()]
            );
            $number++;
        }
    }

    public function down(): void
    {
        DB::table('landlord_number_sequences')->where('sequence_key', UnitCode::SEQUENCE_KEY)->delete();
        Schema::table('units', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
        Schema::table('landlords', function (Blueprint $table) {
            $table->dropUnique(['number']);
            $table->dropColumn('number');
        });
    }
};
