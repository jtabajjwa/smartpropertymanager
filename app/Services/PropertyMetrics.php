<?php

namespace App\Services;

use App\Models\Landlord;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Property portfolio figures for the staff portal — either for one landlord
 * or across the whole platform. Uses plain query builder (not the scoped
 * Eloquent models) so it works without a landlord context.
 */
class PropertyMetrics
{
    /** Multiplier that turns one billing period's rent into a monthly figure. */
    public const MONTHLY_FACTOR = [
        'daily' => 365 / 12,
        'weekly' => 52 / 12,
        'fortnightly' => 26 / 12,
        'monthly' => 1,
        'quarterly' => 1 / 3,
        'yearly' => 1 / 12,
    ];

    /**
     * @return array{districts:int, estates:int, units:int, occupied:int, vacant:int, maintenance:int,
     *               occupancy_rate:float, active_leases:int, tenants:int, expiring_leases:int, monthly_rent_roll:float}
     */
    public function summary(?int $landlordId = null): array
    {
        $units = $this->table('units', $landlordId)
            ->selectRaw("
                COUNT(*) as total,
                COALESCE(SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END), 0) as occupied,
                COALESCE(SUM(CASE WHEN status = 'vacant' THEN 1 ELSE 0 END), 0) as vacant,
                COALESCE(SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END), 0) as maintenance
            ")
            ->first();

        $total = (int) $units->total;
        $occupied = (int) $units->occupied;

        return [
            'districts' => $this->table('estates', $landlordId)->whereNotNull('district')->distinct()->count('district'),
            'estates' => $this->table('estates', $landlordId)->count(),
            'units' => $total,
            'occupied' => $occupied,
            'vacant' => (int) $units->vacant,
            'maintenance' => (int) $units->maintenance,
            'occupancy_rate' => $total > 0 ? round($occupied / $total * 100, 1) : 0.0,
            'active_leases' => $this->table('leases', $landlordId)->where('status', 'active')->count(),
            'tenants' => $this->table('tenants', $landlordId)->count(),
            'expiring_leases' => $this->table('leases', $landlordId)
                ->where('status', 'active')
                ->whereNotNull('end_date')
                ->whereBetween('end_date', [today()->toDateString(), today()->addDays(30)->toDateString()])
                ->count(),
            'monthly_rent_roll' => $this->monthlyRentRoll($landlordId),
        ];
    }

    /**
     * Rent due per month across active leases, with every billing cycle
     * converted to a monthly equivalent (an estimate for non-monthly cycles).
     */
    public function monthlyRentRoll(?int $landlordId = null): float
    {
        return (float) $this->table('leases', $landlordId)
            ->where('status', 'active')
            ->selectRaw('billing_cycle, SUM(rent_amount) as rent')
            ->groupBy('billing_cycle')
            ->get()
            ->sum(fn ($row) => (float) $row->rent * (self::MONTHLY_FACTOR[$row->billing_cycle] ?? 1));
    }

    /**
     * Landlords with the most units, with their occupancy breakdown.
     */
    public function topLandlords(int $limit = 8): Collection
    {
        $rows = DB::table('units')
            ->select('landlord_id')
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END) as occupied,
                SUM(CASE WHEN status = 'vacant' THEN 1 ELSE 0 END) as vacant,
                SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance
            ")
            ->groupBy('landlord_id')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        $ids = $rows->pluck('landlord_id');

        $estates = DB::table('estates')
            ->whereIn('landlord_id', $ids)
            ->selectRaw('landlord_id, COUNT(*) as total')
            ->groupBy('landlord_id')
            ->pluck('total', 'landlord_id');

        $landlords = Landlord::whereIn('id', $ids)->get()->keyBy('id');

        return $rows
            ->filter(fn ($row) => $landlords->has($row->landlord_id))
            ->map(fn ($row) => (object) [
                'landlord' => $landlords[$row->landlord_id],
                'estates' => (int) ($estates[$row->landlord_id] ?? 0),
                'units' => (int) $row->total,
                'occupied' => (int) $row->occupied,
                'vacant' => (int) $row->vacant,
                'maintenance' => (int) $row->maintenance,
                'occupancy_rate' => $row->total > 0 ? round($row->occupied / $row->total * 100, 1) : 0.0,
            ])
            ->values();
    }

    protected function table(string $table, ?int $landlordId): Builder
    {
        return DB::table($table)->when($landlordId, fn (Builder $q) => $q->where('landlord_id', $landlordId));
    }
}
