<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Landlord;
use App\Services\CurrentLandlord;
use App\Services\SmsNotificationService;
use Illuminate\Console\Command;

class SendUpcomingInvoiceReminders extends Command
{
    protected $signature = 'sms:upcoming-invoices';

    protected $description = 'Send SMS reminders for invoices due soon';

    public function handle(SmsNotificationService $smsNotification): int
    {
        if (! config('services.marz_sms.enabled')) {
            $this->info('SMS is disabled. Skipping.');
            return self::SUCCESS;
        }

        // Send reminder 3 days before due date
        $dueDate = now()->addDays(3)->toDateString();

        $sent = 0;

        foreach (Landlord::where('status', 'active')->cursor() as $landlord) {
            CurrentLandlord::set($landlord->id);

            try {
                $invoices = Invoice::with('lease.tenant')
                    ->whereIn('status', ['unpaid', 'partial'])
                    ->whereDate('due_date', $dueDate)
                    ->get();

                foreach ($invoices as $invoice) {
                    $smsNotification->invoiceUpcoming($invoice);
                    $sent++;
                }
            } finally {
                CurrentLandlord::clear();
            }
        }

        $this->info("Processed {$sent} upcoming invoice reminders.");
        return self::SUCCESS;
    }
}
