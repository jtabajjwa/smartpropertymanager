<?php

namespace App\Console\Commands;

use App\Models\Lease;
use App\Services\SmsNotificationService;
use Illuminate\Console\Command;

class SendExpiringLeaseAlerts extends Command
{
    protected $signature = 'sms:expiring-leases';

    protected $description = 'Send SMS alerts for leases expiring soon';

    public function handle(SmsNotificationService $smsNotification): int
    {
        if (! config('services.marz_sms.enabled')) {
            $this->info('SMS is disabled. Skipping.');
            return self::SUCCESS;
        }

        // Send alerts at 30, 14, and 7 days before expiry
        $alertDays = [30, 14, 7];
        $dates = collect($alertDays)->map(fn ($d) => now()->addDays($d)->toDateString());

        $leases = Lease::with(['tenant', 'unit'])
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->whereIn('end_date', $dates)
            ->get();

        $sent = 0;
        foreach ($leases as $lease) {
            $smsNotification->leaseExpiring($lease);
            $sent++;
        }

        $this->info("Processed {$sent} expiring lease alerts.");
        return self::SUCCESS;
    }
}
