<?php

namespace App\Services;

use App\Jobs\SendWhatsAppAlert;
use App\Models\Landlord;
use App\Models\SmsLog;
use App\Models\Tenant;
use App\Payments\Msisdn;
use App\WhatsApp\CloudApi;
use App\WhatsApp\Templates;
use Illuminate\Support\Facades\DB;

/**
 * Tenant alerts by WhatsApp, with SMS as the fallback. Used by
 * SmsNotificationService: each alert first asks queue() to send it by
 * WhatsApp, and goes by SMS when that returns null.
 *
 * An alert goes by WhatsApp only when all of these hold: the landlord has
 * switched WhatsApp alerts on (and isn't read-only), the tenant agreed and
 * hasn't stopped them, WhatsApp hasn't recently said their number can't get
 * messages, the alert's template is approved by Meta, and the landlord has
 * WhatsApp credit. Otherwise it goes by SMS straight away.
 *
 * A WhatsApp alert that then fails (straight away, or later when Meta reports
 * it undelivered) gets its credit back and is sent by SMS instead, linked as
 * its resend. A tenant never gets the same alert both ways.
 */
class WhatsAppAlerts
{
    /** Alerts that never went by SMS: no SMS fallback when WhatsApp fails. */
    public const WHATSAPP_ONLY = ['issue_update'];

    /** Meta error codes meaning "this number can't get WhatsApp messages". */
    protected const UNREACHABLE = [131026];

    /** How long after that we try WhatsApp again. */
    public const RETRY_AFTER_DAYS = 30;

    public function __construct(protected CloudApi $wa, protected WhatsAppCredits $credits)
    {
    }

    /**
     * Sends the alert by WhatsApp (in the background) if it can go that way.
     * Returns its log row, or null when it should go by SMS.
     *
     * @param  list<string>  $params  the template's {{1}}, {{2}}…
     * @param  array{0: 'invoice'|'receipt', 1: int}|null  $document  a PDF to attach
     */
    public function queue(string $phone, string $message, int $tenantId, string $type, ?string $refType, ?int $refId, string $template, array $params, ?array $document = null): ?SmsLog
    {
        $landlordId = CurrentLandlord::id();
        $landlord = $landlordId ? Landlord::find($landlordId) : null;
        $tenant = Tenant::find($tenantId);
        $to = Msisdn::normalize($phone);

        if (!$landlord?->whatsapp_alerts || $landlord->isReadOnly() || !$tenant?->whatsAppAlertsOn() || !$to
            || !$this->wa->configured() || !Templates::approved($template)) {
            return null;
        }

        return DB::transaction(function () use ($landlordId, $to, $message, $tenantId, $type, $refType, $refId, $template, $params, $document) {
            $log = SmsLog::create([
                'phone' => $to, 'channel' => 'whatsapp', 'message' => $message, 'segments' => 1, 'credits_charged' => 1,
                'tenant_id' => $tenantId, 'notification_type' => $type, 'reference_type' => $refType, 'reference_id' => $refId,
                'status' => 'pending',
            ]);

            if (!$this->credits->reserve($landlordId, 1, $log)) {
                $log->delete(); // no WhatsApp credit: this alert goes by SMS

                return null;
            }

            SendWhatsAppAlert::dispatch($log->id, $template, $params, $document)->afterCommit();

            return $log;
        });
    }

    /** Meta accepted it: on its way to the tenant's phone. */
    public function accepted(SmsLog $log, string $wamid): void
    {
        $log->update(['status' => 'sent', 'wamid' => $wamid, 'error' => null]);
    }

    /** Delivered / read, reported later by Meta. Never goes backwards (read stays read). */
    public function progressed(SmsLog $log, string $status): void
    {
        $order = ['pending' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];
        if (isset($order[$status], $order[$log->status]) && $order[$status] > $order[$log->status]) {
            $log->update(['status' => $status]);
        }
    }

    /**
     * WhatsApp couldn't deliver it: give the credit back and send it by SMS
     * instead (charged as SMS). Safe to call twice; only the first counts.
     */
    public function failed(SmsLog $log, string $error, ?int $code = null): void
    {
        $claimed = DB::transaction(function () use ($log, $error) {
            $fresh = SmsLog::whereKey($log->id)->lockForUpdate()->first();
            if (!$fresh || $fresh->channel !== 'whatsapp' || $fresh->status === 'failed') {
                return false;
            }
            $fresh->update(['status' => 'failed', 'credits_charged' => 0, 'error' => mb_substr($error, 0, 500), 'resent_at' => now()]);
            $this->credits->release($fresh->landlord_id, 1, $fresh);

            return true;
        });
        if (!$claimed) {
            return;
        }

        if ($code !== null && in_array($code, self::UNREACHABLE, true) && $log->tenant_id) {
            Tenant::whereKey($log->tenant_id)->update(['whatsapp_failed_at' => now()]);
        }

        if (in_array($log->notification_type, self::WHATSAPP_ONLY, true)) {
            return;
        }

        $sms = app(SmsNotificationService::class)->fallback($log->refresh());
        $log->forceFill(['resent_log_id' => $sms?->id])->save();
    }
}
