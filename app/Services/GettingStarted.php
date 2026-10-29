<?php

namespace App\Services;

use App\Models\Estate;
use App\Models\Landlord;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;

/**
 * The dashboard's "Getting started" checklist for a new landlord. Each step
 * ticks itself off from what's already recorded, so it never needs to be
 * marked by hand and stays right if records are added some other way.
 */
class GettingStarted
{
    /** The key a user's dismissal of the whole guide is stored under (see User::dismissTip). */
    public const KEY = 'getting-started';

    /**
     * The steps for the current landlord, in order.
     *
     * @return list<array{key: string, title: string, text: string, action: string, url: string, done: bool}>
     */
    public function steps(User $user): array
    {
        $landlord = Landlord::find(CurrentLandlord::id());
        $steps = [];

        // Company details are admin-only (Company Profile).
        if ($user->isLandlordAdmin()) {
            $steps[] = [
                'key' => 'company',
                'title' => 'Add your logo and address',
                'text' => 'They appear on your invoices, receipts and lease agreements, and in the tenant portal.',
                'action' => 'Company profile',
                'url' => route('company-profile.edit'),
                'done' => filled($landlord?->logo) || filled($landlord?->address),
            ];
        }

        return [
            ...$steps,
            [
                'key' => 'estate',
                'title' => 'Add an estate',
                'text' => 'An estate is a property or building: a block of flats, a row of shops, or a single house.',
                'action' => 'Add estate',
                'url' => route('estates.index', ['new' => 1]),
                'done' => Estate::exists(),
            ],
            [
                'key' => 'unit',
                'title' => 'Add its units',
                'text' => 'The rooms, flats or shops you rent out. Each one gets a unit ID that tenants use to pay and sign in.',
                'action' => 'Add unit',
                'url' => route('units.index', ['new' => 1]),
                'done' => Unit::exists(),
            ],
            [
                'key' => 'tenant',
                'title' => 'Add a tenant',
                'text' => 'Their phone number is how they get SMS reminders and receipts, and how they sign in to the tenant portal.',
                'action' => 'Add tenant',
                'url' => route('tenants.index', ['new' => 1]),
                'done' => Tenant::exists(),
            ],
            [
                'key' => 'lease',
                'title' => 'Create a lease',
                'text' => 'Link the tenant to a unit with the rent and how often it’s due. Primora then raises every rent invoice for you.',
                'action' => 'Add lease',
                'url' => route('leases.index', ['new' => 1]),
                'done' => Lease::exists(),
            ],
            [
                'key' => 'payment',
                'title' => 'Record a payment',
                'text' => 'When a tenant pays you, record it and allocate it to the invoices it covers. Their balance updates, and you can send them an SMS receipt.',
                'action' => 'Add payment',
                'url' => route('payments.index', ['new' => 1]),
                'done' => Payment::where('status', '!=', 'cancelled')->exists(),
            ],
        ];
    }

    /** The guide to show this user, or null once they've closed it (or for staff). */
    public function forDashboard(mixed $user): ?array
    {
        if (!$user instanceof User || $user->hasDismissedTip(self::KEY)) {
            return null;
        }

        $steps = $this->steps($user);
        $done = count(array_filter($steps, fn ($s) => $s['done']));

        return [
            'steps' => $steps,
            'done' => $done,
            'total' => count($steps),
            'complete' => $done === count($steps),
            'next' => collect($steps)->firstWhere('done', false)['key'] ?? null,
        ];
    }
}
