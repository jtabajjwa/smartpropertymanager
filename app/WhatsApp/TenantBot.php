<?php

namespace App\WhatsApp;

use App\Models\Invoice;
use App\Models\Issue;
use App\Models\PaymentTransaction;
use App\Models\Tenant;
use App\Models\WhatsAppSession;
use App\Payments\Msisdn;
use App\Services\CurrentLandlord;
use App\Services\TenantPortal\IssueReporter;
use App\Services\TenantPortal\RentPaymentException;
use App\Services\TenantPortal\RentPayments;
use App\Services\TenantPortal\TenantAccount;
use App\Services\TenantPortal\TenantLogin;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The tenant WhatsApp bot. A tenant messages Primora's number and gets a menu:
 * balance, invoices (as PDFs), paying by mobile money, payments, reporting
 * and following issues, and their tenancy details.
 *
 * Who they are: the WhatsApp number is matched to the phone on their tenant
 * record, as for SMS sign-in. A number we don't know can sign in with their
 * unit ID (or the phone / email their landlord has), proving it with a code
 * sent there, exactly like the portal. A number on several tenancies picks
 * which account to use.
 *
 * The conversation's place is kept on the WhatsAppSession (state + context).
 * Everything reads and writes through the same services as the tenant portal,
 * inside the tenant's landlord scope.
 */
class TenantBot
{
    protected WhatsAppSession $session;

    public function __construct(
        protected CloudApi $wa,
        protected TenantLogin $login,
        protected RentPayments $rent,
        protected IssueReporter $issues,
    ) {
    }

    public function handle(string $waId, ?string $profileName, array $message): void
    {
        $this->session = WhatsAppSession::firstOrCreate(['wa_id' => $waId]);
        $this->session->update(['profile_name' => $profileName ?? $this->session->profile_name, 'last_inbound_at' => now()]);
        if (!empty($message['id'])) {
            $this->wa->markRead($message['id']);
        }

        $in = Input::from($message);
        $tenants = $this->tenants();

        if ($tenants->isEmpty()) {
            $this->signIn($in);

            return;
        }

        if ($in->is('logout') && $this->session->via) {
            $this->session->update(['via' => null, 'via_to' => null, 'tenant_id' => null, 'state' => null, 'context' => null]);
            $this->wa->text($waId, 'You have signed out. Send your unit ID any time to sign in again.');

            return;
        }

        // Which account: the one chosen before, the only one, or ask.
        if ($in->id && str_starts_with($in->id, 'acct:') && $tenants->contains('id', (int) substr($in->id, 5))) {
            $this->session->update(['tenant_id' => (int) substr($in->id, 5)]);
            $this->session->goTo(null);
            $in = Input::from(['type' => 'text', 'text' => ['body' => 'menu']]);
        }
        $tenant = $tenants->firstWhere('id', $this->session->tenant_id);
        if (!$tenant && $tenants->count() === 1) {
            $tenant = $tenants->first();
            $this->session->update(['tenant_id' => $tenant->id]);
        }
        if (!$tenant || $in->is('accounts')) {
            $this->chooseAccount($tenants);

            return;
        }

        CurrentLandlord::runAs($tenant->landlord_id, fn () => $this->route($tenant, $in, $tenants->count() > 1));
    }

    /** The tenant records this number can use: by its own phone number, or the code it signed in with. */
    protected function tenants(): Collection
    {
        $msisdn = Msisdn::normalize($this->session->wa_id);
        $tenants = $msisdn ? $this->login->tenantsWithPhone($msisdn) : collect();

        if ($this->session->via && $this->session->via_to) {
            $tenants = $tenants->merge($this->login->tenantsFor($this->session->via, $this->session->via_to));
        }

        return $tenants->unique('id')->values();
    }

    // --- Signing in an unknown number -------------------------------------------------

