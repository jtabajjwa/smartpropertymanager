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
