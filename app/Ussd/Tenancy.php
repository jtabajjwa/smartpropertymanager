<?php

namespace App\Ussd;

use App\Models\Estate;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use Illuminate\Support\Str;

/** One active lease as the USSD menu shows it. */
final class Tenancy
{
    public function __construct(
        public readonly Lease $lease,
        public readonly Tenant $tenant,
        public readonly ?Unit $unit,
        public readonly ?Estate $estate,
        /** Still owed on this lease's invoices. */
        public readonly float $balance,
        /** Whether the landlord takes mobile money payments through Primora. */
        public readonly bool $acceptsPayments,
    ) {
    }

    public function estateName(): string
    {
        return self::plain($this->estate?->name ?? 'Estate');
    }

    public function tenantName(): string
    {
        return self::plain($this->tenant->name);
    }

    /** The unit's name as the landlord calls it, e.g. "A1". */
    public function unitName(): string
    {
        return self::plain($this->unit?->name ?? '');
    }

    /** The unit ID tenants pay with, e.g. "1000124". */
    public function unitCode(): string
    {
        return (string) ($this->unit?->code ?? '');
    }

    /** Plain ASCII, so it shows correctly on any phone. */
    public static function plain(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::ascii($text)));
    }
}