    protected function signIn(Input $in): void
    {
        $to = $this->session->wa_id;
        $state = $this->session->state;

        if ($state === 'signin_code' && preg_match('/^\d{6}$/', str_replace(' ', '', (string) $in->text))) {
            [$channel, $destination] = [$this->session->ctx('channel'), $this->session->ctx('destination')];

            if ($this->login->verify($channel, $destination, str_replace(' ', '', $in->text))) {
                $this->session->update(['via' => $channel, 'via_to' => $destination, 'tenant_id' => null, 'state' => null, 'context' => null]);
                $this->handleAgainAsMenu();
            } else {
                $this->wa->text($to, "That code isn't right, or it has expired. Send the code again, or send your unit ID for a new one.");
            }

            return;
        }

        if ($state === 'signin_choose' && $in->id && str_starts_with($in->id, 'dest:')) {
            [$channel, $destination] = TenantLogin::parseKey(substr($in->id, 5)) ?? [null, null];
            $channel ? $this->sendCode($channel, $destination) : $this->notRecognised();

            return;
        }

        // A unit ID (or the phone / email the landlord has), even as the very first message.
        $typed = trim((string) $in->text);
        $destinations = $typed !== '' && !$in->isGreeting() ? $this->login->destinations($typed) : [];

        if (count($destinations) === 1) {
            $this->sendCode(...TenantLogin::parseKey(array_key_first($destinations)));
        } elseif (count($destinations) > 1) {
            $this->session->goTo('signin_choose');
            $this->wa->list($to, 'This unit has more than one tenant. Which phone or email is yours? We will send the code there.', 'Choose',
                collect($destinations)->mapWithKeys(fn ($label, $key) => ['dest:'.$key => [Str::limit($label, 24, ''), $label]])->all());
        } elseif ($typed !== '' && !$in->isGreeting() && in_array($state, ['signin_unit', 'signin_code', 'signin_choose'], true)) {
            $this->wa->text($to, "We couldn't find that. Check the unit ID on your invoice and send it again, or ask your landlord to add this WhatsApp number to your tenancy.");
        } else {
            $this->notRecognised();
        }
    }

    protected function notRecognised(): void
    {
        $this->session->goTo('signin_unit');
        $this->wa->text($this->session->wa_id, "Hello! This is ".config('app.name')." for tenants.\n\n"
            ."This WhatsApp number isn't on any tenancy with us. Ask your landlord to update your phone number, "
            ."or send your *unit ID* (the number on your invoice) and we'll send a sign-in code to the phone or email your landlord has for you.");
    }

    protected function sendCode(string $channel, string $destination): void
    {
        $error = $this->login->sendCode($channel, $destination, 'whatsapp');
        if ($error) {
            $this->wa->text($this->session->wa_id, $error);

            return;
        }

        $this->session->goTo('signin_code', ['channel' => $channel, 'destination' => $destination]);
        $how = $channel === TenantLogin::EMAIL ? 'by email' : 'by SMS';
        $this->wa->text($this->session->wa_id, "We sent a 6-digit code {$how} to ".TenantLogin::label($channel, $destination).'. Reply here with the code.');
    }

    /** Just signed in: show the menu (or the account picker). */
    protected function handleAgainAsMenu(): void
    {
        $this->handle($this->session->wa_id, $this->session->profile_name, ['type' => 'text', 'text' => ['body' => 'menu']]);
    }

    protected function chooseAccount(Collection $tenants): void
    {
        $this->session->goTo(null);
        $this->wa->list($this->session->wa_id, 'This number is on more than one tenancy. Which account would you like to use?', 'Accounts',
            $tenants->take(CloudApi::MAX_ROWS)->mapWithKeys(fn (Tenant $t) => ['acct:'.$t->id => [
                $t->landlord->trading_name ?: $t->landlord->name, $t->name,
            ]])->all());
    }

    // --- Signed in ------------------------------------------------------------------------

