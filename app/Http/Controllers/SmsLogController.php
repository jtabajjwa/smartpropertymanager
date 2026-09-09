<?php

namespace App\Http\Controllers;

use App\Models\SmsLog;
use App\Services\SmsService;

class SmsLogController extends Controller
{
    public function index()
    {
        $query = SmsLog::with('tenant')->latest();

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
            'failed' => SmsLog::where('status', 'failed')->count(),
            'today' => SmsLog::whereDate('created_at', today())->count(),
        ];

        $balance = app(SmsService::class)->balance();

        return view('sms-logs.index', compact('logs', 'stats', 'balance'));
    }
}
