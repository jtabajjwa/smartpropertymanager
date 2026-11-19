<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\StartSmsBroadcast;
use App\Http\Controllers\Controller;
use App\Models\Landlord;
use App\Models\PaymentTransaction;
use App\Models\SmsBroadcast;
use App\Models\SmsBundle;
use App\Models\SmsCreditTransaction;
use App\Models\SmsLog;
use App\Payments\Msisdn;
use App\Payments\PaymentException;
use App\Payments\PaymentService;
use App\Services\CurrentLandlord;
use App\Services\SmsBroadcasts;
use App\Services\SmsCredits;
use App\Services\SmsNotificationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * SMS (admins, as on the web): credit balance and bundles, the SMS log and
 * resending failed messages, bulk SMS, and buying SMS by mobile money.
 */
class SmsController extends Controller
{
    /** Most resends in one request, so it answers in reasonable time. */
    public const RESEND_BATCH = 50;

    protected function landlord(): Landlord
    {
        return Landlord::findOrFail(CurrentLandlord::id());
    }

    public function overview(SmsCredits $credits, PaymentService $payments)
    {
        $landlord = $this->landlord();
        $resendable = SmsLog::resendable()->selectRaw('COUNT(*) as n, COALESCE(SUM(segments), 0) as credits')->first();

        return response()->json([
            'balance' => (int) $landlord->sms_balance,
            'low_threshold' => $credits->lowBalanceThreshold(),
            'used_this_month' => (int) -SmsCreditTransaction::where('landlord_id', $landlord->id)->whereIn('type', ['usage', 'refund'])
                ->where('created_at', '>=', now()->startOfMonth())->sum('quantity'),
            'sent_today' => SmsLog::whereDate('created_at', today())->where('status', 'sent')->count(),
            'failed' => SmsLog::whereIn('status', SmsLog::NOT_SENT)->whereNull('resent_at')->count(),
            'resendable' => ['count' => (int) $resendable->n, 'credits' => (int) $resendable->credits, 'days' => SmsLog::RESEND_DAYS],
            'bundles' => SmsBundle::where('is_active', true)->orderBy('sort_order')->orderBy('quantity')->get()
                ->map(fn (SmsBundle $b) => ['id' => $b->id, 'name' => $b->name, 'quantity' => (int) $b->quantity, 'price' => (float) $b->price]),
            'can_buy' => $payments->gatewayAvailable(),
            'default_phone' => request()->user()->phone ?? $landlord->contact_phone,
            'placeholders' => collect(SmsBroadcasts::PLACEHOLDERS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'max_length' => SmsBroadcasts::MAX_LENGTH,
        ]);
    }

    public function logs(Request $request)
    {
        $search = trim((string) $request->input('search'));
        $logs = SmsLog::with('tenant')
            ->when($request->input('status') === 'failed', fn ($q) => $q->whereIn('status', SmsLog::NOT_SENT))
            ->when($request->input('status') === 'sent', fn ($q) => $q->where('status', 'sent'))
            ->when($request->input('tenant_id'), fn ($q, $id) => $q->where('tenant_id', $id))
            ->when($search, fn ($q) => $q->where(fn ($q) => $q->where('phone', 'like', "%{$search}%")->orWhere('message', 'like', "%{$search}%")
                ->orWhereHas('tenant', fn ($t) => $t->where('name', 'like', "%{$search}%"))))
            ->latest()->paginate(30);

        return response()->json($logs->through(fn (SmsLog $l) => [
            'id' => $l->id,
            'tenant' => $l->tenant?->name,
            'tenant_id' => $l->tenant_id,
            'phone' => $l->phone,
            'message' => $l->message,
            'type' => $l->notification_type,
            'status' => $l->status,
            'error' => $l->error,
            'segments' => (int) $l->segments,
            'can_resend' => $l->canResend(),
            'resent' => $l->resent_at !== null,
            'at' => $l->created_at?->toIso8601String(),
        ]));
    }

    public function resend(SmsLog $smsLog, SmsNotificationService $sms)
    {
        if (!$smsLog->canResend()) {
            throw ValidationException::withMessages(['sms' => 'That SMS was sent, or has already been resent.']);
        }
        if ((int) $smsLog->segments > (int) $this->landlord()->sms_balance) {
            throw ValidationException::withMessages(['sms' => 'Not enough SMS credit to resend it. Buy more SMS first.']);
        }

        $new = $sms->resend($smsLog);
        if ($new?->status !== 'sent') {
            throw ValidationException::withMessages(['sms' => 'The SMS could not be sent again'.($new?->error ? ': '.$new->error : '.')]);
        }

        return response()->json(['message' => 'SMS resent to '.$smsLog->phone.'.']);
    }

    public function resendFailed(SmsNotificationService $sms)
    {
        $logs = SmsLog::resendable()->orderBy('id')->limit(self::RESEND_BATCH)->get();
        if ($logs->isEmpty()) {
            return response()->json(['message' => 'There are no failed SMS to resend.', 'sent' => 0, 'failed' => 0, 'left' => 0]);
        }
        $balance = (int) $this->landlord()->sms_balance;
        if ((int) $logs->sum('segments') > $balance) {
            throw ValidationException::withMessages(['sms' => 'Resending needs '.number_format((int) $logs->sum('segments')).' SMS credits but you have '.number_format($balance).'. Buy more SMS first.']);
        }

        $sent = $failed = 0;
        foreach ($logs as $log) {
            $sms->resend($log)?->status === 'sent' ? $sent++ : $failed++;
        }
        $left = SmsLog::resendable()->count();

        return response()->json([
            'message' => number_format($sent).' SMS resent'.($failed ? ', '.number_format($failed).' failed again' : '').'.'.($left ? ' '.number_format($left).' more to resend.' : ''),
            'sent' => $sent, 'failed' => $failed, 'left' => $left,
        ]);
    }

    public function broadcasts()
    {
        $broadcasts = SmsBroadcast::with('createdBy')->latest('id')->paginate(30);

        return response()->json($broadcasts->through(fn (SmsBroadcast $b) => self::broadcast($b)));
    }

    public function broadcastShow(SmsBroadcast $smsBroadcast)
    {
        $logs = SmsLog::with('tenant')->where('reference_type', 'SmsBroadcast')->where('reference_id', $smsBroadcast->id)->orderBy('id')->limit(200)->get();

        return response()->json(self::broadcast($smsBroadcast->load('createdBy')) + [
            'recipients' => $logs->map(fn (SmsLog $l) => ['id' => $l->id, 'tenant' => $l->tenant?->name, 'phone' => $l->phone, 'status' => $l->status, 'error' => $l->error, 'can_resend' => $l->canResend()]),
        ]);
    }

    /** Who it would reach, the credits it needs, and the message as the first tenant would read it. */
    public function preview(Request $request, StartSmsBroadcast $start)
    {
        $data = $this->validatedBroadcast($request, preview: true);

        return response()->json($start->preview($this->landlord(), (string) ($data['message'] ?? ''), $data['estates'], $data['owing_only']));
    }

    public function broadcastStore(Request $request, StartSmsBroadcast $start)
    {
        $data = $this->validatedBroadcast($request);
        $broadcast = $start($this->landlord(), $request->user(), $data['message'], $data['estates'], $data['owing_only']);

        return response()->json(self::broadcast($broadcast->load('createdBy')), 201);
    }

    /** Buy an SMS bundle by mobile money: a prompt goes to the phone; poll /payments/{reference} for the result. */
    public function buy(Request $request, PaymentService $payments)
    {
        $data = $request->validate(['bundle_id' => ['required', 'exists:sms_bundles,id'], 'phone' => ['required', 'string', 'max:20']]);

        $bundle = SmsBundle::where('is_active', true)->find($data['bundle_id']);
        if (!$bundle) {
            throw ValidationException::withMessages(['bundle_id' => 'That bundle is no longer available. Please choose another.']);
        }
        $msisdn = Msisdn::normalize($data['phone']);
        if (!$msisdn) {
            throw ValidationException::withMessages(['phone' => 'Enter a Ugandan mobile money number, e.g. 0772 123 456.']);
        }
        $landlord = $this->landlord();
        if (PaymentTransaction::forLandlord($landlord->id)->where('purpose', 'sms')->open()->where('created_at', '>', now()->subMinutes(3))->exists()) {
            throw ValidationException::withMessages(['phone' => 'A payment request is already waiting for approval on a phone. Approve or decline it first, or try again in a few minutes.']);
        }

        try {
            $t = $payments->initiate(
                purpose: 'sms', landlord: $landlord, amount: (float) $bundle->price, msisdn: $msisdn,
                context: ['bundle_id' => $bundle->id, 'bundle_name' => $bundle->name, 'quantity' => $bundle->quantity],
                payable: $landlord, initiator: $request->user(),
            );
        } catch (PaymentException $e) {
            throw ValidationException::withMessages(['phone' => $e->getMessage()]);
        }

        return response()->json([
            'reference' => $t->reference,
            'status' => $t->status,
            'message' => $t->status === PaymentTransaction::SUCCEEDED ? "Payment received: {$bundle->quantity} SMS added." : 'Approve the payment on '.$t->displayMsisdn().'.',
        ], 201);
    }

    /** @return array{message: ?string, estates: int[], owing_only: bool} */
    protected function validatedBroadcast(Request $request, bool $preview = false): array
    {
        $data = $request->validate([
            'message' => [$preview ? 'nullable' : 'required', 'string', $preview ? 'min:0' : 'min:5', 'max:'.SmsBroadcasts::MAX_LENGTH],
            'estates' => ['nullable', 'array'],
            'estates.*' => ['integer', Rule::exists('estates', 'id')->where('landlord_id', CurrentLandlord::id())],
            'owing_only' => ['nullable', 'boolean'],
        ], ['message.required' => 'Write the message you want to send.']);

        return ['message' => $data['message'] ?? null, 'estates' => array_values(array_map('intval', $data['estates'] ?? [])), 'owing_only' => (bool) ($data['owing_only'] ?? false)];
    }

    public static function broadcast(SmsBroadcast $b): array
    {
        return [
            'id' => $b->id,
            'message' => $b->message,
            'audience' => $b->audienceLabel(),
            'status' => $b->status,
            'recipient_count' => (int) $b->recipients_count,
            'sent' => (int) $b->sent_count,
            'failed' => (int) $b->failed_count,
            'credits_used' => (int) $b->credits_used,
            'by' => $b->createdBy?->name,
            'at' => $b->created_at?->toIso8601String(),
            'completed_at' => $b->completed_at?->toIso8601String(),
        ];
    }
}
