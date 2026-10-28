<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Utilities list only labelled utility invoices. Utility invoices stay
 * (invoice type "utility", which the books rely on); the label moves into
 * the invoice title ("Water – October") and the list is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Each step checks first: MariaDB can't roll back schema changes, so a
        // run that stopped part-way must be able to finish when run again.
        if (Schema::hasColumn('invoices', 'utility_id') && Schema::hasTable('utilities')) {
            $names = DB::table('utilities')->pluck('name', 'id');
            foreach (DB::table('invoices')->whereNotNull('utility_id')->get(['id', 'title', 'utility_id']) as $invoice) {
                $name = trim((string) ($names[$invoice->utility_id] ?? ''));
                if ($name !== '' && stripos((string) $invoice->title, $name) === false) {
                    DB::table('invoices')->where('id', $invoice->id)->update(['title' => mb_substr("{$name} – {$invoice->title}", 0, 100)]);
                }
            }
        }

        if (Schema::hasColumn('invoices', 'utility_id')) {
            if (collect(Schema::getForeignKeys('invoices'))->contains(fn ($fk) => $fk['columns'] === ['utility_id'])) {
                Schema::table('invoices', fn (Blueprint $table) => $table->dropForeign(['utility_id']));
            }
            Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('utility_id'));
        }
        Schema::dropIfExists('utilities');
    }

    /** Brings back an empty list and the column; titles keep the utility name. */
    public function down(): void
    {
        Schema::create('utilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('utility_id')->nullable()->constrained();
        });
    }
};
