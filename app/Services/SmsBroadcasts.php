<?php

namespace App\Services;

use App\Models\Landlord;
use App\Models\Lease;
use App\Payments\Msisdn;
use Illuminate\Support\Collection;

/**
 * Bulk SMS from a landlord to their current tenants (tenants with an active
 * lease), personalised with placeholders. One SMS per phone number.
 */
class SmsBroadcasts
{
    /** What the SMS provider sends at most (see SmsService). */
    public const MAX_LENGTH = 320;

    /** placeholder => what it becomes */
    public const PLACEHOLDERS = [
        '{first_name}' => 'Tenant’s first name',
        '{name}' => 'Tenant’s full name',
        '{unit}' => 'Their unit',
        '{estate}' => 'Their estate',
        '{balance}' => 'What they owe, e.g. UGX 250,000',
        '{landlord}' => 'Your company name',
    ];

    public function __construct(protected AccountBalances $balances)
    {
    }

    /**
     * Who would receive it: one row per phone number.
     *
     * @param  int[]  $estateIds  empty = every estate
     * @return array{recipients: Collection<int, array>, without_phone: int}
     */
    public function recipients(array $estateIds = [], bool $owingOnly = false): array
    {
        $leases = Lease::with(['tenant', 'unit.estate'])
            ->where('status', 'active')
            ->when($estateIds, fn ($q) => $q->whereHas('unit', fn ($u) => $u->whereIn('estate_id', $estateIds)))
            ->get()
            ->filter(fn (Lease $l) => $l->tenant)
            ->groupBy('tenant_id');

        $balances = $this->balances->forTenants($leases->keys()->all());

        $withoutPhone = 0;
        $recipients = collect();
        foreach ($leases as $tenantId => $tenantLeases) {
            $tenant = $tenantLeases->first()->tenant;
            $owed = max(0, ($balances[$tenantId]['owed'] ?? 0) - ($balances[$tenantId]['credit'] ?? 0));

            if ($owingOnly && $owed <= 0) {
                continue;
            }

            $phone = Msisdn::normalize((string) $tenant->primary_phone) ?? Msisdn::normalize((string) $tenant->secondary_phone);
            if (!$phone) {
                $withoutPhone++;
                continue;
            }
            if ($recipients->has($phone)) {
                continue; // one SMS per number (e.g. a family sharing a phone)
            }

            $units = $tenantLeases->map(fn (Lease $l) => $l->unit)->filter();
            $recipients->put($phone, [
                'tenant_id' => (int) $tenantId,
                'phone' => $phone,
                'name' => trim((string) $tenant->name),
                'first_name' => trim(strtok(trim((string) $tenant->name), ' ') ?: (string) $tenant->name),
                'unit' => $units->pluck('name')->filter()->unique()->implode(', '),
                'estate' => $units->map(fn ($u) => $u->estate?->name)->filter()->unique()->implode(', '),
                'estate_ids' => $units->pluck('estate_id')->filter()->unique()->values()->all(),
                'balance' => $owed,
            ]);
        }

        return ['recipients' => $recipients->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values(), 'without_phone' => $withoutPhone];
    }

    /** The message for one recipient, cut to what the provider sends. */
    public function render(string $template, array $recipient, string $landlordName): string
    {
        $text = strtr($template, [
            '{first_name}' => $recipient['first_name'],
            '{name}' => $recipient['name'],
            '{unit}' => $recipient['unit'],
            '{estate}' => $recipient['estate'],
            '{balance}' => 'UGX '.number_format((float) $recipient['balance']),
            '{landlord}' => $landlordName,
        ]);

        return mb_substr(trim(preg_replace('/[ \t]+/', ' ', $text)), 0, self::MAX_LENGTH);
    }

    /** SMS credits the whole send would use. */
    public function credits(string $template, Collection $recipients, string $landlordName): int
    {
        return $recipients->sum(fn (array $r) => SmsSegments::count($this->render($template, $r, $landlordName)));
    }

    public static function landlordName(Landlord $landlord): string
    {
        return (string) $landlord->name;
    }
}
