<?php

namespace App\Console\Commands;

use App\Models\Landlord;
use App\Models\Lease;
use App\Services\CurrentLandlord;
use App\Services\InvoicingService;
use App\Services\SmsNotificationService;
use Illuminate\Console\Command;

class GenerateRecurringInvoices extends Command
{
    protected $signature = 'invoices:generate-recurring';
    protected $description = 'Generate recurring rent invoices for active leases where next_invoice_date has arrived';

    public function handle(InvoicingService $invoicingService, SmsNotificationService $sms): void
    {
        $generated = 0;
        $leaseCount = 0;

        foreach (Landlord::where('status', 'active')->cursor() as $landlord) {
            CurrentLandlord::set($landlord->id);

            try {
                $leases = Lease::where('status', 'active')
                    ->where('next_invoice_date', '<=', today())
                    ->where(function ($q) {
                        $q->whereNull('end_date')
                          ->orWhere('end_date', '>=', today());
                    })
                    ->get();

                $leaseCount += $leases->count();

                foreach ($leases as $lease) {
                    try {
                        // Catch up on any missed invoices (e.g. if system was down)
                        while ($lease->next_invoice_date && $lease->next_invoice_date <= today()) {
                            $invoice = $invoicingService->createRecurringInvoice($lease);
                            $lease->refresh();

                            try {
                                $sms->invoiceCreated($invoice->load('lease.tenant'));
                            } catch (\Throwable $e) {
                                // SMS failure should not block invoice generation
                            }

                            $generated++;
                        }
                    } catch (\Throwable $e) {
                        $this->error("Failed for lease {$lease->number}: {$e->getMessage()}");
                    }
                }
            } finally {
                CurrentLandlord::clear();
            }
        }

        $this->info("Generated {$generated} recurring invoice(s) for {$leaseCount} lease(s).");
    }
}
