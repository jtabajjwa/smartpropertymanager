<?php

namespace App\Support;

/**
 * Everything a staff member can be allowed to do in the staff portal.
 * Assigned per staff user (staff_users.permissions). Staff admins implicitly
 * have all of them, and are the only ones who can manage staff accounts.
 */
final class StaffPermissions
{
    /** Grouped for the staff-user form: group => [permission => [label, description]]. */
    public const GROUPS = [
        'Landlords' => [
            'landlords.view' => ['View landlords', 'See the landlords list and their details.'],
            'landlords.create' => ['Add landlords', 'Create landlord accounts and their first admin user.'],
            'landlords.edit' => ['Edit landlord details', 'Change company name, contact details and address.'],
            'landlords.suspend' => ['Suspend / reactivate', 'Suspend or reactivate a landlord account.'],
        ],
        'Property workspace' => [
            'properties.view' => ['View property workspace', "See a landlord's overview, locations, estates and units."],
            'properties.manage' => ['Manage properties', 'Add, edit and delete locations, estates and units for a landlord.'],
        ],
        'Landlord portal (staff mode)' => [
            'portal.access' => ['Open landlord portal', "Work inside a landlord's portal: tenants, leases, invoices, payments, expenses…"],
            'portal.admin' => ["Act as the landlord's admin", "In the portal, also manage the landlord's users, audit log, company profile and subscription."],
        ],
        'Plans' => [
            'plans.view' => ['View plans', 'See subscription plans and prices.'],
            'plans.manage' => ['Manage plans', 'Create, edit and delete plans, and choose which appear on the website.'],
        ],
        'Subscriptions' => [
            'subscriptions.view' => ['View subscriptions', "See every landlord's subscription and its history."],
            'subscriptions.adjust' => ['Adjust subscriptions', 'Change status, paid-until date, plan, cycle and unit limit.'],
            'subscriptions.record_payment' => ['Record offline payments', 'Record cash / bank payments, which extend the subscription.'],
        ],
        'Payments' => [
            'payments.view' => ['View payments', 'See mobile money payments, their details and the gateway log.'],
            'payments.check' => ['Check payment status', 'Ask the payment gateway for the latest status of a payment.'],
        ],
        'SMS' => [
            'sms.view' => ['View SMS', 'See SMS stock, provider purchases, bundles and landlord SMS balances.'],
            'sms.purchases' => ['Record provider purchases', 'Record SMS bought from providers (stock and cost).'],
            'sms.credits' => ['Manage landlord SMS credit', "Record offline SMS sales and adjust a landlord's SMS balance."],
            'sms.manage' => ['Manage bundles & settings', 'Create and price SMS bundles; set the starter allowance and low-balance warning.'],
        ],
        'Rent collections' => [
            'collections.view' => ['View rent collections', 'See rent tenants paid by mobile money, fees, and what each landlord is owed.'],
            'collections.manage' => ['Manage rent collections', 'Switch tenant mobile money payments on/off for landlords; set collection fee bands.'],
            'collections.settle' => ['Record settlements', 'Record payouts to landlords of rent collected for them.'],
        ],
        'Payouts' => [
            'liquidations.view' => ['View payouts', 'See landlord payout requests and payout accounts.'],
            'liquidations.approve' => ['Approve payouts', 'Approve or reject payout requests, and verify landlord payout accounts.'],
            'liquidations.execute' => ['Execute payouts', 'Send approved payouts by mobile money (Yo!) or record bank EFTs. Nobody can execute a payout they approved.'],
        ],
        'Books' => [
            'books.view' => ['View books', 'Chart of accounts, ledgers, journal, and profit & loss.'],
            'books.post' => ['Record in the books', 'Record expenses, transfers and journals, reverse entries, and manage accounts.'],
            'books.tax_rates' => ['Set landlord tax rates', 'Change the default rates and bands of the taxes and levies landlords can switch on in their Books.'],
        ],
        'Revenue' => [
            'revenue.view' => ['View revenue', 'See revenue from subscriptions and SMS sales, SMS costs and margins.'],
        ],
    ];

    /** Quick-start sets for the staff-user form. */
    public const PRESETS = [
        'support' => [
            'label' => 'Support',
            'permissions' => ['landlords.view', 'landlords.edit', 'properties.view', 'properties.manage', 'portal.access', 'subscriptions.view', 'payments.view'],
        ],
        'finance' => [
            'label' => 'Finance',
            'permissions' => ['landlords.view', 'plans.view', 'subscriptions.view', 'subscriptions.adjust', 'subscriptions.record_payment', 'payments.view', 'payments.check', 'sms.view', 'sms.purchases', 'sms.credits', 'revenue.view', 'collections.view', 'collections.settle', 'liquidations.view', 'liquidations.approve', 'books.view'],
        ],
        'read_only' => [
            'label' => 'Read-only',
            'permissions' => ['landlords.view', 'properties.view', 'plans.view', 'subscriptions.view', 'payments.view', 'sms.view', 'collections.view', 'liquidations.view', 'books.view'],
        ],
    ];

    /** Doing something implies being able to see it. */
    public const IMPLIES = [
        'landlords.create' => ['landlords.view'],
        'landlords.edit' => ['landlords.view'],
        'landlords.suspend' => ['landlords.view'],
        'properties.manage' => ['properties.view', 'landlords.view'],
        'properties.view' => ['landlords.view'],
        'portal.access' => ['landlords.view'],
        'portal.admin' => ['portal.access', 'landlords.view'],
        'plans.manage' => ['plans.view'],
        'subscriptions.adjust' => ['subscriptions.view'],
        'subscriptions.record_payment' => ['subscriptions.view'],
        'payments.check' => ['payments.view'],
        'sms.purchases' => ['sms.view'],
        'sms.credits' => ['sms.view', 'landlords.view'],
        'sms.manage' => ['sms.view'],
        'collections.manage' => ['collections.view'],
        'collections.settle' => ['collections.view'],
        'liquidations.approve' => ['liquidations.view'],
        'liquidations.execute' => ['liquidations.view'],
        'books.post' => ['books.view'],
        'books.tax_rates' => ['books.view'],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::GROUPS)));
    }

    public static function label(string $permission): string
    {
        foreach (self::GROUPS as $permissions) {
            if (isset($permissions[$permission])) {
                return $permissions[$permission][0];
            }
        }

        return $permission;
    }

    /**
     * Keep only known permissions and add the ones they imply.
     *
     * @param  array<int, string>  $permissions
     * @return list<string>
     */
    public static function normalize(array $permissions): array
    {
        $known = array_values(array_intersect(array_unique($permissions), self::all()));

        foreach ($known as $permission) {
            array_push($known, ...(self::IMPLIES[$permission] ?? []));
        }

        // Stable, catalogue order.
        return array_values(array_intersect(self::all(), array_unique($known)));
    }
}
