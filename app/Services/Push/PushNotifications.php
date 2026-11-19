<?php

namespace App\Services\Push;

use App\Jobs\SendPushNotification;
use App\Models\DeviceToken;
use App\Models\Invoice;
use App\Models\Issue;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\SecurityDepositLedger;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Push notifications to the mobile app (free, unlike SMS, so they don't
 * depend on SMS settings or credit). Each one carries `route`, the app
 * screen to open when tapped.
 *
 * Tenants: bills (new, due soon, overdue), payments (received, mobile money
 * failed), replies to their issues, deposit used / refunded, lease ending.
 * Landlords and their staff: a tenant paid through Primora, a tenant
 * reported an issue or added a note.
 *
 * Nothing is queued when no phone of the recipient is signed in, or when
 * Firebase isn't set up; failures never stop the action that caused them.
 */
class PushNotifications
{
    public function __construct(protected FcmClient $fcm)
    {
    }

    // --- Tenants ---------------------------------------------------------

    public function invoiceIssued(Invoice $invoice): void
    {
        $tenantId = $this->tenantOf($invoice);
        if (!$tenantId) {
            return;
        }
        $paid = (float) $invoice->allocations()->sum('amount');
        $due = max(0, (float) $invoice->amount - $paid);

        $this->toTenant($tenantId, 'New bill: '.$invoice->title, $due <= 0
            ? self::ugx($invoice->amount).', paid in full from your advance payment. Nothing to pay.'
            : self::ugx($due).' due by '.$invoice->due_date->format('j M Y').'.', ['type' => 'invoice', 'route' => '/t/bills']);
    }

    public function invoiceDueSoon(Invoice $invoice): void
    {
        if (($tenantId = $this->tenantOf($invoice)) && $this->onceToday("due:{$invoice->id}")) {
            $this->toTenant($tenantId, 'Rent due '.$invoice->due_date->format('j M'), "{$invoice->title} ({$invoice->number}): ".self::ugx($invoice->amount).'. Tap to pay.', ['type' => 'invoice', 'route' => '/t/bills']);
        }
    }

    public function invoiceOverdue(Invoice $invoice): void
    {
        if (($tenantId = $this->tenantOf($invoice)) && $this->onceToday("overdue:{$invoice->id}")) {
            $this->toTenant($tenantId, 'Overdue: '.$invoice->title, "{$invoice->number} was due on ".$invoice->due_date->format('j M Y').'. Please pay your balance.', ['type' => 'invoice', 'route' => '/t/bills']);
        }
    }

    public function paymentReceived(Payment $payment): void
    {
        if ($payment->payable_type === Tenant::class) {
            $this->toTenant($payment->payable_id, 'Payment received', self::ugx($payment->amount).' received'.($payment->reference ? " (ref {$payment->reference})" : '').'. Thank you!', ['type' => 'payment', 'route' => '/t/payments']);
        }
    }

    public function rentPaymentFailed(PaymentTransaction $transaction): void
    {
        if ($transaction->payable_type === Tenant::class) {
            $this->toTenant($transaction->payable_id, "Payment didn't go through", self::ugx($transaction->amount).' from '.$transaction->displayMsisdn().' was not completed. You can try again.', ['type' => 'payment', 'route' => '/t/payments']);
        }
    }

    public function issueUpdated(Issue $issue, ?string $newStatus, ?string $note): void
    {
        $title = $newStatus ? "{$issue->title}: ".(Issue::STATUSES[$newStatus][0] ?? $newStatus) : "Reply on: {$issue->title}";
        $this->toTenant($issue->tenant_id, $title, $note ? mb_strimwidth($note, 0, 150, '…') : 'Your landlord updated this issue.', ['type' => 'issue', 'route' => "/t/issues/{$issue->id}"]);
    }

