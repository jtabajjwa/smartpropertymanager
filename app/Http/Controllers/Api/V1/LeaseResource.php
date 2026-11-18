<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Lease;

/** A lease as the app shows it. */
final class LeaseResource
{
    public static function make(Lease $l): array
    {
        return [
            'id' => $l->id,
            'number' => $l->number,
            'status' => $l->status,
            'tenant_id' => $l->tenant_id,
            'tenant' => $l->relationLoaded('tenant') ? $l->tenant?->name : null,
            'unit_id' => $l->unit_id,
            'unit' => $l->relationLoaded('unit') ? trim(($l->unit?->estate?->name ? $l->unit->estate->name.' · ' : '').$l->unit?->name) : null,
            'start_date' => $l->start_date?->toDateString(),
            'end_date' => $l->end_date?->toDateString(),
            'rent_amount' => (float) $l->rent_amount,
            'billing_cycle' => $l->billing_cycle,
            'security_deposit' => (float) $l->security_deposit,
            'required_upfront_periods' => (int) $l->required_upfront_periods,
            'next_invoice_date' => $l->next_invoice_date?->toDateString(),
        ];
    }
}
