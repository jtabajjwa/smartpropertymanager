<?php

namespace App\Actions;

use App\Models\Expense;
use App\Models\Lease;
use App\Models\SecurityDepositLedger;
use App\Services\CurrentLandlord;
use App\Services\NumberGenerator;
use App\Services\SecurityDeposits;
use App\Services\SmsNotificationService;
use Illuminate\Support\Facades\DB;

/**
 * An expense (repairs, cleaning, utilities…), optionally paid from a tenant's
 * security deposit, which then goes down by the same amount and the tenant
 * is told by SMS. Shared by the web portal and the mobile app's API.
 *
 * $data is StoreExpenseRequest's validated data.
 *
 * @throws \App\Services\DepositException when the deposit can't cover it
 */
class RecordExpense
{
    /** @return array{0: Expense, 1: ?SecurityDepositLedger, 2: ?float} the expense, the deposit deduction and what's left */
    public function __invoke(array $data): array
    {
        $fromDeposit = (bool) ($data['paid_from_deposit'] ?? false);
        $leaseId = $data['deposit_lease_id'] ?? null;
        $category = $data['deposit_category'] ?? null;
        unset($data['paid_from_deposit'], $data['deposit_lease_id'], $data['deposit_category']);

        [$expense, $entry, $remaining] = DB::transaction(function () use ($data, $fromDeposit, $leaseId, $category) {
            $expense = Expense::create($data);
            $expense->update(['number' => app(NumberGenerator::class)->next(CurrentLandlord::id(), 'expense', 'EXP')]);

            if ($fromDeposit) {
                $lease = Lease::with('tenant')->findOrFail($leaseId);
                $result = app(SecurityDeposits::class)->deduct($lease, (string) $expense->amount, $expense->date->toDateString(), $category, $data['notes'] ?? null, expense: $expense);

                return [$expense, $result['entry'], (float) $result['remaining']];
            }

            return [$expense, null, null];
        });

        if ($entry) {
            app(\App\Services\Push\PushNotifications::class)->depositChanged($entry, (float) $remaining);
            app(SmsNotificationService::class)->depositDeduction($entry->load('lease.tenant'), $remaining);
        }

        return [$expense, $entry, $remaining];
    }
}
