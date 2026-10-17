<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToLandlord;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeaseTemplate extends Model
{
    use HasFactory, Auditable, BelongsToLandlord;

    protected $fillable = [
        'name',
        'content',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public const TOKENS = [
        'tenant_name' => "Tenant's full name",
        'tenant_email' => "Tenant's email address",
        'tenant_phone' => "Tenant's phone number",
        'landlord_name' => 'Landlord / company name',
        'estate_name' => 'Estate name',
        'unit_name' => 'Unit name',
        'lease_number' => 'Lease number',
        'start_date' => 'Lease start date',
        'end_date' => 'Lease end date (or "Ongoing")',
        'rent_amount' => 'Rent amount (formatted, with UGX)',
        'billing_cycle' => 'Billing cycle (Monthly, Quarterly, Annual)',
        'security_deposit' => 'Security deposit (formatted, with UGX)',
        'today' => "Today's date",
    ];

    /**
     * Built here, not in the Blade view — Blade's compiler scans the raw
     * template file text for "{{ ... }}" and doesn't know the difference
     * between an actual echo tag and a literal "{{"/"}}" sitting inside a
     * PHP string, so it will "compile" one either way. Keeping the braces
     * out of any .blade.php file avoids that entirely.
     */
    public static function wrappedToken(string $token): string
    {
        return '{{' . $token . '}}';
    }

    public function renderFor(Lease $lease): string
    {
        $tenant = $lease->tenant;
        $unit = $lease->unit;
        $estate = $unit?->estate;

        $replacements = [
            '{{tenant_name}}' => $tenant?->name ?? '—',
            '{{tenant_email}}' => $tenant?->email ?? '—',
            '{{tenant_phone}}' => $tenant?->primary_phone ?? '—',
            '{{landlord_name}}' => $lease->landlord?->name ?? '—',
            '{{estate_name}}' => $estate?->name ?? '—',
            '{{unit_name}}' => $unit?->name ?? '—',
            '{{lease_number}}' => $lease->number ?? '—',
            '{{start_date}}' => $lease->start_date?->format('d M Y') ?? '—',
            '{{end_date}}' => $lease->end_date?->format('d M Y') ?? 'Ongoing',
            '{{rent_amount}}' => 'UGX ' . number_format((float) $lease->rent_amount, 2),
            '{{billing_cycle}}' => ucfirst($lease->billing_cycle ?? ''),
            '{{security_deposit}}' => 'UGX ' . number_format((float) $lease->security_deposit, 2),
            '{{today}}' => now()->format('d M Y'),
        ];

        return strtr($this->content, $replacements);
    }
}
