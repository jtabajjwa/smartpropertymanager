<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PayoutController as WebPayouts;
use App\Models\Landlord;
use App\Models\Liquidation;
use App\Models\PayoutAccount;
use App\Models\User;
use App\Services\ActionCodes;
use App\Services\CurrentLandlord;
use App\Services\LiquidationException;
use App\Services\Liquidations;
use App\Services\PayoutSteps;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Payouts in the app (admins): what Primora holds for the landlord, payout
 * accounts, and requests to be paid. Adding an account and requesting a
 * payout are confirmed with a code, exactly as on the web (PayoutSteps); the
 * step waiting for its code is kept on the server, per user, for 10 minutes.
 */
class PayoutController extends Controller
{
    public function __construct(protected Liquidations $liquidations, protected PayoutSteps $steps)
    {
    }

    public function index(Request $request)
    {
        $landlord = $this->landlord();
        $user = $request->user();

        return response()->json([
            'collects' => (bool) $landlord->collect_payments,
            'owed' => $this->liquidations->owed($landlord->id),
            'reserved' => $this->liquidations->reserved($landlord->id),
            'available' => $this->liquidations->available($landlord->id),
            'paid_out' => (float) Liquidation::where('status', Liquidation::COMPLETED)->sum('net_amount'),
            'min' => Liquidations::MIN_AMOUNT,
            'mobile_max' => Liquidations::MOBILE_MONEY_MAX,
            'mobile_limit' => PayoutAccount::mobileMoneyLimit(),
            'mobile_in_use' => PayoutAccount::mobileMoneyInUse(),
            'code_destinations' => ActionCodes::destinations($user),
            'accounts' => PayoutAccount::where('status', '!=', 'disabled')->latest()->get()->map(fn (PayoutAccount $a) => [
                'id' => $a->id, 'type' => $a->type, 'summary' => $a->summary(), 'status' => $a->status, 'status_label' => $a->statusLabel(), 'usable' => $a->status === 'verified',
            ])->values(),
            'payouts' => Liquidation::latest('id')->limit(30)->get()->map(fn (Liquidation $l) => self::payout($l))->values(),
            'pending_payout' => $this->pending($user, 'payout'),
            'pending_account' => $this->pending($user, 'account'),
        ]);
    }

    /** The transfer charge and what would arrive, before asking for a code. */
    public function quote(Request $request)
    {
        $data = $request->validate(['payout_account_id' => ['required', 'integer'], 'amount' => ['required', 'numeric', 'min:1']]);
        $account = PayoutAccount::find($data['payout_account_id']);

        try {
            if (!$account) {
                throw new LiquidationException('Choose one of your payout accounts.');
            }
            $quote = $this->liquidations->check($this->landlord(), $account, (float) $data['amount']);
        } catch (LiquidationException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return response()->json(['amount' => round((float) $data['amount']), 'fee' => $quote['fee'], 'net' => $quote['net']]);
    }

    // --- Requesting a payout -----------------------------------------------------------

    public function request(Request $request)
    {
        $data = $request->validate([
            'payout_account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:'.Liquidations::MIN_AMOUNT],
            'note' => ['nullable', 'string', 'max:300'],
            'password' => ['required', 'current_password:sanctum'],
        ], [
            'password.current_password' => 'That password is not correct.',
            'amount.min' => 'The smallest payout is UGX '.number_format(Liquidations::MIN_AMOUNT).'.',
        ]);

        $pending = $this->step(fn () => $this->steps->startPayout($request->user(), $this->landlord(), (int) $data['payout_account_id'], (float) $data['amount'], $data['note'] ?? null, $request->ip()));
        $this->keep($request->user(), 'payout', $pending);

        return response()->json(['message' => 'We sent a code to '.ActionCodes::destinations($request->user()).'. Enter it to send the request.', 'pending' => $this->public($pending)]);
    }

    public function confirmRequest(Request $request)
    {
        $request->validate(['code' => ['required', 'string', 'max:12']], ['code.required' => 'Enter the code we sent you.']);
        $pending = $this->pendingOrFail($request->user(), 'payout', 'That request has expired. Please request the payout again.');

        try {
            $liquidation = $this->steps->confirmPayout($request->user(), $this->landlord(), $pending, (string) $request->input('code'));
        } catch (LiquidationException $e) {
            $this->forget($request->user(), 'payout');
            throw ValidationException::withMessages(['request' => $e->getMessage()]);
        }
        $this->forget($request->user(), 'payout');

        return response()->json(['message' => 'Payout request received. Primora will review it and email you as it moves.', 'payout' => self::payout($liquidation)], 201);
    }

    public function resendRequestCode(Request $request)
    {
        $pending = $this->pendingOrFail($request->user(), 'payout', 'That request has expired. Please request the payout again.');
        $this->keep($request->user(), 'payout', $this->step(fn () => $this->steps->resendPayoutCode($request->user(), $pending, $request->ip())));

        return response()->json(['message' => 'A new code was sent to '.ActionCodes::destinations($request->user()).'.']);
    }

