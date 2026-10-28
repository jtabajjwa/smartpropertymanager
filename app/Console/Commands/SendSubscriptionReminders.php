<?php

namespace App\Console\Commands;

use App\Mail\NoticeMail;
use App\Models\Landlord;
use App\Models\User;
use App\Services\SubscriptionAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails a landlord's admins as their trial or plan runs out: 7 days and
 * 1 day before it ends, the day grace starts, and the day the account
 * becomes read-only (see SubscriptionAccess). Run once a day.
 */
class SendSubscriptionReminders extends Command
{
    protected $signature = 'subscriptions:reminders';

    protected $description = 'Email landlords whose trial or plan is ending, has ended, or has gone read-only';

    public function handle(): int
    {
        $sent = 0;

        $landlords = Landlord::where('status', 'active')->whereNotNull('current_period_end')
            ->where('subscription_status', '!=', 'cancelled')->cursor();

        foreach ($landlords as $landlord) {
            if (!$mail = $this->reminderFor($landlord)) {
                continue;
            }

            $to = User::withoutGlobalScopes()->where('landlord_id', $landlord->id)->where('role', 'admin')
                ->where('is_active', true)->whereNotNull('email')->pluck('email')
                ->push($landlord->contact_email)->filter()->map(fn ($e) => mb_strtolower(trim($e)))->unique()->values()->all();
            if (!$to) {
                continue;
            }

            try {
                Mail::to($to)->send($mail);
                $sent++;
            } catch (\Throwable $e) {
                Log::error("Subscription reminder to landlord {$landlord->id} failed: {$e->getMessage()}");
            }
        }

        $this->info("Sent {$sent} subscription reminder(s).");

        return self::SUCCESS;
    }

    protected function reminderFor(Landlord $landlord): ?NoticeMail
    {
        $end = $landlord->current_period_end;
        $graceEnd = SubscriptionAccess::graceEndsOn($landlord);
        $what = $landlord->subscription_status === 'trialing' ? 'free trial' : 'Primora plan';
        $name = $landlord->trading_name ?: $landlord->name;
        $url = route('subscription.show');
        $daysLeft = (int) today()->diffInDays($end, false);

        // With no grace days, the day after the end is already read-only, so that check comes first.
        return match (true) {
            $daysLeft === 7 || $daysLeft === 1 => new NoticeMail(
                "Your {$what} ends ".($daysLeft === 1 ? 'tomorrow' : 'in 7 days'),
                "Your {$what} ends on {$end->format('j M Y')}",
                [
                    "Hi {$name}, your Primora {$what} ends ".($daysLeft === 1 ? 'tomorrow' : 'in 7 days').'.',
                    'Choose a plan to keep adding tenants, raising invoices and sending SMS without interruption.',
                ],
                'Choose a plan', $url,
            ),
            $graceEnd->copy()->addDay()->isToday() => new NoticeMail(
                'Your Primora account is now read-only',
                'Your account is now read-only',
                [
                    "Hi {$name}, your Primora {$what} ended on {$end->format('j M Y')} and the grace period is over.",
                    'Nothing has been deleted. You can still view and export everything, tenants can still pay, and payouts still work. To add or change records and send SMS again, choose a plan: full access returns as soon as it is paid.',
                ],
                'Choose a plan', $url,
            ),
            $daysLeft === -1 => new NoticeMail(
                "Your {$what} has ended",
                "Your {$what} ended on {$end->format('j M Y')}",
                [
                    "Hi {$name}, your Primora {$what} has ended.",
                    "Everything keeps working until {$graceEnd->format('j M Y')}. After that your account becomes read-only: you can still view and export everything, and tenants can still pay, but you won't be able to add or change records, and no SMS will be sent.",
                ],
                'Choose a plan', $url,
            ),
            default => null,
        };
    }
}