    protected function route(Tenant $tenant, Input $in, bool $severalAccounts): void
    {
        $id = $in->id;
        $state = $this->session->state;

        if ($in->isGreeting() || $id === 'menu') {
            $this->menu($tenant, $severalAccounts);

            return;
        }

        $action = $id ?? $in->keyword();
        $handled = match (true) {
            $action === 'balance' => $this->balance($tenant),
            $action === 'invoices' => $this->invoices($tenant),
            $action === 'pay' => $this->pay($tenant),
            $action === 'payments' => $this->payments($tenant),
            $action === 'issue_new' => $this->issueStart($tenant),
            $action === 'issues' => $this->issueList($tenant),
            $action === 'tenancy' => $this->tenancy($tenant),
            $id !== null && str_starts_with($id, 'inv:') => $this->invoicePdf($tenant, (int) substr($id, 4)),
            $id === 'pay:advance' => $this->payAdvance(),
            $id === 'pay:confirm' && $state === 'pay_confirm' => $this->payConfirm($tenant),
            $id !== null && preg_match('/^pay:\d+$/', $id) === 1 => $this->payInvoice($tenant, (int) substr($id, 4)),
            $id === 'amt:full' && $state === 'pay_amount' => $this->askPhone($tenant, (float) $this->session->ctx('balance')),
            $id === 'amt:other' && $state === 'pay_amount' => $this->askAmount('Reply with the amount in UGX, e.g. 250000.'),
            $id === 'phone:self' && $state === 'pay_phone' => $this->payReview($tenant, (string) Msisdn::normalize($this->session->wa_id)),
            $id === 'phone:other' && $state === 'pay_phone' => $this->askOtherPhone(),
            $id !== null && str_starts_with($id, 'cat:') => $this->issueCategory($id),
            $id !== null && str_starts_with($id, 'lease:') && $state === 'issue_lease' => $this->issueLease((int) substr($id, 6)),
            $id === 'issue:no_photo' && $state === 'issue_photo' => $this->issueSave($tenant, null),
            $id !== null && str_starts_with($id, 'iss:') => $this->issueShow($tenant, (int) substr($id, 4)),
            $state === 'pay_amount_text' && $in->text !== null => $this->payAmountTyped($tenant, $in->text),
            $state === 'pay_phone_text' && $in->text !== null => $this->payPhoneTyped($tenant, $in->text),
            $state === 'issue_desc' && $in->text !== null => $this->issueDescribed($in->text),
            $state === 'issue_photo' && $in->mediaId !== null => $this->issueSave($tenant, $in->mediaId),
            $state === 'issue_photo' && $in->text !== null => $this->issueSave($tenant, null),
            default => false,
        };

        if ($handled === false) {
            if ($in->type !== 'text' && $in->type !== 'interactive' && $in->mediaId === null) {
                $this->wa->text($this->session->wa_id, 'Sorry, I can only read text, menu choices and photos.', $tenant->id);
            }
            $this->menu($tenant, $severalAccounts);
        }
    }

    protected function menu(Tenant $tenant, bool $severalAccounts): bool
    {
        $this->session->goTo(null);
        $first = Str::before(trim($tenant->name), ' ') ?: $tenant->name;

        $rows = [
            'balance' => ['My balance', 'What you owe and when it is due'],
            'invoices' => ['Invoices', 'Unpaid invoices, sent as PDF'],
        ];
        if ($this->rent->available($tenant)) {
            $rows['pay'] = ['Pay rent', 'Mobile money prompt to your phone'];
        }
        $rows += [
            'payments' => ['My payments', 'Recent payments and receipts'],
            'issue_new' => ['Report an issue', 'A repair, request or complaint'],
            'issues' => ['My issues', 'Progress on what you reported'],
            'tenancy' => ['My tenancy', 'Unit, unit ID, lease and contacts'],
        ];
        if ($severalAccounts) {
            $rows['accounts'] = ['Switch account', 'You rent with more than one landlord'];
        }
        if ($this->session->via) {
            $rows['logout'] = ['Sign out', 'Stop using this number for your account'];
        }

        $this->wa->list($this->session->wa_id, "Hi {$first}, what would you like to do?", 'Menu', $rows, $tenant->id, $this->landlordName($tenant));

        return true;
    }

