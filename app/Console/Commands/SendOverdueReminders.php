<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\SmsNotificationService;
use Illuminate\Console\Command;

class SendOverdueReminders extends Command
{
    protected $signature = 'sms:overdue-reminders';

    protected $description = 'Send SMS reminders for overdue invoices';

    public function handle(SmsNotificationService $smsNotification): int
    {
        if (! config('services.marz_sms.enabled')) {
            $this->info('SMS is disabled. Skipping.');

            return self::SUCCESS;
        }

        // Send reminders on day 1, 3, 7, 14, and 30 after due date
        $reminderDays = []; // left blank for now, but can be easily re-enabled if we want to start with fewer reminders and add more later
        $dates = collect($reminderDays)->map(fn ($d) => now()->subDays($d)->toDateString());

        $invoices = Invoice::with('lease.tenant')
            ->whereIn('status', ['unpaid', 'partial'])
            ->whereIn('due_date', $dates)
            ->get();

        $sent = 0;
        foreach ($invoices as $invoice) {
            $smsNotification->invoiceOverdue($invoice);
            $sent++;
        }

        $this->info("Processed {$sent} overdue invoice reminders.");

        return self::SUCCESS;
    }
}
