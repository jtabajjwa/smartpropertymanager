<?php

use App\Support\Places;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Locations are gone: an estate has its own address, district and country
 * (with a map pin for where it is). Each estate takes its location's
 * details, then the locations table is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Each step checks first: MariaDB can't roll back schema changes, so a
        // run that stopped part-way must be able to finish when run again.
        if (!Schema::hasColumn('estates', 'address')) {
            Schema::table('estates', function (Blueprint $table) {
                $table->string('address')->nullable()->after('description');
                $table->string('district', 100)->nullable()->after('address');
                $table->string('country', 60)->default(Places::DEFAULT_COUNTRY)->after('district');
            });
        }
        if (!Schema::hasTable('locations') || !Schema::hasColumn('estates', 'location_id')) {
            $this->dropLocations();

            return;
        }

        $locations = DB::table('locations')->get()->keyBy('id');
        foreach (DB::table('estates')->get(['id', 'location_id']) as $estate) {
            $location = $locations[$estate->location_id] ?? null;
            if (!$location) {
                continue;
            }

            // "Kololo" + "Plot 1, Acacia Avenue" → "Kololo, Plot 1, Acacia Avenue" (unless the address already names the area).
            $name = trim((string) $location->name);
            $address = trim((string) $location->address);
            $line = $name !== '' && stripos($address, $name) === false ? trim("{$name}, {$address}", ', ') : $address;

            $country = trim((string) $location->country) ?: Places::DEFAULT_COUNTRY;
            $district = trim((string) $location->district);
            if (strcasecmp($country, 'Uganda') === 0) {
                $country = 'Uganda';
                $district = Places::ugandaDistrict($district) ?? $district;
            }

            DB::table('estates')->where('id', $estate->id)->update([
                'address' => $line !== '' ? mb_substr($line, 0, 255) : null,
                'district' => $district !== '' ? mb_substr($district, 0, 100) : null,
                'country' => mb_substr($country, 0, 60),
            ]);
        }

        $this->dropLocations();
    }

    protected function dropLocations(): void
    {
        if (Schema::hasColumn('estates', 'location_id')) {
            $hasForeignKey = collect(Schema::getForeignKeys('estates'))->contains(fn ($fk) => $fk['columns'] === ['location_id']);
            Schema::table('estates', function (Blueprint $table) use ($hasForeignKey) {
                if ($hasForeignKey) {
                    $table->dropForeign(['location_id']);
                }
            });
            if (Schema::hasIndex('estates', 'estates_location_id_index')) {
                Schema::table('estates', fn (Blueprint $table) => $table->dropIndex(['location_id']));
            }
            Schema::table('estates', fn (Blueprint $table) => $table->dropColumn('location_id'));
        }
        Schema::dropIfExists('locations');
    }

    /** Recreates one location per estate from its address. */
    public function down(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('country');
            $table->string('district');
            $table->string('address');
            $table->timestamps();
        });
        Schema::table('estates', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('description')->constrained();
        });

        foreach (DB::table('estates')->get() as $estate) {
            $id = DB::table('locations')->insertGetId([
                'landlord_id' => $estate->landlord_id,
                'name' => $estate->district ?: $estate->name,
                'country' => $estate->country ?: Places::DEFAULT_COUNTRY,
                'district' => $estate->district ?? '',
                'address' => $estate->address ?? '',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('estates')->where('id', $estate->id)->update(['location_id' => $id]);
        }

        Schema::table('estates', function (Blueprint $table) {
            $table->dropColumn(['address', 'district', 'country']);
        });
    }
};
