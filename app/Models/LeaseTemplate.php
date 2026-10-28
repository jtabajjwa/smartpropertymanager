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
        'tenant_id_type' => "Tenant's ID type (National ID, Passport, …)",
        'tenant_id_number' => "Tenant's ID / passport number",
        'landlord_name' => 'Landlord / company name',
        'landlord_address' => "Landlord's address (from Company Profile)",
        'landlord_phone' => "Landlord's contact phone",
        'landlord_email' => "Landlord's contact email",
        'estate_name' => 'Estate name',
        'unit_name' => 'Unit name',
        'unit_id' => 'Unit ID (digits; the tenant signs in to the portal with it)',
        'property_address' => "Estate's address and district",
        'lease_number' => 'Lease number',
        'start_date' => 'Lease start date',
        'end_date' => 'Lease end date (or "Ongoing")',
        'rent_amount' => 'Rent amount (formatted, with UGX)',
        'billing_cycle' => 'Billing cycle (Monthly, Quarterly, Annual)',
        'security_deposit' => 'Security deposit (formatted, with UGX)',
        'upfront_periods' => 'Rent periods paid in advance (e.g. "3 months")',
        'today' => "Today's date",
    ];

    /** Printed in place of a missing detail so it can be written in by hand. */
    protected const BLANK = '____________________';

    /** Shown for {{tenant_id_type}}; matches the ID types on the tenant form. */
    protected const ID_TYPE_LABELS = [
        'national_id' => 'National ID',
        'passport' => 'Passport',
        'drivers_license' => "Driver's Licence",
        'business_registration' => 'Business Registration',
        'other' => 'ID',
    ];

    /** Singular/plural period names for {{upfront_periods}}. */
    protected const PERIOD_NAMES = [
        'daily' => ['day', 'days'],
        'weekly' => ['week', 'weeks'],
        'fortnightly' => ['fortnight', 'fortnights'],
        'monthly' => ['month', 'months'],
        'quarterly' => ['quarter', 'quarters'],
        'yearly' => ['year', 'years'],
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
        $landlord = $lease->landlord;

        $propertyAddress = $estate?->fullAddress() ?? '';

        $periods = (int) ($lease->required_upfront_periods ?: 1);
        [$singular, $plural] = self::PERIOD_NAMES[$lease->billing_cycle] ?? ['period', 'periods'];

        $replacements = [
            '{{tenant_name}}' => $tenant?->name ?? '—',
            '{{tenant_email}}' => $tenant?->email ?? '—',
            '{{tenant_phone}}' => $tenant?->primary_phone ?? '—',
            '{{tenant_id_type}}' => self::ID_TYPE_LABELS[$tenant?->id_type] ?? 'ID',
            // Missing details print as a blank line, so they can be filled in by hand.
            '{{tenant_id_number}}' => $tenant?->id_number ?: self::BLANK,
            '{{landlord_name}}' => $landlord?->name ?? '—',
            '{{landlord_address}}' => $landlord?->address ?: self::BLANK,
            '{{landlord_phone}}' => $landlord?->contact_phone ?: self::BLANK,
            '{{landlord_email}}' => $landlord?->contact_email ?: self::BLANK,
            '{{estate_name}}' => $estate?->name ?? '—',
            '{{unit_name}}' => $unit?->name ?? '—',
            '{{unit_id}}' => $unit?->code ?? '—',
            '{{property_address}}' => $propertyAddress ?: self::BLANK,
            '{{upfront_periods}}' => $periods.' '.($periods === 1 ? $singular : $plural),
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
