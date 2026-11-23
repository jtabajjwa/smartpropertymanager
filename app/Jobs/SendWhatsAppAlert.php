<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Scopes\LandlordScope;
use App\Models\SmsLog;
use App\Services\CurrentLandlord;
use App\Services\PaymentReceipt;
use App\Services\WhatsAppAlerts;
use App\WhatsApp\CloudApi;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends one tenant alert by WhatsApp template (see WhatsAppAlerts), making the
 * invoice / receipt PDF it carries. Off the request, because the PDF and the
 * upload take a few seconds. If WhatsApp refuses it, it goes by SMS.
 */
class SendWhatsAppAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Never retried: a retry could reach the tenant twice. Failures fall back to SMS instead. */
    public int $tries = 1;

    public function __construct(public int $logId, public string $template, public array $params, public ?array $document = null)
    {
    }

    public function handle(CloudApi $wa, WhatsAppAlerts $alerts): void
    {
        $log = SmsLog::withoutGlobalScope(LandlordScope::class)->find($this->logId);
        if (!$log || $log->status !== 'pending') {
            return;
        }

        CurrentLandlord::runAs($log->landlord_id, function () use ($log, $wa, $alerts) {
            try {
                $document = $this->document();
                $result = $wa->template($log->phone, $this->template, $this->params, $document, $log->tenant_id);
            } catch (\Throwable $e) {
                report($e);
                $result = ['ok' => false, 'wamid' => null, 'error' => $e->getMessage()];
            }

            $result['ok'] ? $alerts->accepted($log, $result['wamid']) : $alerts->failed($log, 'WhatsApp: '.($result['error'] ?? 'not accepted'));
        });
    }

    /** The PDF to attach, made now so it shows the latest payments. */
    protected function document(): ?array
    {
        [$kind, $id] = $this->document ?? [null, null];

        if ($kind === 'invoice' && ($invoice = Invoice::with(['landlord', 'lease.unit.estate'])->find($id))) {
            return ['contents' => Pdf::loadView('pdfs.invoice', ['invoice' => $invoice])->output(), 'filename' => "invoice-{$invoice->number}.pdf"];
        }
        if ($kind === 'receipt' && ($payment = Payment::find($id))) {
            return ['contents' => app(PaymentReceipt::class)->pdf($payment)->output(), 'filename' => PaymentReceipt::filename($payment)];
        }

        return null;
    }
}