    public function cancelPendingRequest(Request $request)
    {
        $this->forget($request->user(), 'payout');

        return response()->json(['message' => 'Cancelled.']);
    }

    /** Cancel a request Primora hasn't acted on yet. */
    public function cancel(Liquidation $liquidation)
    {
        $this->step(fn () => $this->liquidations->cancel($liquidation));

        return response()->json(['message' => "Payout request {$liquidation->reference} cancelled.", 'payout' => self::payout($liquidation->fresh())]);
    }

    // --- Payout accounts -----------------------------------------------------------------

    public function storeAccount(Request $request)
    {
        $data = $request->validate(WebPayouts::accountRules('sanctum'), WebPayouts::accountMessages());
        $pending = $this->step(fn () => $this->steps->startAccount($request->user(), $this->landlord(), $data, $request->ip()));
        $this->keep($request->user(), 'account', $pending);

        return response()->json(['message' => 'We sent a code to '.ActionCodes::destinations($request->user()).'. Enter it to finish adding the account.', 'pending' => $this->public($pending)]);
    }

    public function confirmAccount(Request $request)
    {
        $request->validate(['code' => ['required', 'string', 'max:12']], ['code.required' => 'Enter the code we sent you.']);
        $pending = $this->pendingOrFail($request->user(), 'account', 'That request has expired. Please add the account again.');

        try {
            $account = $this->steps->confirmAccount($request->user(), $this->landlord(), $pending, (string) $request->input('code'));
        } catch (LiquidationException $e) {
            $this->forget($request->user(), 'account');
            throw ValidationException::withMessages(['request' => $e->getMessage()]);
        }
        $this->forget($request->user(), 'account');

        return response()->json(['message' => 'Payout account added. Primora will verify it before it can be used.', 'account' => ['id' => $account->id, 'summary' => $account->summary(), 'status' => $account->status]], 201);
    }

    public function resendAccountCode(Request $request)
    {
        $pending = $this->pendingOrFail($request->user(), 'account', 'That request has expired. Please add the account again.');
        $this->keep($request->user(), 'account', $this->step(fn () => $this->steps->resendAccountCode($request->user(), $pending, $request->ip())));

        return response()->json(['message' => 'A new code was sent to '.ActionCodes::destinations($request->user()).'.']);
    }

    public function cancelPendingAccount(Request $request)
    {
        $this->forget($request->user(), 'account');

        return response()->json(['message' => 'Cancelled.']);
    }

    public function destroyAccount(PayoutAccount $payoutAccount)
    {
        $payoutAccount->update(['status' => 'disabled']);

        return response()->json(['message' => 'Payout account removed.']);
    }

    // ------------------------------------------------------------------------------------

    public static function payout(Liquidation $l): array
    {
        return [
            'id' => $l->id,
            'reference' => $l->reference,
            'amount' => (float) $l->amount,
            'fee' => (float) $l->fee,
            'net' => (float) $l->net_amount,
            'to' => $l->destinationSummary(),
            'status' => $l->status,
            'status_label' => $l->landlordStatus(),
            'can_cancel' => $l->status === Liquidation::PENDING,
            'note' => $l->status_message,
            'requested_at' => $l->created_at?->toIso8601String(),
            'completed_at' => $l->completed_at?->toIso8601String(),
        ];
    }

    protected function landlord(): Landlord
    {
        return Landlord::findOrFail(CurrentLandlord::id());
    }

    /** Run a step; a business problem becomes a 422 the app shows. */
    protected function step(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (LiquidationException $e) {
            throw ValidationException::withMessages(['request' => $e->getMessage()]);
        }
    }

    protected function key(User $user, string $kind): string
    {
        return "api.payout_steps.{$kind}.{$user->id}";
    }

    protected function keep(User $user, string $kind, array $pending): void
    {
        Cache::put($this->key($user, $kind), $pending, now()->addMinutes(ActionCodes::CODE_MINUTES + 1));
    }

    protected function forget(User $user, string $kind): void
    {
        Cache::forget($this->key($user, $kind));
    }

    protected function pending(User $user, string $kind): ?array
    {
        $pending = Cache::get($this->key($user, $kind));

        return PayoutSteps::stillValid($pending, $user, (int) CurrentLandlord::id()) ? $this->public($pending) : null;
    }

    protected function pendingOrFail(User $user, string $kind, string $expired): array
    {
        $pending = Cache::get($this->key($user, $kind));
        if (!PayoutSteps::stillValid($pending, $user, (int) CurrentLandlord::id())) {
            throw ValidationException::withMessages(['request' => $expired]);
        }

        return $pending;
    }

    /** What the app may show of a pending step. */
    protected function public(array $pending): array
    {
        return array_intersect_key($pending, array_flip(['summary', 'amount', 'fee', 'net', 'note', 'expires_at']));
    }
}
