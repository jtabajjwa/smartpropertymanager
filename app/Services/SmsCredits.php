<?php

namespace App\Services;

use App\Models\Landlord;
use App\Models\PaymentTransaction;
use App\Models\SmsCreditTransaction;
use App\Models\SmsLog;
use App\Models\StaffUser;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * The only place that changes a landlord's SMS credit balance. Each change
 * locks the landlord row and writes an sms_credit_transactions entry with
 * the balance after it, so the balance can always be explained line by line.
 */
class SmsCredits
{
    public function balance(Landlord|int $landlord): int
    {
        $id = $landlord instanceof Landlord ? $landlord->id : $landlord;

        return (int) DB::table('landlords')->where('id', $id)->value('sms_balance');
    }

    public function lowBalanceThreshold(): int
    {
        return Settings::int('sms.low_balance_threshold', 20);
    }

    /** Add credits (purchase, starter allowance, positive adjustment, refund). */
    public function add(int $landlordId, int $quantity, string $type, ?string $note = null, ?PaymentTransaction $payment = null, ?StaffUser $staff = null, ?SmsLog $smsLog = null): SmsCreditTransaction
    {
        return $this->change($landlordId, abs($quantity), $type, $note, $payment, $staff, $smsLog);
    }

    /**
     * Staff correction, + or −. The balance may not go below zero.
     *
     * @throws \InvalidArgumentException
     */
    public function adjust(int $landlordId, int $quantity, string $note, ?StaffUser $staff): SmsCreditTransaction
    {
        return DB::transaction(function () use ($landlordId, $quantity, $note, $staff) {
            $balance = (int) DB::table('landlords')->where('id', $landlordId)->lockForUpdate()->value('sms_balance');

            if ($balance + $quantity < 0) {
                throw new \InvalidArgumentException("This would take the balance below zero (it is {$balance}).");
            }

            return $this->change($landlordId, $quantity, 'adjustment', $note, staff: $staff);
        });
    }

    /** New landlords' free allowance (amount set by staff; 0 = none). */
    public function grantStarter(Landlord $landlord): ?SmsCreditTransaction
    {
        $credits = Settings::int('sms.starter_credits', 50);

        return $credits > 0 ? $this->add($landlord->id, $credits, 'starter', 'Free starter SMS') : null;
    }

    /**
     * Take credits for an SMS about to be sent. Returns false (and takes
     * nothing) if the balance can't cover it.
     */
    public function reserve(int $landlordId, int $segments, SmsLog $smsLog): bool
    {
        return DB::transaction(function () use ($landlordId, $segments, $smsLog) {
            $balance = (int) DB::table('landlords')->where('id', $landlordId)->lockForUpdate()->value('sms_balance');

            if ($balance < $segments) {
                return false;
            }

            $this->change($landlordId, -$segments, 'usage', null, smsLog: $smsLog);

            return true;
        });
    }

    /** Give back credits for an SMS the provider didn't accept. */
    public function release(int $landlordId, int $segments, SmsLog $smsLog): void
    {
        $this->add($landlordId, $segments, 'refund', 'SMS not sent', smsLog: $smsLog);
    }

    protected function change(int $landlordId, int $quantity, string $type, ?string $note = null, ?PaymentTransaction $payment = null, ?StaffUser $staff = null, ?SmsLog $smsLog = null): SmsCreditTransaction
    {
        return DB::transaction(function () use ($landlordId, $quantity, $type, $note, $payment, $staff, $smsLog) {
            $balance = (int) DB::table('landlords')->where('id', $landlordId)->lockForUpdate()->value('sms_balance');
            $after = $balance + $quantity;

            DB::table('landlords')->where('id', $landlordId)->update(['sms_balance' => $after]);

            return SmsCreditTransaction::create([
                'landlord_id' => $landlordId,
                'type' => $type,
                'quantity' => $quantity,
                'balance_after' => $after,
                'payment_transaction_id' => $payment?->id,
                'sms_log_id' => $smsLog?->id,
                'staff_user_id' => $staff?->id,
                'note' => $note,
            ]);
        });
    }
}
