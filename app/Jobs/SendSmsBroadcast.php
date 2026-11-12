<?php

namespace App\Jobs;

use App\Models\Landlord;
use App\Models\SmsBroadcast;
use App\Services\CurrentLandlord;
use App\Services\SmsBroadcasts;
use App\Services\SmsNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Sends a landlord's bulk SMS in the background, one message per tenant phone. */
class SendSmsBroadcast implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Never retried: a retry would text tenants twice. */
    public int $tries = 1;

    public int $timeout = 3000;

    public function __construct(public int $broadcastId, public int $landlordId)
    {
    }

    public function handle(SmsBroadcasts $broadcasts, SmsNotificationService $sms): void
    {
        CurrentLandlord::runAs($this->landlordId, function () use ($broadcasts, $sms) {
            // Claim it, so it can only ever be sent once.
            if (!SmsBroadcast::whereKey($this->broadcastId)->where('status', 'queued')->update(['status' => 'sending', 'started_at' => now()])) {
                return;
            }

            $broadcast = SmsBroadcast::findOrFail($this->broadcastId);
            $landlordName = SmsBroadcasts::landlordName(Landlord::findOrFail($this->landlordId));
            $recipients = $broadcasts->recipients($broadcast->audience['estates'] ?? [], (bool) ($broadcast->audience['owing_only'] ?? false))['recipients'];

            $broadcast->update(['recipients_count' => $recipients->count()]);

            $sent = $failed = $credits = 0;
            foreach ($recipients as $i => $r) {
                $log = $sms->broadcast($r['phone'], $broadcasts->render($broadcast->message, $r, $landlordName), $r['tenant_id'], $broadcast->id);

                if ($log?->status === 'sent') {
                    $sent++;
                    $credits += (int) $log->credits_charged;
                } else {
                    $failed++;
                }

                if ($i % 20 === 19) { // progress for the screen
                    $broadcast->update(['sent_count' => $sent, 'failed_count' => $failed, 'credits_used' => $credits]);
                }
            }

            $broadcast->update(['sent_count' => $sent, 'failed_count' => $failed, 'credits_used' => $credits, 'status' => 'completed', 'completed_at' => now()]);
        });
    }

    public function failed(\Throwable $e): void
    {
        SmsBroadcast::withoutGlobalScopes()->whereKey($this->broadcastId)->where('status', 'sending')
            ->update(['status' => 'completed', 'completed_at' => now()]);
    }
}
