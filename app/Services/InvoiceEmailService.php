<?php

namespace App\Services;

use App\Mail\InvoiceMail;
use App\Models\Customer;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class InvoiceEmailService
{
    /**
     * Fire-and-forget send used by automatic flows (invoice creation, recurring
     * generation) — failures are logged, never surfaced to the caller.
     */
    public function invoiceCreated(Invoice $invoice): void
    {
        $this->send($invoice);
    }

    /**
     * Send (or resend) the invoice PDF to its payer, reporting back whether it
     * went out and why not, for callers that need to show the user feedback.
     *
     * @return array{sent: bool, reason: ?string}
     */
    public function send(Invoice $invoice): array
    {
        if ($invoice->invoiceable_type === Customer::class) {
            $payer = $invoice->invoiceable;
        } else {
            $payer = $invoice->lease?->tenant;
        }

        $email = $payer?->email;

        if (!$email) {
            return ['sent' => false, 'reason' => 'The payer has no email address on file.'];
        }

        try {
            $invoice->loadMissing(['landlord', 'lease.unit.estate']);

            $pdfContent = Pdf::loadView('pdfs.invoice', ['invoice' => $invoice])->output();

            Mail::to($email)->send(new InvoiceMail($invoice, $payer->name, $pdfContent));

            return ['sent' => true, 'reason' => null];
        } catch (\Throwable $e) {
            Log::error("Invoice email failed for invoice {$invoice->id}: {$e->getMessage()}");

            return ['sent' => false, 'reason' => 'The email could not be sent. Please try again.'];
        }
    }
}
