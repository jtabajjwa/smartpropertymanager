<?php

namespace App\Actions;

use App\Jobs\SendSmsBroadcast;
use App\Models\Landlord;
use App\Models\SmsBroadcast;
use App\Models\User;
use App\Services\SmsBroadcasts;
use Illuminate\Validation\ValidationException;

/**
 * A bulk SMS to the landlord's tenants (all, some estates, or only those
 * owing), personalised per tenant, sent in the background. Checks there's
 * someone to send to and enough SMS credit first. Shared by the web portal
 * and the mobile app's API.
 */
class StartSmsBroadcast
{
    public function __construct(protected SmsBroadcasts $broadcasts)
    {
    }

    /**
     * What a send would do, without sending: who, how many credits, and the
     * message as the first tenant would get it.
     *
     * @param  int[]  $estateIds
     * @return array{recipients: int, without_phone: int, credits: int, balance: int, enough: bool, sample: ?string, sample_to: ?string}
     */
    public function preview(Landlord $landlord, string $message, array $estateIds, bool $owingOnly): array
    {
        ['recipients' => $recipients, 'without_phone' => $withoutPhone] = $this->broadcasts->recipients($estateIds, $owingOnly);
        $name = SmsBroadcasts::landlordName($landlord);
        $credits = trim($message) === '' ? 0 : $this->broadcasts->credits($message, $recipients, $name);
        $first = $recipients->first();

        return [
            'recipients' => $recipients->count(),
            'without_phone' => $withoutPhone,
            'credits' => $credits,
            'balance' => (int) $landlord->sms_balance,
            'enough' => $credits <= (int) $landlord->sms_balance,
            'sample' => $first && trim($message) !== '' ? $this->broadcasts->render($message, $first, $name) : null,
            'sample_to' => $first['name'] ?? null,
        ];
    }

    /** @param  int[]  $estateIds */
    public function __invoke(Landlord $landlord, User $by, string $message, array $estateIds, bool $owingOnly): SmsBroadcast
    {
        $recipients = $this->broadcasts->recipients($estateIds, $owingOnly)['recipients'];
        if ($recipients->isEmpty()) {
            throw ValidationException::withMessages(['audience' => 'No tenants with a phone number match who you chose to send to.']);
        }

        $needed = $this->broadcasts->credits($message, $recipients, SmsBroadcasts::landlordName($landlord));
        if ($needed > (int) $landlord->sms_balance) {
            throw ValidationException::withMessages(['audience' => 'This needs '.number_format($needed).' SMS credits but you have '.number_format((int) $landlord->sms_balance).'. Buy more SMS, or send to fewer tenants.']);
        }

        $broadcast = SmsBroadcast::create([
            'created_by_user_id' => $by->id,
            'message' => trim($message),
            'audience' => ['estates' => array_values($estateIds), 'owing_only' => $owingOnly],
            'recipients_count' => $recipients->count(),
            'status' => 'queued',
        ]);

        SendSmsBroadcast::dispatch($broadcast->id, $landlord->id);

        return $broadcast;
    }
}
