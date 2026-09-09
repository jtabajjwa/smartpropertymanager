<?php

namespace App\Console\Commands;

use App\Models\Invoice;
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

        $invoices = Invoice::with('lease.tenant')
            ->whereIn('status', ['unpaid', 'partial'])
            ->whereDate('due_date', $dueDate)
            ->get();

        $sent = 0;
        foreach ($invoices as $invoice) {
            $smsNotification->invoiceUpcoming($invoice);
            $sent++;
        }

        $this->info("Processed {$sent} upcoming invoice reminders.");
        return self::SUCCESS;
    }
}
