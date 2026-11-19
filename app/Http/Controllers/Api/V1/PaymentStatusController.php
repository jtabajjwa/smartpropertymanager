<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Landlord;
use App\Models\PaymentTransaction;
use App\Payments\PaymentService;
use App\Services\CurrentLandlord;

/** How a mobile money payment the landlord started (SMS bundle, subscription) is doing; checks with the gateway at most every 5 seconds. */
class PaymentStatusController extends Controller
{
    public function __invoke(string $reference, PaymentService $payments)
    {
        $t = PaymentTransaction::forLandlord(CurrentLandlord::id())->where('reference', $reference)->firstOrFail();

        if ($t->isOpen() && (!$t->last_checked_at || $t->last_checked_at->lt(now()->subSeconds(5)))) {
            try {
                $t = $payments->refresh($t);
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $landlord = Landlord::find($t->landlord_id);

        return response()->json([
            'reference' => $t->reference,
            'purpose' => $t->purpose,
            'status' => $t->status,
            'open' => $t->isOpen(),
            'message' => $t->status_message,
            'sms_balance' => $t->purpose === 'sms' ? (int) $landlord?->sms_balance : null,
            'period_end' => $t->purpose === 'subscription' && $t->isFinal() ? $landlord?->current_period_end?->toDateString() : null,
        ]);
    }
}
