<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\HandleWhatsAppMessage;
use App\Models\Scopes\LandlordScope;
use App\Models\SmsLog;
use App\Models\WhatsAppMessage;
use App\Services\CurrentLandlord;
use App\Services\WhatsAppAlerts;
use App\WhatsApp\Templates;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Meta calls this for the tenant WhatsApp bot.
 *
 * GET: one-off check when the webhook is set up (echo the challenge if the
 * verify token matches). POST: incoming messages and delivery receipts,
 * signed with the app secret (X-Hub-Signature-256). Messages are handled in
 * the background so Meta gets its 200 straight away; Meta retries
 * deliveries, so each message id is only handled once.
 */
class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request)
    {
        $token = config('services.whatsapp.verify_token');

        if ($request->query('hub_mode') === 'subscribe' && filled($token) && hash_equals($token, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    public function receive(Request $request)
    {
        $secret = config('services.whatsapp.app_secret');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), (string) $secret);

        if (blank($secret) || !hash_equals($expected, (string) $request->header('X-Hub-Signature-256'))) {
            return response('Invalid signature', 403);
        }

        foreach ($request->input('entry', []) as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                // Meta approved / rejected / paused one of our alert templates.
                if (($change['field'] ?? null) === 'message_template_status_update') {
                    if (!empty($value['message_template_name']) && !empty($value['event'])) {
                        Templates::setStatus($value['message_template_name'], (string) $value['event']);
                    }

                    continue;
                }
                $names = collect($value['contacts'] ?? [])->mapWithKeys(fn ($c) => [$c['wa_id'] ?? '' => $c['profile']['name'] ?? null]);

                foreach ($value['messages'] ?? [] as $message) {
                    $this->incoming($message, $names[$message['from'] ?? ''] ?? null);
                }
                foreach ($value['statuses'] ?? [] as $status) {
                    $this->receipt($status);
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    protected function incoming(array $message, ?string $profileName): void
    {
        $wamid = $message['id'] ?? null;
        $from = preg_replace('/\D/', '', (string) ($message['from'] ?? ''));

        if (!$wamid || !$from || WhatsAppMessage::where('wamid', $wamid)->exists()) {
            return;
        }

        WhatsAppMessage::create([
            'wa_id' => $from, 'direction' => 'in', 'wamid' => $wamid, 'type' => Str::limit((string) ($message['type'] ?? 'unknown'), 20, ''),
            'body' => $message['text']['body'] ?? $message['interactive']['button_reply']['title'] ?? $message['interactive']['list_reply']['title'] ?? null,
            'payload' => $message,
        ]);

        HandleWhatsAppMessage::dispatch($from, $profileName, $message);
    }

    /** Delivered / read / failed, for a message we sent. */
    protected function receipt(array $status): void
    {
        if (empty($status['id']) || empty($status['status'])) {
            return;
        }

        WhatsAppMessage::where('wamid', $status['id'])->where('direction', 'out')->update(array_filter([
            'status' => Str::limit($status['status'], 20, ''),
            'error' => isset($status['errors']) ? Str::limit(json_encode($status['errors']), 2000) : null,
        ]));

        // A tenant alert: show its progress in the landlord's log; if it failed, send it by SMS.
        $log = SmsLog::withoutGlobalScope(LandlordScope::class)->where('wamid', $status['id'])->first();
        if ($log) {
            $alerts = app(WhatsAppAlerts::class);
            CurrentLandlord::runAs($log->landlord_id, fn () => $status['status'] === 'failed'
                ? $alerts->failed($log, 'WhatsApp: '.($status['errors'][0]['title'] ?? $status['errors'][0]['message'] ?? 'not delivered'), isset($status['errors'][0]['code']) ? (int) $status['errors'][0]['code'] : null)
                : $alerts->progressed($log, (string) $status['status']));
        }
    }
}
