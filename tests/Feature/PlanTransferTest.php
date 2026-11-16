<?php

use App\Models\Plan;
use App\Models\StaffUser;
use App\Services\PlanTransfer;
use Illuminate\Http\UploadedFile;

/*
 * Plans move between environments (staging → production) as a JSON file:
 * export, upload, review what would change, apply. Matched by name; never deleted.
 */

function planManager(): StaffUser
{
    return StaffUser::factory()->withPermissions(['plans.view', 'plans.manage'])->create();
}

function staffingPlans(): void
{
    Plan::create(['name' => 'Starter', 'pricing_model' => 'flat', 'monthly_price' => 50000, 'quarterly_price' => 140000, 'annual_price' => 500000, 'currency' => 'UGX', 'is_active' => true, 'show_on_website' => true, 'features' => ['books']]);
    $tiered = Plan::create(['name' => 'Per Unit', 'pricing_model' => 'tiered', 'monthly_price' => 0, 'quarterly_price' => 0, 'annual_price' => 0, 'currency' => 'UGX', 'is_active' => true, 'show_on_website' => false, 'features' => null]);
    $tiered->tiers()->create(['min_units' => 1, 'max_units' => 10, 'monthly_price' => 4000, 'quarterly_price' => 11000, 'annual_price' => 40000]);
    $tiered->tiers()->create(['min_units' => 11, 'max_units' => null, 'monthly_price' => 3500, 'quarterly_price' => 10000, 'annual_price' => 36000]);
}

function exportFile(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('plans.json', json_encode(app(PlanTransfer::class)->export()));
}

test('plans export to a file and import into an empty environment', function () {
    staffingPlans();
    $staff = planManager();

    $response = $this->actingAs($staff, 'staff')->get(route('staff.plans.export'))->assertOk();
    $json = json_decode($response->streamedContent(), true);
    expect($json['format'])->toBe('primora-plans')->and($json['plans'])->toHaveCount(2)
        ->and($json['plans'][0]['name'])->toBe('Per Unit')->and($json['plans'][0]['tiers'])->toHaveCount(2);

    // "Production": the same file into an empty database.
    $file = UploadedFile::fake()->createWithContent('plans.json', json_encode($json));
    Plan::query()->each(fn ($p) => $p->tiers()->delete());
    Plan::query()->delete();

    $this->actingAs($staff, 'staff')->post(route('staff.plans.import'), ['file' => $file])->assertRedirect(route('staff.plans.index'));
    expect(Plan::count())->toBe(0); // nothing until applied
    $this->actingAs($staff, 'staff')->get(route('staff.plans.index'))->assertOk()->assertSee('Review the import')->assertSee('2 new');

    $this->actingAs($staff, 'staff')->post(route('staff.plans.import.apply'))->assertSessionHas('success', 'Plans imported: 2 new, 0 updated, 0 unchanged.');

    $starter = Plan::where('name', 'Starter')->sole();
    $perUnit = Plan::where('name', 'Per Unit')->with('tiers')->sole();
    expect((float) $starter->monthly_price)->toBe(50000.0)->and($starter->features)->toBe(['books'])->and($starter->show_on_website)->toBeTrue()
        ->and($perUnit->pricing_model)->toBe('tiered')->and($perUnit->features)->toBeNull()
        ->and($perUnit->tiers->pluck('min_units')->all())->toBe([1, 11])->and($perUnit->tiers->last()->max_units)->toBeNull();

    // The session preview is used up.
    $this->actingAs($staff, 'staff')->post(route('staff.plans.import.apply'))->assertSessionHas('error');
});

test('importing over existing plans updates those that changed, adds new ones, and deletes nothing', function () {
    staffingPlans();
    $staff = planManager();
    $file = exportFile();

    // This environment differs: Starter is cheaper, and has an extra plan the file doesn't know.
    Plan::where('name', 'Starter')->update(['monthly_price' => 45000]);
    Plan::where('name', 'Per Unit')->update(['name' => 'per unit']); // matched ignoring case
    Plan::create(['name' => 'Legacy', 'pricing_model' => 'flat', 'monthly_price' => 1, 'quarterly_price' => 1, 'annual_price' => 1, 'currency' => 'UGX', 'is_active' => false]);

    $this->actingAs($staff, 'staff')->post(route('staff.plans.import'), ['file' => $file]);
    $this->actingAs($staff, 'staff')->get(route('staff.plans.index'))
        ->assertSee('Will update')->assertSee('Monthly price: 45,000 → 50,000')->assertSee('No change');

    $this->actingAs($staff, 'staff')->post(route('staff.plans.import.apply'))->assertSessionHas('success', 'Plans imported: 0 new, 1 updated, 1 unchanged.');
    expect((float) Plan::where('name', 'Starter')->value('monthly_price'))->toBe(50000.0)
        ->and(Plan::where('name', 'Legacy')->exists())->toBeTrue()
        ->and(Plan::count())->toBe(3);
});

test('bad files are refused with reasons, and nothing changes', function () {
    $staff = planManager();
    $upload = fn ($content) => $this->actingAs($staff, 'staff')->post(route('staff.plans.import'), ['file' => UploadedFile::fake()->createWithContent('x.json', $content)]);

    $upload('not json')->assertSessionHas('import_errors', ['This is not a Primora plans export file.']);
    $upload(json_encode(['format' => 'primora-plans', 'version' => 1, 'plans' => [
        ['name' => 'Broken', 'pricing_model' => 'tiered', 'currency' => 'UGX', 'monthly_price' => 0, 'quarterly_price' => 0, 'annual_price' => 0,
            'tiers' => [['min_units' => 1, 'max_units' => null, 'monthly_price' => 1, 'quarterly_price' => 1, 'annual_price' => 1], ['min_units' => 5, 'max_units' => 9, 'monthly_price' => 1, 'quarterly_price' => 1, 'annual_price' => 1]]],
        ['name' => 'NoPrice', 'pricing_model' => 'flat', 'currency' => 'UGX'],
    ]]))->assertSessionHas('import_errors', fn ($errors) => count($errors) === 2 && str_contains($errors[0], 'overlap'));

    expect(Plan::count())->toBe(0)->and(session('staff.plans_import'))->toBeNull();
});

test('features this environment does not know are skipped with a warning', function () {
    $staff = planManager();
    $json = json_encode(['format' => 'primora-plans', 'version' => 1, 'plans' => [
        ['name' => 'Future', 'pricing_model' => 'flat', 'currency' => 'ugx', 'monthly_price' => 1000, 'quarterly_price' => 3000, 'annual_price' => 10000, 'features' => ['books', 'teleport']],
    ]]);

    $this->actingAs($staff, 'staff')->post(route('staff.plans.import'), ['file' => UploadedFile::fake()->createWithContent('p.json', $json)]);
    $this->actingAs($staff, 'staff')->get(route('staff.plans.index'))->assertSee('features not known here are skipped (teleport)');
    $this->actingAs($staff, 'staff')->post(route('staff.plans.import.apply'));

    expect(Plan::sole()->only(['currency', 'features']))->toBe(['currency' => 'UGX', 'features' => ['books']]);
});

test('only staff who manage plans can import; viewers can export', function () {
    $viewer = StaffUser::factory()->withPermissions(['plans.view'])->create();
    $this->actingAs($viewer, 'staff')->get(route('staff.plans.export'))->assertOk();
    $this->actingAs($viewer, 'staff')->post(route('staff.plans.import'), ['file' => exportFile()])->assertForbidden();
    $this->actingAs($viewer, 'staff')->get(route('staff.plans.index'))->assertOk()->assertSee('Export')->assertDontSee('Upload a plans file');
});
