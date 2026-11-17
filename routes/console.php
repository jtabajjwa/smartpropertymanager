<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Artisan::command('inspire', function () {
//     $this->comment(Inspiring::quote());
// })->purpose('Display an inspiring quote');

Schedule::command('invoices:generate-recurring')->dailyAt('00:01');
Schedule::command('sms:overdue-reminders')->dailyAt('09:00');
Schedule::command('sms:expiring-leases')->dailyAt('09:00');
Schedule::command('sms:upcoming-invoices')->dailyAt('09:00');
Schedule::command('subscriptions:reminders')->dailyAt('08:00');

// Backup for payment notifications: re-check open mobile-money payments with the gateway.
Schedule::command('payments:reconcile')->everyMinute()->withoutOverlapping();
// Payouts still being sent: ask Yo! (never resends).
Schedule::command('payouts:reconcile')->everyFiveMinutes()->withoutOverlapping();
// Yo! pool balance against the books, and landlord funds covered; emails staff on problems.
Schedule::command('reconcile:balances')->hourlyAt(7)->withoutOverlapping();
