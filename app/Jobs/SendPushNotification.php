<?php

namespace App\Jobs;

use App\Models\DeviceToken;
use App\Services\Push\FcmClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Sends one notification to every signed-in phone of the given owners (users or tenants). */
class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    /**
     * @param  class-string  $ownerType  User or Tenant
     * @param  int[]  $ownerIds
     * @param  array<string, string>  $data
     */
    public function __construct(public string $ownerType, public array $ownerIds, public string $title, public string $body, public array $data = [])
    {
        $this->afterCommit();
    }

    public function handle(FcmClient $fcm): void
    {
        if (!$fcm->configured()) {
            return;
        }

        $devices = DeviceToken::where('owner_type', $this->ownerType)->whereIn('owner_id', $this->ownerIds)->signedIn()->get();

        foreach ($devices as $device) {
            try {
                if ($fcm->send($device->token, $this->title, $this->body, $this->data) === FcmClient::GONE) {
                    $device->delete();
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
