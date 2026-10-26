<?php

namespace App\Http\Controllers;

use App\Models\Landlord;
use App\Models\PaymentTransaction;
use App\Payments\PaymentService;
use App\Services\CurrentLandlord;

/**
 * Status of one of the current landlord's payments (subscription, SMS
 * credits, …), polled by the page while it awaits approval on the phone.
 * Also nudges a status check with the gateway (at most every 5 seconds), so
 * the page updates even when the gateway's notification can't reach us.
 */
class LandlordPaymentController extends Controller
{
    public function status(string $reference, PaymentService $payments)
    {
        $transaction = PaymentTransaction::forLandlord(CurrentLandlord::id())
            ->where('reference', $reference)
            ->firstOrFail();

        if ($transaction->isOpen() && (!$transaction->last_checked_at || $transaction->last_checked_at->lt(now()->subSeconds(5)))) {
            try {
                $transaction = $payments->refresh($transaction);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $landlord = Landlord::find($transaction->landlord_id);

        return response()->json([
            'reference' => $transaction->reference,
            'purpose' => $transaction->purpose,
            'status' => $transaction->status,
            'message' => $transaction->status_message,
            'receipt' => $transaction->receipt_number,
            'period_end' => $transaction->isFinal() && $transaction->purpose === 'subscription' ? $landlord?->current_period_end?->format('d M Y') : null,
            'sms_balance' => $transaction->purpose === 'sms' ? $landlord?->sms_balance : null,
        ]);
    }
}