    // --- Balance, invoices, payments, tenancy -------------------------------------------

    protected function balance(Tenant $tenant): bool
    {
        $account = new TenantAccount($tenant);
        $open = $account->openInvoices();
        $outstanding = $open->sum('balance');
        $overdue = $open->filter(fn ($i) => $i->due_date->lt(today()))->sum('balance');
        $next = $open->first(fn ($i) => $i->due_date->gte(today()));
        $credit = $account->credit();

        $lines = ["*Your balance with {$this->landlordName($tenant)}*", 'You owe: '.$this->ugx($outstanding)];
        if ($overdue > 0) {
            $lines[] = 'Overdue: '.$this->ugx($overdue);
        }
        if ($next) {
            $lines[] = "Next due: {$this->ugx($next->balance)} on {$next->due_date->format('j M Y')} ({$next->number})";
        }
        if ($credit > 0) {
            $lines[] = 'Paid in advance (credit): '.$this->ugx($credit);
        }

        $buttons = ($outstanding > 0 && $this->rent->available($tenant)) ? ['pay' => 'Pay now'] : [];
        $this->wa->buttons($this->session->wa_id, implode("\n", $lines), $buttons + ['invoices' => 'Invoices', 'menu' => 'Menu'], $tenant->id);

        return true;
    }

    protected function invoices(Tenant $tenant): bool
    {
        $account = new TenantAccount($tenant);
        $open = $account->openInvoices();

        if ($open->isEmpty()) {
            $this->wa->buttons($this->session->wa_id, 'You have no unpaid invoices. Thank you!', ['payments' => 'My payments', 'menu' => 'Menu'], $tenant->id);

            return true;
        }

        $multi = $account->hasMultipleUnits();
        $more = $open->count() > CloudApi::MAX_ROWS ? "\n(Showing the oldest ".CloudApi::MAX_ROWS.'. See them all in the tenant portal.)' : '';
        $this->wa->list($this->session->wa_id, "You have {$open->count()} unpaid ".Str::plural('invoice', $open->count()).', '.$this->ugx($open->sum('balance'))." in all. Pick one to get the PDF.{$more}", 'Invoices',
            $open->mapWithKeys(fn (Invoice $i) => ['inv:'.$i->id => [
                $i->number, $this->ugx($i->balance).' · due '.$i->due_date->format('j M').($multi && $i->lease?->unit ? ' · Unit '.$i->lease->unit->name : ''),
            ]])->all(), $tenant->id);

        return true;
    }

    protected function invoicePdf(Tenant $tenant, int $invoiceId): bool
    {
        $account = new TenantAccount($tenant);
        $invoice = Invoice::find($invoiceId);
        if (!$invoice || !$account->ownsInvoice($invoice)) {
            return false;
        }

        $invoice->load(['landlord', 'lease.unit.estate']);
        $pdf = Pdf::loadView('pdfs.invoice', ['invoice' => $invoice])->output();
        $this->wa->document($this->session->wa_id, $pdf, "invoice-{$invoice->number}.pdf",
            "Invoice {$invoice->number} · {$this->ugx((float) $invoice->amount)} · due {$invoice->due_date->format('j M Y')}", $tenant->id);

        $open = $account->findOpenInvoice($invoice->id);
        $buttons = ($open && $this->rent->available($tenant)) ? ['pay:'.$invoice->id => 'Pay this invoice'] : [];
        $this->wa->buttons($this->session->wa_id, $open ? 'Still due: '.$this->ugx($open->balance).'.' : 'This invoice is paid.', $buttons + ['menu' => 'Menu'], $tenant->id);

        return true;
    }