    public function depositChanged(SecurityDepositLedger $entry, float $remaining): void
    {
        $tenantId = $entry->lease?->tenant_id;
        if (!$tenantId) {
            return;
        }
        $refund = $entry->type === 'refund';
        $this->toTenant($tenantId, $refund ? 'Deposit refunded' : 'Deposit used',
            self::ugx($entry->amount).($refund ? ' refunded to you' : ' taken from your deposit'.($entry->notes || $entry->category ? ': '.rtrim($entry->description(), '.') : '')).'. Left: '.self::ugx($remaining).'.',
            ['type' => 'deposit', 'route' => '/t/deposit']);
    }

    public function leaseExpiring(Lease $lease): void
    {
        if ($lease->tenant_id && $lease->end_date && $this->onceToday("lease:{$lease->id}")) {
            $this->toTenant($lease->tenant_id, 'Your lease ends '.$lease->end_date->format('j M Y'), 'Talk to your landlord about renewing'.($lease->unit ? " {$lease->unit->name}" : '').'.', ['type' => 'lease', 'route' => '/t/home']);
        }
    }

    // --- Landlords and their staff -----------------------------------------

    public function tenantPaid(PaymentTransaction $transaction, Payment $payment): void
    {
        $c = $transaction->context ?? [];
        $this->toLandlord($transaction->landlord_id, ($c['tenant_name'] ?? 'A tenant').' paid '.self::ugx($payment->amount),
            (!empty($c['invoice_number']) ? "For {$c['invoice_number']}" : 'Advance payment').' by mobile money through Primora.',
            ['type' => 'payment', 'route' => "/payments/{$payment->id}"]);
    }

    public function issueReported(Issue $issue): void
    {
        $this->toLandlord($issue->landlord_id, 'New issue: '.$issue->title,
            ($issue->tenant?->name ?? 'A tenant').($issue->unit ? " · {$issue->unit->name}" : '').': '.mb_strimwidth((string) $issue->description, 0, 120, '…'),
            ['type' => 'issue', 'route' => "/issues/{$issue->id}"]);
    }

    public function tenantNote(Issue $issue, string $note, bool $reopened): void
    {
        $this->toLandlord($issue->landlord_id, ($reopened ? 'Re-opened: ' : 'Tenant note: ').$issue->title,
            ($issue->tenant?->name ?? 'The tenant').': '.mb_strimwidth($note, 0, 140, '…'), ['type' => 'issue', 'route' => "/issues/{$issue->id}"]);
    }

    // ---------------------------------------------------------------------

    public function toTenant(?int $tenantId, string $title, string $body, array $data = []): void
    {
        if ($tenantId) {
            $this->queue(Tenant::class, [$tenantId], $title, $body, $data);
        }
    }

    public function toLandlord(int $landlordId, string $title, string $body, array $data = []): void
    {
        $users = User::where('landlord_id', $landlordId)->where('is_active', true)->pluck('id')->all();
        $this->queue(User::class, $users, $title, $body, $data);
    }

    protected function queue(string $ownerType, array $ownerIds, string $title, string $body, array $data): void
    {
        try {
            if (!$ownerIds || !$this->fcm->configured()
                || !DeviceToken::where('owner_type', $ownerType)->whereIn('owner_id', $ownerIds)->signedIn()->exists()) {
                return;
            }
            SendPushNotification::dispatch($ownerType, $ownerIds, $title, $body, $data);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function tenantOf(Invoice $invoice): ?int
    {
        return $invoice->invoiceable_type === Tenant::class ? $invoice->invoiceable_id
            : ($invoice->lease_id ? DB::table('leases')->where('id', $invoice->lease_id)->value('tenant_id') : null);
    }

    /** Scheduled reminders: at most one push per thing per day, even if the job runs twice. */
    protected function onceToday(string $key): bool
    {
        return Cache::add('push:'.$key.':'.today()->toDateString(), true, now()->endOfDay());
    }

    protected static function ugx(float|string $amount): string
    {
        return 'UGX '.number_format((float) $amount);
    }
}
