<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Landlord books (reports built from invoices, payments and expenses) and
 * the taxes and levies landlords owe: a Primora-maintained catalogue with
 * default rates, which each landlord switches on and adjusts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landlords', function (Blueprint $table) {
            $table->string('books_basis', 10)->default('cash')->after('pass_collection_fee');      // cash | accrual
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(7)->after('books_basis'); // Uganda: July
            $table->string('taxpayer_type', 12)->default('individual')->after('fiscal_year_start_month'); // individual | company
            $table->string('tin', 30)->nullable()->after('taxpayer_type');                          // URA Taxpayer Identification Number
        });

        Schema::create('tax_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('authority', 80);              // who it's paid to
            $table->string('method', 30);                 // rental_income | bands | percent_of_value | fixed
            $table->string('scope', 10)->default('landlord'); // landlord | estate (one entry per estate)
            $table->json('params');                       // default rates, thresholds, bands…
            $table->boolean('deductible')->default(false); // an allowable expense when computing company income tax
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('landlord_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tax_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('estate_id')->nullable()->constrained()->cascadeOnDelete(); // per-estate taxes (rates, ground rent)
            $table->json('settings')->nullable();          // landlord's overrides: rate, threshold, amount, rateable_value…
            $table->string('notes', 300)->nullable();
            $table->timestamps();

            $table->unique(['landlord_id', 'tax_template_id', 'estate_id']);
        });

        Schema::create('tax_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained()->cascadeOnDelete();
            $table->foreignId('landlord_tax_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year');   // the year the fiscal year starts in (2025 = FY 2025/26)
            $table->decimal('amount', 14, 2);
            $table->date('paid_on');
            $table->string('reference', 100)->nullable();  // e.g. URA payment registration number (PRN)
            $table->string('notes', 300)->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['landlord_id', 'fiscal_year']);
        });

        $now = now();
        $verify = ' Rates are defaults for guidance; confirm the current figures with the authority.';
        DB::table('tax_templates')->insert([
            [
                'code' => 'rental_income_tax', 'name' => 'Rental income tax', 'authority' => 'Uganda Revenue Authority (URA)',
                'method' => 'rental_income', 'scope' => 'landlord', 'deductible' => false, 'sort_order' => 1,
                'params' => json_encode([
                    'individual' => ['rate' => 12, 'threshold' => 2820000],
                    'company' => ['rate' => 30, 'expense_cap_percent' => 50],
                ]),
                'description' => 'Individuals: a percentage of gross rental income above the annual threshold, with no deductions. Companies and partnerships: the corporate rate on rental income less allowable expenses (capped at a share of rental income).'.$verify,
            ],
            [
                'code' => 'local_service_tax', 'name' => 'Local service tax (LST)', 'authority' => 'Local government',
                'method' => 'bands', 'scope' => 'landlord', 'deductible' => false, 'sort_order' => 2,
                'params' => json_encode(['basis' => 'monthly_income', 'bands' => [
                    ['min' => 0, 'max' => 100000, 'amount' => 0],
                    ['min' => 100001, 'max' => 200000, 'amount' => 5000],
                    ['min' => 200001, 'max' => 300000, 'amount' => 10000],
                    ['min' => 300001, 'max' => 400000, 'amount' => 20000],
                    ['min' => 400001, 'max' => 500000, 'amount' => 30000],
                    ['min' => 500001, 'max' => 600000, 'amount' => 40000],
                    ['min' => 600001, 'max' => 700000, 'amount' => 60000],
                    ['min' => 700001, 'max' => 800000, 'amount' => 70000],
                    ['min' => 800001, 'max' => 900000, 'amount' => 80000],
                    ['min' => 900001, 'max' => 1000000, 'amount' => 90000],
                    ['min' => 1000001, 'max' => null, 'amount' => 100000],
                ]]),
                'description' => 'An annual amount set by band of average monthly income, paid to the local government where you earn it.'.$verify,
            ],
            [
                'code' => 'property_rates', 'name' => 'Property rates', 'authority' => 'Local council (e.g. KCCA, municipality)',
                'method' => 'percent_of_value', 'scope' => 'estate', 'deductible' => true, 'sort_order' => 3,
                'params' => json_encode(['rate' => 6]),
                'description' => "A percentage of each property's rateable value per year, set by the council. Enter the rateable value from the council's assessment for each estate.".$verify,
            ],
            [
                'code' => 'ground_rent', 'name' => 'Ground rent', 'authority' => 'Land owner (e.g. Buganda Land Board, council)',
                'method' => 'fixed', 'scope' => 'estate', 'deductible' => true, 'sort_order' => 4,
                'params' => json_encode(['amount' => 0]),
                'description' => 'A fixed annual amount for leasehold land, per estate, from your lease or land owner.',
            ],
            [
                'code' => 'trading_licence', 'name' => 'Trading licence', 'authority' => 'Local council',
                'method' => 'fixed', 'scope' => 'landlord', 'deductible' => true, 'sort_order' => 5,
                'params' => json_encode(['amount' => 0]),
                'description' => 'A fixed annual amount, where your council requires one for a property business.',
            ],
        ]);
        DB::table('tax_templates')->update(['is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_payments');
        Schema::dropIfExists('landlord_taxes');
        Schema::dropIfExists('tax_templates');
        Schema::table('landlords', fn (Blueprint $table) => $table->dropColumn(['books_basis', 'fiscal_year_start_month', 'taxpayer_type', 'tin']));
    }
};
