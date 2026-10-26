<?php

namespace App\Services;

use App\Models\LeaseTemplate;

/**
 * The ready-made tenancy agreement every landlord starts with. The wording
 * lives in resources/agreements/standard-tenancy-agreement.txt; clauses the
 * landlord is expected to adjust are marked in [square brackets].
 */
class StandardLeaseTemplate
{
    public const NAME = 'Standard Tenancy Agreement (Uganda)';

    public static function content(): string
    {
        return file_get_contents(resource_path('agreements/standard-tenancy-agreement.txt'));
    }

    /**
     * Give a landlord the standard agreement. It becomes their default only if
     * they have no default yet. Skips landlords who already have it, unless
     * $freshCopy is set (the "Add standard agreement" button), in which case
     * a dated copy is added alongside any edited version.
     */
    public function installFor(int $landlordId, bool $freshCopy = false): ?LeaseTemplate
    {
        $templates = LeaseTemplate::withoutGlobalScopes()->where('landlord_id', $landlordId);

        $name = self::NAME;
        if ((clone $templates)->where('name', $name)->exists()) {
            if (!$freshCopy) {
                return null;
            }
            $name .= ' (fresh copy '.now()->format('d M Y H:i').')';
        }

        $template = new LeaseTemplate([
            'name' => $name,
            'content' => self::content(),
            'is_default' => !(clone $templates)->where('is_default', true)->exists(),
        ]);
        $template->landlord_id = $landlordId;
        $template->save();

        return $template;
    }
}
