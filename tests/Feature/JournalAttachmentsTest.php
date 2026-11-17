<?php

use App\Accounting\Ledger;
use App\Models\JournalAttachment;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\StaffUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Supporting documents (receipts, invoices, statements) kept with Primora's
 * journal entries: attached when recording, or added later; private to staff.
 */

beforeEach(fn () => Storage::fake(JournalAttachment::DISK));

function bookkeeper(array $permissions = ['books.view', 'books.post']): StaffUser
{
    return StaffUser::factory()->withPermissions($permissions)->create();
}

test('an expense, transfer or journal can be recorded with its documents', function () {
    $staff = bookkeeper();
    $bank = LedgerAccount::key('bank');

    $this->actingAs($staff, 'staff')->post(route('staff.books.expenses.store'), [
        'entry_date' => today()->toDateString(), 'expense_account_id' => LedgerAccount::key('other_expenses')->id, 'paid_from_id' => $bank->id,
        'amount' => 150000, 'description' => 'Office rent',
        'attachments' => [UploadedFile::fake()->create('rent-invoice.pdf', 120, 'application/pdf'), UploadedFile::fake()->image('receipt.jpg')],
    ])->assertSessionHas('success', fn ($m) => str_contains($m, '2 documents attached'));

    $entry = JournalEntry::where('description', 'Office rent')->sole();
    expect($entry->attachments)->toHaveCount(2)
        ->and($entry->attachments->first()->original_name)->toBe('rent-invoice.pdf')
        ->and($entry->attachments->first()->uploaded_by_staff_user_id)->toBe($staff->id);
    Storage::disk(JournalAttachment::DISK)->assertExists($entry->attachments->pluck('path')->all());

    $this->actingAs($staff, 'staff')->post(route('staff.books.transfers.store'), [
        'entry_date' => today()->toDateString(), 'from_id' => $bank->id, 'to_id' => LedgerAccount::key('cash')->id, 'amount' => 50000,
        'attachments' => [UploadedFile::fake()->create('slip.pdf', 10, 'application/pdf')],
    ])->assertSessionHas('success');
    $this->actingAs($staff, 'staff')->post(route('staff.books.journal.store'), [
        'entry_date' => today()->toDateString(), 'description' => 'Capital',
        'lines' => [['account_id' => $bank->id, 'debit' => 1000], ['account_id' => LedgerAccount::key('equity')->id, 'credit' => 1000]],
        'attachments' => [UploadedFile::fake()->create('resolution.docx', 10)],
    ])->assertSessionHas('success');
    expect(JournalAttachment::count())->toBe(4);

    // Without documents still works.
    $this->actingAs($staff, 'staff')->post(route('staff.books.expenses.store'), [
        'entry_date' => today()->toDateString(), 'expense_account_id' => LedgerAccount::key('other_expenses')->id, 'paid_from_id' => $bank->id, 'amount' => 1000, 'description' => 'Tea',
    ])->assertSessionHas('success', 'Expense recorded ('.JournalEntry::latest('id')->first()->number.').');
});

test('bad files are refused and nothing is posted', function () {
    $staff = bookkeeper();
    $this->actingAs($staff, 'staff')->post(route('staff.books.expenses.store'), [
        'entry_date' => today()->toDateString(), 'expense_account_id' => LedgerAccount::key('other_expenses')->id, 'paid_from_id' => LedgerAccount::key('bank')->id,
        'amount' => 1000, 'description' => 'X', 'attachments' => [UploadedFile::fake()->create('run.exe', 10, 'application/x-msdownload')],
    ])->assertSessionHasErrors('attachments.0');
    $this->actingAs($staff, 'staff')->post(route('staff.books.expenses.store'), [
        'entry_date' => today()->toDateString(), 'expense_account_id' => LedgerAccount::key('other_expenses')->id, 'paid_from_id' => LedgerAccount::key('bank')->id,
        'amount' => 1000, 'description' => 'X', 'attachments' => [UploadedFile::fake()->create('big.pdf', 11000, 'application/pdf')],
    ])->assertSessionHasErrors('attachments.0');

    expect(JournalEntry::count())->toBe(0)->and(JournalAttachment::count())->toBe(0);
});

test('documents can be added to any entry later, opened by staff, and removed', function () {
    $entry = app(Ledger::class)->post(today(), 'Yo! pool payout batch', [['account' => 'cash', 'debit' => 500], ['account' => 'bank', 'credit' => 500]], 'liquidation', 1);
    $staff = bookkeeper();

    $this->actingAs($staff, 'staff')->post(route('staff.books.journal.attach', $entry), ['attachments' => [UploadedFile::fake()->create('statement.pdf', 50, 'application/pdf')]])
        ->assertSessionHas('success', "Document attached to {$entry->number}.");
    $doc = $entry->attachments()->sole();

    $this->actingAs($staff, 'staff')->get(route('staff.books.journal', ['period' => 'all']))->assertOk()->assertSee('statement.pdf')->assertSee('+ Attach document');
    $this->actingAs($staff, 'staff')->get(route('staff.books.attachments.show', $doc))->assertOk()->assertHeader('Content-Type', 'application/pdf');

    // Viewers can open documents but not add or remove them.
    $viewer = bookkeeper(['books.view']);
    $this->actingAs($viewer, 'staff')->get(route('staff.books.attachments.show', $doc))->assertOk();
    $this->actingAs($viewer, 'staff')->get(route('staff.books.journal', ['period' => 'all']))->assertSee('statement.pdf')->assertDontSee('+ Attach document');
    $this->actingAs($viewer, 'staff')->post(route('staff.books.journal.attach', $entry), ['attachments' => [UploadedFile::fake()->create('a.pdf', 1)]])->assertForbidden();
    $this->actingAs($viewer, 'staff')->delete(route('staff.books.attachments.destroy', $doc))->assertForbidden();

    $this->actingAs($staff, 'staff')->delete(route('staff.books.attachments.destroy', $doc))->assertSessionHas('success');
    expect(JournalAttachment::count())->toBe(0);
    Storage::disk(JournalAttachment::DISK)->assertMissing($doc->path);
});

test('documents are not reachable without staff sign-in', function () {
    $entry = app(Ledger::class)->post(today(), 'Capital', [['account' => 'bank', 'debit' => 5], ['account' => 'equity', 'credit' => 5]], 'manual');
    app(\App\Services\JournalAttachments::class)->store($entry, [UploadedFile::fake()->create('x.pdf', 1, 'application/pdf')], null);

    $this->get(route('staff.books.attachments.show', JournalAttachment::sole()))->assertRedirect();
});
