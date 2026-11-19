<?php

namespace App\Http\Controllers;

use App\Jobs\SendSmsBroadcast;
use App\Models\Estate;
use App\Models\Landlord;
use App\Models\SmsBroadcast;
use App\Models\SmsLog;
use App\Services\CurrentLandlord;
use App\Services\SmsBroadcasts;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Bulk SMS: a landlord writes one message and it goes to all (or some of) their current tenants, personalised. */
class SmsBroadcastController extends Controller
{
    public function __construct(protected SmsBroadcasts $broadcasts)
    {
    }

    public function index()
    {
        return view('sms-broadcasts.index', [
            'broadcasts' => SmsBroadcast::with('createdBy')->latest('id')->paginate(15),
            'balance' => (int) Landlord::whereKey(CurrentLandlord::id())->value('sms_balance'),
        ]);
    }

    public function create(Request $request)
    {
        $landlord = Landlord::findOrFail(CurrentLandlord::id());
        ['recipients' => $recipients, 'without_phone' => $withoutPhone] = $this->broadcasts->recipients();

        // Starting from an earlier message ("Send again").
        $from = $request->integer('from') ? SmsBroadcast::find($request->integer('from')) : null;

        return view('sms-broadcasts.create', [
            'estates' => Estate::orderBy('name')->get(['id', 'name']),
            'recipients' => $recipients,
            'withoutPhone' => $withoutPhone,
            'balance' => (int) $landlord->sms_balance,
            'landlordName' => SmsBroadcasts::landlordName($landlord),
            'from' => $from,
        ]);
    }

    public function store(Request $request, \App\Actions\StartSmsBroadcast $start)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:5', 'max:'.SmsBroadcasts::MAX_LENGTH],
            'estates' => ['nullable', 'array'],
            'estates.*' => ['integer', Rule::exists('estates', 'id')->where('landlord_id', CurrentLandlord::id())],
            'owing_only' => ['nullable', 'boolean'],
        ], ['message.required' => 'Write the message you want to send.']);

        $broadcast = $start(
            Landlord::findOrFail(CurrentLandlord::id()), $request->user(), $data['message'],
            array_values(array_map('intval', $data['estates'] ?? [])), (bool) ($data['owing_only'] ?? false),
        );

        return redirect()->route('sms-broadcasts.show', $broadcast)
            ->with('success', 'Your message is being sent to '.number_format($broadcast->recipients_count).' '.($broadcast->recipients_count === 1 ? 'tenant' : 'tenants').'.');
    }

    public function show(SmsBroadcast $smsBroadcast)
    {
        $logs = SmsLog::with('tenant')->where('reference_type', 'SmsBroadcast')->where('reference_id', $smsBroadcast->id)
            ->when(request('status') === 'failed', fn ($q) => $q->where('status', '!=', 'sent'))
            ->orderBy('id')->paginate(50)->withQueryString();

        return view('sms-broadcasts.show', ['broadcast' => $smsBroadcast->load('createdBy'), 'logs' => $logs]);
    }
}
