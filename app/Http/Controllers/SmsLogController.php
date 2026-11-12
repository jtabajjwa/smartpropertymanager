<?php

namespace App\Http\Controllers;

use App\Models\Landlord;
use App\Models\SmsLog;
use App\Services\CurrentLandlord;
use App\Services\SmsNotificationService;

class SmsLogController extends Controller
{
    public function index()
    {
        $query = SmsLog::with(['tenant', 'resentAs'])->latest();

        if (request('status')) {
            $query->where('status', request('status'));
        }

        if (request('notification_type')) {
            $query->where('notification_type', request('notification_type'));
        }

        if (request('tenant_id')) {
            $query->where('tenant_id', request('tenant_id'));
        }

        if (request('date_from')) {
            $query->whereDate('created_at', '>=', request('date_from'));
        }

        if (request('date_to')) {
            $query->whereDate('created_at', '<=', request('date_to'));
        }

        if (request('search')) {
            $search = request('search');
            $query->where(function ($q) use ($search) {
                $q->where('phone', 'like', "%{$search}%")
                  ->orWhere('message', 'like', "%{$search}%")
                  ->orWhereHas('tenant', fn ($t) => $t->where('name', 'like', "%{$search}%"));
            });
        }

        $logs = $query->paginate(20)->withQueryString();

        // Summary stats
        $stats = (object) [
            'total' => SmsLog::count(),
            'sent' => SmsLog::where('status', 'sent')->count(),
            'failed' => SmsLog::whereIn('status', SmsLog::NOT_SENT)->whereNull('resent_at')->count(),
            'today' => SmsLog::whereDate('created_at', today())->count(),
        ];

        // The landlord's own prepaid SMS credit (not the provider account).
        $balance = (int) \App\Models\Landlord::whereKey(\App\Services\CurrentLandlord::id())->value('sms_balance');

        // Failed messages that can still be resent, and the credit that would take.
        $resendable = SmsLog::resendable()->selectRaw('COUNT(*) as n, COALESCE(SUM(segments), 0) as credits')->first();

        return view('sms-logs.index', compact('logs', 'stats', 'balance', 'resendable'));
    }

    /** Send one failed SMS again. */
    public function resend(SmsLog $smsLog, SmsNotificationService $sms)
    {
        if ($error = $this->cannotSend()) {
            return back()->with('error', $error);
        }
        if (!$smsLog->canResend()) {
            return back()->with('error', 'That SMS was sent, or has already been resent.');
        }
        if ((int) $smsLog->segments > $this->balance()) {
            return back()->with('error', 'Not enough SMS credit to resend it. Buy more SMS first.');
        }

        $new = $sms->resend($smsLog);

        return $new?->status === 'sent'
            ? back()->with('success', 'SMS resent to '.$smsLog->phone.'.')
            : back()->with('error', 'The SMS could not be sent again'.($new?->error ? ': '.$new->error : '.'));
    }

    /** Resend every failed SMS from the last SmsLog::RESEND_DAYS days (oldest first, up to RESEND_BATCH per click). */
    public function resendFailed(SmsNotificationService $sms)
    {
        if ($error = $this->cannotSend()) {
            return back()->with('error', $error);
        }

        $logs = SmsLog::resendable()->orderBy('id')->limit(self::RESEND_BATCH)->get();
        if ($logs->isEmpty()) {
            return back()->with('success', 'There are no failed SMS to resend.');
        }
        if ((int) $logs->sum('segments') > $this->balance()) {
            return back()->with('error', 'Resending needs '.number_format((int) $logs->sum('segments')).' SMS credits but you have '.number_format($this->balance()).'. Buy more SMS first.');
        }

        $sent = $failed = 0;
        foreach ($logs as $log) {
            $new = $sms->resend($log);
            $new?->status === 'sent' ? $sent++ : $failed++;
        }
        $left = SmsLog::resendable()->count();

        $message = number_format($sent).' SMS resent'.($failed ? ', '.number_format($failed).' failed again' : '').'.'
            .($left ? ' '.number_format($left).' more to resend: click "Resend all failed" again.' : '');

        return back()->with($failed && !$sent ? 'error' : 'success', $message);
    }

    /** Most resends in one click, so the page answers in reasonable time. */
    protected const RESEND_BATCH = 50;

    protected function balance(): int
    {
        return (int) Landlord::whereKey(CurrentLandlord::id())->value('sms_balance');
    }

    protected function cannotSend(): ?string
    {
        return Landlord::find(CurrentLandlord::id())?->isReadOnly()
            ? 'Your account is read-only until a plan is chosen, so SMS can\'t be sent.'
            : null;
    }
}
