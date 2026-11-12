<?php

use App\Http\Middleware\AuthenticateTenant;
use App\Models\Estate;
use App\Models\Expense;
use App\Models\ExpenseItems;
use App\Models\Landlord;
use App\Models\Lease;
use App\Models\SecurityDepositLedger;
use App\Models\SmsLog;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\CurrentLandlord;
use App\Services\SmsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Using a tenant's security deposit: a reason, what it paid for, a receipt,
 * and (for costs) a linked expense. Tenants see the breakdown in their portal.
 */

beforeEach(function () {
    Storage::fake('public');
    $this->sms = [];
    $this->mock(SmsService::class, function ($mock) {
        $mock->shouldReceive('send')->andReturnUsing(function ($phone, $message) {
            $this->sms[] = $message;

            return true;
        });
    });
});
afterEach(fn () => CurrentLandlord::clear());

/** Grace in Kisaasi A4 with a 500,000 deposit paid. */
function depositSetup(): array
{
    $landlord = Landlord::factory()->create(['sms_balance' => 20]);
    $admin = User::factory()->admin()->create(['landlord_id' => $landlord->id]);
    CurrentLandlord::set($landlord->id);

    $unit = Unit::create(['name' => 'A4', 'estate_id' => Estate::create(['name' => 'Kisaasi', 'district' => 'Kampala'])->id, 'status' => 'occupied']);
    $tenant = Tenant::create([
        'type' => 'individual', 'name' => 'Grace Namata', 'email' => 'g'.fake()->unique()->randomNumber(6).'@example.test', 'primary_phone' => '+256772123456',
        'id_type' => 'national_id', 'id_number' => 'N'.fake()->unique()->randomNumber(6), 'emergency_contact_name' => 'E', 'emergency_contact_relationship' => 'family', 'emergency_contact_phone' => '+256700000000',
    ]);
    $lease = Lease::create([
        'number' => 'LSE-'.fake()->unique()->randomNumber(5), 'tenant_id' => $tenant->id, 'unit_id' => $unit->id, 'status' => 'active', 'start_date' => '2026-10-01', 'rent_amount' => 500000,
        'billing_cycle' => 'monthly', 'security_deposit' => 500000, 'required_upfront_periods' => 1, 'next_invoice_date' => '2026-12-01',
    ]);
    SecurityDepositLedger::create(['lease_id' => $lease->id, 'type' => 'deposit', 'date' => '2026-10-01', 'amount' => 500000, 'notes' => 'Deposit paid']);
    $repairs = ExpenseItems::firstOrCreate(['name' => 'Repairs & maintenance']);
    CurrentLandlord::clear();

    return compact('landlord', 'admin', 'tenant', 'lease', 'unit', 'repairs');
}

function depositLeft(Lease $lease): float
{
    return (float) CurrentLandlord::runAs($lease->landlord_id, fn () => app(\App\Services\SecurityDeposits::class)->balance($lease->id));
}

test('a deduction records why, what for and a receipt, and can record the repair as a linked expense', function () {
    ['admin' => $admin, 'lease' => $lease, 'repairs' => $repairs, 'unit' => $unit] = depositSetup();

    // A deduction needs a reason and a description.
    $this->actingAs($admin)->post(route('security-deposit-ledgers.store'), ['lease_id' => $lease->id, 'type' => 'deduction', 'amount' => 80000, 'date' => '2026-11-10'])
        ->assertSessionHasErrors(['category', 'notes']);

    $this->actingAs($admin)->post(route('security-deposit-ledgers.store'), [
        'lease_id' => $lease->id, 'type' => 'deduction', 'category' => 'damage_repair', 'amount' => 80000, 'date' => '2026-11-10',
        'notes' => 'Replaced the broken glass in the sitting room window', 'attachment' => UploadedFile::fake()->image('receipt.jpg'),
        'record_expense' => 1, 'expense_item_id' => $repairs->id,
    ])->assertSessionHas('success');

    $entry = SecurityDepositLedger::withoutGlobalScopes()->where('type', 'deduction')->sole();
    $expense = Expense::withoutGlobalScopes()->sole();
    expect($entry->category)->toBe('damage_repair')->and($entry->expense_id)->toBe($expense->id)
        ->and(Storage::disk('public')->exists($entry->attachment))->toBeTrue()
        ->and((float) $expense->amount)->toBe(80000.0)->and($expense->unit_id)->toBe($unit->id)->and($expense->expense_item_id)->toBe($repairs->id)
        ->and(depositLeft($lease))->toBe(420000.0)
        ->and(end($this->sms))->toContain('For: Damage repair: Replaced the broken glass')->toContain('Remaining: UGX 420,000');

    // More than is left is refused.
    $this->actingAs($admin)->post(route('security-deposit-ledgers.store'), [
        'lease_id' => $lease->id, 'type' => 'deduction', 'category' => 'cleaning', 'amount' => 500000, 'date' => '2026-11-10', 'notes' => 'Deep clean',
    ])->assertSessionHas('error');

    // Editing the deduction changes its expense; deleting it deletes both.
    $this->actingAs($admin)->put(route('security-deposit-ledgers.update', $entry), ['amount' => 90000])->assertSessionHas('success');
    expect((float) $expense->fresh()->amount)->toBe(90000.0);
    $this->actingAs($admin)->delete(route('security-deposit-ledgers.destroy', $entry))->assertSessionHas('success');
    expect(Expense::withoutGlobalScopes()->count())->toBe(0)->and(depositLeft($lease))->toBe(500000.0);
});

