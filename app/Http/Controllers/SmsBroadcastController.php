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

    public function store(Request $request)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:5', 'max:'.SmsBroadcasts::MAX_LENGTH],
            'estates' => ['nullable', 'array'],
            'estates.*' => ['integer', Rule::exists('estates', 'id')->where('landlord_id', CurrentLandlord::id())],
            'owing_only' => ['nullable', 'boolean'],
        ], ['message.required' => 'Write the message you want to send.']);

        $estates = array_values(array_map('intval', $data['estates'] ?? []));
        $owingOnly = (bool) ($data['owing_only'] ?? false);
        $landlord = Landlord::findOrFail(CurrentLandlord::id());
        $recipients = $this->broadcasts->recipients($estates, $owingOnly)['recipients'];

        if ($recipients->isEmpty()) {
            return back()->withInput()->withErrors(['audience' => 'No tenants with a phone number match who you chose to send to.']);
        }

        $needed = $this->broadcasts->credits($data['message'], $recipients, SmsBroadcasts::landlordName($landlord));
        if ($needed > (int) $landlord->sms_balance) {
            return back()->withInput()->withErrors(['audience' => 'This needs '.number_format($needed).' SMS credits but you have '.number_format((int) $landlord->sms_balance).'. Buy more SMS, or send to fewer tenants.']);
        }

        $broadcast = SmsBroadcast::create([
            'created_by_user_id' => $request->user()->id,
            'message' => trim($data['message']),
            'audience' => ['estates' => $estates, 'owing_only' => $owingOnly],
            'recipients_count' => $recipients->count(),
            'status' => 'queued',
        ]);

        SendSmsBroadcast::dispatch($broadcast->id, $landlord->id);

        return redirect()->route('sms-broadcasts.show', $broadcast)
            ->with('success', 'Your message is being sent to '.number_format($recipients->count()).' '.($recipients->count() === 1 ? 'tenant' : 'tenants').'.');
    }

    public function show(SmsBroadcast $smsBroadcast)
    {
        $logs = SmsLog::with('tenant')->where('reference_type', 'SmsBroadcast')->where('reference_id', $smsBroadcast->id)
            ->when(request('status') === 'failed', fn ($q) => $q->where('status', '!=', 'sent'))
            ->orderBy('id')->paginate(50)->withQueryString();

        return view('sms-broadcasts.show', ['broadcast' => $smsBroadcast->load('createdBy'), 'logs' => $logs]);
    }
}
