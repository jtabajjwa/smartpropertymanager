<?php

namespace App\Support;

use App\Models\User;

/**
 * Guided tours of the landlord portal: a dimmed page with one element at a
 * time highlighted and a speech bubble explaining it (resources/js/tour.js,
 * <x-tour id="..." /> on the page). Elements are found by data-tour="..."
 * attributes, so layout changes don't break a tour.
 *
 * A tour is offered once, with a "quick tour?" prompt. Finishing, skipping
 * or declining it is remembered with the user's dismissed tips (tour-{id});
 * "Turn off tours" stops every prompt. "Take a tour" in the user menu
 * replays one at any time.
 */
class Tours
{
    /** Stored like a dismissed tip: no tour prompts at all. */
    public const OFF = 'tours-off';

    public static function key(string $id): string
    {
        return 'tour-'.$id;
    }

    /** Should the prompt for this tour appear? Only for landlord users (not staff in staff mode). */
    public static function shouldOffer(string $id, mixed $user): bool
    {
        return $user instanceof User
            && !$user->hasDismissedTip(self::OFF)
            && !$user->hasDismissedTip(self::key($id));
    }

    /**
     * @return array{title: string, intro: string, steps: list<array{element: string, title: string, text: string, side?: string, sidebar?: bool}>}|null
     */
    public static function get(string $id, ?User $user = null): ?array
    {
        $admin = (bool) $user?->isLandlordAdmin();

        return match ($id) {
            'dashboard' => [
                'title' => 'Welcome to Primora',
                'intro' => 'Want a quick 1-minute tour of where everything is?',
                'steps' => [
                    [
                        'element' => 'company', 'sidebar' => true, 'side' => 'right',
                        'title' => 'Your company',
                        'text' => 'The business you’re working in. Its name and logo appear on your invoices, receipts and the tenant portal.'
                            .($admin ? ' Change them under Company Profile.' : ''),
                    ],
                    [
                        'element' => 'nav-property', 'sidebar' => true, 'side' => 'right',
                        'title' => 'Property: estates and units',
                        'text' => 'Start here. Add each estate (a building or compound), then its units. Every unit gets a unit ID that tenants can pay with.',
                    ],
                    [
                        'element' => 'nav-tenancy', 'sidebar' => true, 'side' => 'right',
                        'title' => 'Tenancy: tenants and leases',
                        'text' => 'Add your tenants, then give each one a lease on a unit. The lease sets the rent, and Primora raises the rent invoices from it.',
                    ],
                    [
                        'element' => 'nav-finance', 'sidebar' => true, 'side' => 'right',
                        'title' => 'Finance: what’s owed and paid',
                        'text' => 'Invoices, payments and security deposits. Payments made through Primora are recorded for you; record cash and bank payments yourself.',
                    ],
                    [
                        'element' => 'nav-operations', 'sidebar' => true, 'side' => 'right',
                        'title' => 'Operations',
                        'text' => 'Track expenses, vendors and job cards, and answer the issues your tenants report.',
                    ],
                    [
                        'element' => 'getting-started', 'side' => 'bottom',
                        'title' => 'Your setup checklist',
                        'text' => 'The steps to get going. Each one ticks itself off as you do it, so you always know what’s next.',
                    ],
                    [
                        'element' => 'stats', 'side' => 'bottom',
                        'title' => 'Your business at a glance',
                        'text' => 'Occupancy, vacant units, active leases, net income and overdue rent, kept up to date as you work.',
                    ],
                    [
                        'element' => 'income-chart', 'side' => 'top',
                        'title' => 'Income vs expenses',
                        'text' => 'The last six months side by side, so you can see how the business is trending.',
                    ],
                    [
                        'element' => 'user-menu', 'sidebar' => true, 'side' => 'right',
                        'title' => 'Your account',
                        'text' => 'Change your password or sign out here. You can also take this tour again, or bring back the tips.',
                    ],
                ],
            ],
            'leases' => self::listPage('Leases', 'lease', [
                'add' => 'Choose the tenant and the unit, then the rent, the security deposit and the upfront period. Saving it raises the first rent invoice.',
                'actions' => 'View or edit a lease, or generate its agreement to print and sign.',
            ]),
            'invoices' => self::listPage('Invoices', 'invoice', [
                'add' => 'Rent invoices raise themselves from leases. Use this for anything else, such as a charge to a casual customer.',
                'actions' => 'View an invoice or download its PDF, email it to the tenant, write it off or cancel it.',
            ]),
            'payments' => self::listPage('Payments', 'payment', [
                'add' => 'Record a payment made in cash, by bank or another way outside Primora. Payments through Primora are recorded for you.',
                'actions' => 'View a payment, see or change which invoices it pays (Allocations), or cancel it.',
            ]),
            default => null,
        };
    }

    /** The same four stops on each list page: Add, filters, a row's actions, Export. */
    protected static function listPage(string $title, string $noun, array $text): array
    {
        return [
            'title' => $title,
            'intro' => 'New here? Take a 30-second tour of this page.',
            'steps' => [
                ['element' => 'add', 'side' => 'bottom', 'title' => 'Add a '.$noun, 'text' => $text['add']],
                ['element' => 'filters', 'side' => 'bottom', 'title' => 'Find what you need', 'text' => 'Search and filter the list. The filters also apply to Export.'],
                ['element' => 'row-actions', 'side' => 'left', 'title' => 'Actions on each '.$noun, 'text' => $text['actions']],
                ['element' => 'export', 'side' => 'bottom', 'title' => 'Export', 'text' => 'Download the list, as filtered, as a spreadsheet (CSV) that opens in Excel.'],
            ],
        ];
    }
}