test('an expense can be paid from a tenant\'s security deposit, and stays in step with it', function () {
    ['admin' => $admin, 'lease' => $lease, 'repairs' => $repairs, 'unit' => $unit] = depositSetup();

    $this->actingAs($admin)->get(route('expenses.index'))->assertOk()->assertSee('Paid from a tenant')->assertSee('Grace Namata · Kisaasi A4 · UGX 500,000 left');

    $this->actingAs($admin)->post(route('expenses.store'), [
        'date' => '2026-11-11', 'expense_item_id' => $repairs->id, 'estate_id' => $unit->estate_id, 'unit_id' => $unit->id, 'amount' => 150000,
        'notes' => 'Fixed the door lock the tenant broke', 'paid_from_deposit' => 1, 'deposit_lease_id' => $lease->id, 'deposit_category' => 'damage_repair',
    ])->assertSessionHas('success');

    $expense = Expense::withoutGlobalScopes()->sole();
    $entry = SecurityDepositLedger::withoutGlobalScopes()->where('type', 'deduction')->sole();
    expect($entry->expense_id)->toBe($expense->id)->and($entry->notes)->toBe('Fixed the door lock the tenant broke')->and(depositLeft($lease))->toBe(350000.0);
    $this->actingAs($admin)->get(route('expenses.index'))->assertSee('From deposit · Grace Namata');

    // The deposit can't pay more than it has: nothing is saved.
    $this->actingAs($admin)->post(route('expenses.store'), [
        'date' => '2026-11-11', 'expense_item_id' => $repairs->id, 'estate_id' => $unit->estate_id, 'amount' => 400000,
        'paid_from_deposit' => 1, 'deposit_lease_id' => $lease->id, 'deposit_category' => 'cleaning',
    ])->assertSessionHas('error');
    expect(Expense::withoutGlobalScopes()->count())->toBe(1);

    // Editing the expense moves the deduction; too much is refused.
    $this->actingAs($admin)->put(route('expenses.update', $expense), ['amount' => 120000])->assertSessionHas('success');
    expect((float) $entry->fresh()->amount)->toBe(120000.0);
    $this->actingAs($admin)->put(route('expenses.update', $expense), ['amount' => 600000])->assertSessionHas('error');
    expect((float) $expense->fresh()->amount)->toBe(120000.0);

    // Deleting the expense returns the money to the deposit.
    $this->actingAs($admin)->delete(route('expenses.destroy', $expense))->assertSessionHas('success');
    expect(SecurityDepositLedger::withoutGlobalScopes()->where('type', 'deduction')->count())->toBe(0)->and(depositLeft($lease))->toBe(500000.0);
});

test('the tenant sees how their deposit was used, with receipts, and only their own', function () {
    ['admin' => $admin, 'lease' => $lease, 'tenant' => $tenant] = depositSetup();
    ['tenant' => $other] = depositSetup();

    $this->actingAs($admin)->post(route('security-deposit-ledgers.store'), [
        'lease_id' => $lease->id, 'type' => 'deduction', 'category' => 'damage_repair', 'amount' => 80000, 'date' => '2026-11-10',
        'notes' => 'Replaced broken window glass', 'attachment' => UploadedFile::fake()->image('glass.jpg'),
    ]);
    $this->actingAs($admin)->post(route('security-deposit-ledgers.store'), [
        'lease_id' => $lease->id, 'type' => 'deduction', 'category' => 'unpaid_utilities', 'amount' => 20000, 'date' => '2026-11-11', 'notes' => 'Water bill, October',
    ]);
    $this->post(route('logout'));
    CurrentLandlord::clear();

    $page = $this->withSession([AuthenticateTenant::SESSION => ['msisdn' => '256772123456', 'tenant_ids' => [$tenant->id], 'tenant_id' => $tenant->id]])
        ->get(route('tenant.deposit'))->assertOk();
    $page->assertSee('Damage repair')->assertSee('Replaced broken window glass')->assertSee('View receipt / photo')
        ->assertSee('Unpaid utilities / bills')->assertSee('Water bill, October')
        ->assertSee('UGX 100,000')->assertSee('UGX 400,000')->assertSee('Left: 420,000');

    CurrentLandlord::clear();
    $this->withSession([AuthenticateTenant::SESSION => ['msisdn' => '256772123456', 'tenant_ids' => [$other->id], 'tenant_id' => $other->id]])
        ->get(route('tenant.deposit'))->assertOk()->assertDontSee('Replaced broken window glass')->assertSee('UGX 500,000');
});