    protected function payments(Tenant $tenant): bool
    {
        $payments = (new TenantAccount($tenant))->payments()->latest('payment_date')->latest('id')->limit(5)->get();

        $body = $payments->isEmpty()
            ? 'No payments recorded yet.'
            : "*Your recent payments*\n".$payments->map(fn ($p) => "{$p->payment_date->format('j M Y')} · {$this->ugx((float) $p->amount)} · {$p->number}")->implode("\n");

        $this->wa->buttons($this->session->wa_id, $body, ['balance' => 'My balance', 'menu' => 'Menu'], $tenant->id);

        return true;
    }

    protected function tenancy(Tenant $tenant): bool
    {
        $leases = $tenant->leases()->with('unit.estate')->where('status', 'active')->get();
        $landlord = $tenant->landlord;

        $lines = [];
        foreach ($leases as $lease) {
            $unit = $lease->unit;
            $lines[] = "*Unit {$unit?->name}*".($unit?->estate ? ", {$unit->estate->name}" : '');
            if ($unit?->code) {
                $lines[] = "Unit ID: {$unit->code}";
            }
            $lines[] = "Lease {$lease->number}: from {$lease->start_date->format('j M Y')}".($lease->end_date ? " to {$lease->end_date->format('j M Y')}" : ', open-ended');
            $lines[] = 'Rent: '.$this->ugx((float) $lease->rent_amount).' '.strtolower((string) $lease->billing_cycle);
            $lines[] = '';
        }
        if ($leases->isEmpty()) {
            $lines[] = 'You have no active tenancy.';
            $lines[] = '';
        }
        $lines[] = "*{$this->landlordName($tenant)}*";
        if ($landlord->contact_phone) {
            $lines[] = "Phone: {$landlord->contact_phone}";
        }
        if ($landlord->contact_email) {
            $lines[] = "Email: {$landlord->contact_email}";
        }
        $lines[] = '';
        $lines[] = 'Tenant portal: '.route('tenant.login');

        $this->wa->buttons($this->session->wa_id, implode("\n", $lines), ['menu' => 'Menu'], $tenant->id);

        return true;
    }

    // --- Paying ----------------------------------------------------------------------------

    protected function pay(Tenant $tenant): bool
    {
        if (!$this->rent->available($tenant)) {
            $this->wa->buttons($this->session->wa_id, "{$this->landlordName($tenant)} doesn't take mobile money payments through ".config('app.name').' yet. Please pay them the usual way.', ['menu' => 'Menu'], $tenant->id);

            return true;
        }

        $open = (new TenantAccount($tenant))->openInvoices();
        if ($open->isEmpty()) {
            return $this->payAdvance();
        }

        $rows = $open->take(CloudApi::MAX_ROWS - 1)->mapWithKeys(fn (Invoice $i) => ['pay:'.$i->id => [$i->number, $this->ugx($i->balance).' · due '.$i->due_date->format('j M')]])->all();
        $rows['pay:advance'] = ['Pay in advance', 'Any amount, kept as credit'];
        $this->wa->list($this->session->wa_id, 'What would you like to pay?', 'Choose', $rows, $tenant->id);

        return true;
    }

    protected function payInvoice(Tenant $tenant, int $invoiceId): bool
    {
        $invoice = (new TenantAccount($tenant))->findOpenInvoice($invoiceId);
        if (!$invoice) {
            $this->wa->buttons($this->session->wa_id, 'That invoice is already paid or no longer available.', ['menu' => 'Menu'], $tenant->id);

            return true;
        }

        $this->session->goTo('pay_amount', ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->number, 'balance' => $invoice->balance]);
        $full = 'Pay '.$this->ugx($invoice->balance);
        $this->wa->buttons($this->session->wa_id, "{$this->ugx($invoice->balance)} is due on invoice {$invoice->number}. How much would you like to pay?",
            ['amt:full' => strlen($full) <= 20 ? $full : 'Pay in full', 'amt:other' => 'Another amount', 'menu' => 'Cancel'], $tenant->id);

        return true;
    }

    protected function payAdvance(): bool
    {
        $this->session->goTo('pay_amount_text', ['invoice_id' => null]);
        $this->wa->text($this->session->wa_id, 'How much would you like to pay in advance? Reply with the amount in UGX, e.g. 500000. It is kept as credit for your next invoices.');

        return true;
    }

    protected function askAmount(string $prompt): bool
    {
        $this->session->goTo('pay_amount_text', $this->session->context ?? []);
        $this->wa->text($this->session->wa_id, $prompt);

        return true;
    }

    protected function payAmountTyped(Tenant $tenant, string $text): bool
    {
        $digits = preg_replace('/[^\d.]/', '', str_ireplace(['ugx', 'shs', ','], '', $text));
        $amount = is_numeric($digits) ? round((float) $digits) : 0;
        $balance = $this->session->ctx('balance');

        $problem = match (true) {
            $amount < RentPayments::MIN_AMOUNT => 'Please reply with an amount of at least '.$this->ugx(RentPayments::MIN_AMOUNT).', in numbers, e.g. 250000.',
            $amount > RentPayments::MAX_AMOUNT => 'The most you can pay at once is '.$this->ugx(RentPayments::MAX_AMOUNT).'. Reply with a smaller amount.',
            $balance !== null && $amount > (float) $balance => "That is more than the {$this->ugx((float) $balance)} due on this invoice. Reply with a smaller amount, or pay the extra in advance from the menu.",
            default => null,
        };
        if ($problem) {
            $this->wa->text($this->session->wa_id, $problem, $tenant->id);

            return true;
        }

        return $this->askPhone($tenant, $amount);
    }

    protected function askPhone(Tenant $tenant, float $amount): bool
    {
        $this->session->goTo('pay_phone', ['amount' => $amount] + ($this->session->context ?? []));

        $own = Msisdn::normalize($this->session->wa_id);
        if (!$own) {
            return $this->askOtherPhone();
        }

        $this->wa->buttons($this->session->wa_id, 'Which mobile money number should get the payment prompt?', [
            'phone:self' => 'This number ('.$this->localNumber($own).')',
            'phone:other' => 'Another number',
            'menu' => 'Cancel',
        ], $tenant->id);

        return true;
    }

    protected function askOtherPhone(): bool
    {
        $this->session->goTo('pay_phone_text', $this->session->context ?? []);
        $this->wa->text($this->session->wa_id, 'Reply with the MTN or Airtel mobile money number, e.g. 0772 123 456.');

        return true;
    }

    protected function payPhoneTyped(Tenant $tenant, string $text): bool
    {
        $msisdn = Msisdn::normalize($text);
        if (!$msisdn) {
            $this->wa->text($this->session->wa_id, "That doesn't look like a Ugandan mobile number. Reply with a number like 0772 123 456.", $tenant->id);

            return true;
        }

        return $this->payReview($tenant, $msisdn);
    }

    protected function payReview(Tenant $tenant, string $msisdn): bool
    {
        $amount = (float) $this->session->ctx('amount');
        $quote = $this->rent->quote($tenant, $amount);
        $this->session->goTo('pay_confirm', ['phone' => $msisdn] + ($this->session->context ?? []));

        $for = $this->session->ctx('invoice_number') ? "invoice {$this->session->ctx('invoice_number')}" : 'advance payment';
        $lines = [
            '*Please confirm*',
            "Pay {$this->ugx($quote['principal'])} to {$this->landlordName($tenant)} for {$for}.",
        ];
        if ($quote['fee'] > 0) {
            $lines[] = 'Fee: '.$this->ugx($quote['fee']).($quote['bearer'] === 'tenant' ? ' (added)' : ' (paid by your landlord)');
        }
        $lines[] = 'You will be charged: '.$this->ugx($quote['charge']);
        $lines[] = 'From: '.$this->localNumber($msisdn);

        $this->wa->buttons($this->session->wa_id, implode("\n", $lines), ['pay:confirm' => 'Confirm', 'menu' => 'Cancel'], $tenant->id);

        return true;
    }

    protected function payConfirm(Tenant $tenant): bool
    {
        $c = $this->session->context ?? [];
        $this->session->goTo(null);

        try {
            $transaction = $this->rent->start($tenant, $c['invoice_id'] ?? null, (float) ($c['amount'] ?? 0), (string) ($c['phone'] ?? ''), ['whatsapp_to' => $this->session->wa_id]);
        } catch (RentPaymentException $e) {
            $this->wa->buttons($this->session->wa_id, $e->getMessage(), ['pay' => 'Try again', 'menu' => 'Menu'], $tenant->id);

            return true;
        }

        $this->wa->text($this->session->wa_id, $transaction->status === PaymentTransaction::SUCCEEDED
            ? 'Payment received. Thank you!'
            : "Payment request sent to {$transaction->displayMsisdn()}. Approve it on that phone with your mobile money PIN. We'll confirm here when it goes through.", $tenant->id);

        return true;
    }

    /** Called when a payment started on WhatsApp succeeds or fails (see RentPurpose). */
    public static function paymentResult(PaymentTransaction $transaction, bool $succeeded): void
    {
        $c = $transaction->context ?? [];
        $to = $c['whatsapp_to'] ?? null;
        if (!$to) {
            return;
        }

        $wa = app(CloudApi::class);
        $principal = 'UGX '.number_format((float) ($c['principal'] ?? $transaction->amount));
        $for = !empty($c['invoice_number']) ? "invoice {$c['invoice_number']}" : 'your advance payment';

        if ($succeeded) {
            $receipt = !empty($c['payment_number']) ? " Receipt {$c['payment_number']}." : '';
            $wa->buttons($to, "Payment received: {$principal} for {$for}.{$receipt} Thank you!", ['balance' => 'My balance', 'menu' => 'Menu'], $c['tenant_id'] ?? null);
        } else {
            $why = $transaction->status_message ? " ({$transaction->status_message})" : '';
            $wa->buttons($to, "Your payment of {$principal} for {$for} didn't go through{$why}. Nothing was charged to your landlord account.", ['pay' => 'Try again', 'menu' => 'Menu'], $c['tenant_id'] ?? null);
        }
    }

    // --- Issues ----------------------------------------------------------------------------

    protected function issueStart(Tenant $tenant): bool
    {
        $leases = $tenant->leases()->with('unit')->where('status', 'active')->get();

        if ($leases->count() > 1) {
            $this->session->goTo('issue_lease');
            $this->wa->list($this->session->wa_id, 'Which unit is the issue in?', 'Units',
                $leases->mapWithKeys(fn ($l) => ['lease:'.$l->id => ['Unit '.$l->unit?->name, $l->number]])->all(), $tenant->id);

            return true;
        }

        return $this->issueAskCategory(['lease_id' => $leases->first()?->id]);
    }

    protected function issueLease(int $leaseId): bool
    {
        return $this->issueAskCategory(['lease_id' => $leaseId]);
    }

    protected function issueAskCategory(array $context): bool
    {
        $this->session->goTo('issue_category', $context);
        $this->wa->buttons($this->session->wa_id, 'What kind of issue is it?', ['cat:repair' => 'Repair', 'cat:request' => 'Request', 'cat:complaint' => 'Complaint']);

        return true;
    }

    protected function issueCategory(string $id): bool
    {
        $category = substr($id, 4);
        if (!array_key_exists($category, Issue::CATEGORIES)) {
            return false;
        }

        $this->session->goTo('issue_desc', ['category' => $category] + ($this->session->context ?? []));
        $this->wa->text($this->session->wa_id, 'Describe it in one message, e.g. "The kitchen tap is leaking and the floor gets wet."');

        return true;
    }

    protected function issueDescribed(string $text): bool
    {
        $text = trim($text);
        if (mb_strlen($text) < 5) {
            $this->wa->text($this->session->wa_id, 'Please describe the issue in a few words so your landlord knows what to fix.');

            return true;
        }

        $this->session->goTo('issue_photo', ['description' => Str::limit($text, 5000, '')] + ($this->session->context ?? []));
        $this->wa->buttons($this->session->wa_id, 'If you can, send a photo of it now. Otherwise tap "No photo".', ['issue:no_photo' => 'No photo', 'menu' => 'Cancel']);

        return true;
    }

    protected function issueSave(Tenant $tenant, ?string $mediaId): bool
    {
        $c = $this->session->context ?? [];
        $this->session->goTo(null);

        $photo = null;
        if ($mediaId && ($media = $this->wa->downloadMedia($mediaId))) {
            $extension = str_contains((string) $media['mime'], 'png') ? 'png' : 'jpg';
            $photo = 'issues/'.Str::uuid().'.'.$extension;
            Storage::disk('public')->put($photo, $media['contents']);
        }

        $description = (string) ($c['description'] ?? '');
        $lease = !empty($c['lease_id']) ? $tenant->leases()->find($c['lease_id']) : $tenant->leases()->where('status', 'active')->first();
        $issue = $this->issues->report($tenant, $lease, $c['category'] ?? 'repair', Str::limit(Str::before($description, "\n"), 150, ''), $description, $photo);

        $this->wa->buttons($this->session->wa_id, "Thank you. Your landlord has been notified.\n\nIssue {$issue->number}: {$issue->title}\nYou can follow its progress under \"My issues\".",
            ['issues' => 'My issues', 'menu' => 'Menu'], $tenant->id);

        return true;
    }

    protected function issueList(Tenant $tenant): bool
    {
        $issues = Issue::where('tenant_id', $tenant->id)->orderByRaw("status in ('open', 'in_progress') desc")->latest()->limit(CloudApi::MAX_ROWS)->get();

        if ($issues->isEmpty()) {
            $this->wa->buttons($this->session->wa_id, "You haven't reported any issues.", ['issue_new' => 'Report an issue', 'menu' => 'Menu'], $tenant->id);

            return true;
        }

        $this->wa->list($this->session->wa_id, 'Your issues. Pick one to see its progress.', 'Issues',
            $issues->mapWithKeys(fn (Issue $i) => ['iss:'.$i->id => [$i->number, $i->statusLabel().' · '.$i->title]])->all(), $tenant->id);

        return true;
    }

    protected function issueShow(Tenant $tenant, int $issueId): bool
    {
        $issue = Issue::where('tenant_id', $tenant->id)->find($issueId);
        if (!$issue) {
            return false;
        }

        $updates = $issue->updates()->whereNotNull('note')->where('note', '!=', 'Reported')->latest('id')->limit(3)->get()->reverse();
        $lines = ["*{$issue->number}: {$issue->title}*", 'Status: '.$issue->statusLabel(), 'Reported '.$issue->created_at->format('j M Y')];
        foreach ($updates as $update) {
            $who = $update->author === 'tenant' ? 'You' : $this->landlordName($tenant);
            $lines[] = '';
            $lines[] = "{$update->created_at->format('j M')} · {$who}: {$update->note}";
        }

        $this->wa->buttons($this->session->wa_id, implode("\n", $lines), ['issues' => 'My issues', 'menu' => 'Menu'], $tenant->id);

        return true;
    }

    // --- Helpers ------------------------------------------------------------------------------

    protected function landlordName(Tenant $tenant): string
    {
        return $tenant->landlord->trading_name ?: $tenant->landlord->name;
    }

    protected function ugx(float|int $amount): string
    {
        return 'UGX '.number_format((float) $amount);
    }

    /** 256772123456 → 0772 123 456 */
    protected function localNumber(string $msisdn): string
    {
        $local = '0'.substr($msisdn, 3);

        return substr($local, 0, 4).' '.substr($local, 4, 3).' '.substr($local, 7);
    }
}
