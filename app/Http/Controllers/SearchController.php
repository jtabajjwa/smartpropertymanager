<?php

namespace App\Http\Controllers;

use App\Models\Estate;
use App\Models\ExpenseItems;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Location;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Models\Utility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function locations(Request $request): JsonResponse
    {
        return $this->search(Location::query(), 'name', $request);
    }

    public function estates(Request $request): JsonResponse
    {
        return $this->search(Estate::query(), 'name', $request);
    }

    public function units(Request $request): JsonResponse
    {
        $query = Unit::with('estate');

        if ($request->boolean('available')) {
            $query->where('status', 'vacant');
        }

        if ($q = $request->input('q')) {
            $query->where(function ($builder) use ($q) {
                $builder->where('name', 'like', "%{$q}%")
                    ->orWhereHas('estate', fn ($e) => $e->where('name', 'like', "%{$q}%"));
            });
        }

        $results = $query->limit($request->input('limit', 20))->get()
            ->map(fn ($unit) => [
                'id' => $unit->id,
                'text' => $unit->name . ($unit->estate ? " ({$unit->estate->name})" : ''),
            ]);

        return response()->json($results);
    }

    public function tenants(Request $request): JsonResponse
    {
        $query = Tenant::query();

        if ($q = $request->input('q')) {
            $query->where(function ($builder) use ($q) {
                $builder->where('name', 'like', "%{$q}%")
                    ->orWhere('number', 'like', "%{$q}%")
                    ->orWhere('primary_phone', 'like', "%{$q}%");
            });
        }

        $results = $query->limit($request->input('limit', 20))->get()
            ->map(fn ($t) => ['id' => $t->id, 'text' => "{$t->name} ({$t->number})"]);

        return response()->json($results);
    }

    public function customers(Request $request): JsonResponse
    {
        $query = \App\Models\Customer::query();

        if ($q = $request->input('q')) {
            $query->where(function ($builder) use ($q) {
                $builder->where('name', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        $results = $query->limit($request->input('limit', 20))->get()
            ->map(fn ($c) => ['id' => $c->id, 'text' => "{$c->name} ({$c->phone})"]);

        return response()->json($results);
    }

    public function leases(Request $request): JsonResponse
    {
        $query = Lease::with('tenant')
            ->whereNotIn('status', ['terminated', 'evicted']);

        if ($q = $request->input('q')) {
            $query->where(function ($builder) use ($q) {
                $builder->where('number', 'like', "%{$q}%")
                    ->orWhereHas('tenant', fn ($t) => $t->where('name', 'like', "%{$q}%"));
            });
        }

        $results = $query->limit($request->input('limit', 20))->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'text' => "{$l->number} — {$l->tenant?->name}",
            ]);

        return response()->json($results);
    }

    public function utilities(Request $request): JsonResponse
    {
        return $this->search(Utility::query(), 'name', $request);
    }

    public function expenseItems(Request $request): JsonResponse
    {
        return $this->search(ExpenseItems::query(), 'name', $request);
    }

    public function payments(Request $request): JsonResponse
    {
        $query = Payment::with('tenant');

        if ($q = $request->input('q')) {
            $query->where(function ($builder) use ($q) {
                $builder->where('number', 'like', "%{$q}%")
                    ->orWhere('reference', 'like', "%{$q}%")
                    ->orWhereHas('tenant', fn ($t) => $t->where('name', 'like', "%{$q}%"));
            });
        }

        $results = $query->limit($request->input('limit', 20))->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'text' => "{$p->number} — {$p->tenant?->name} (UGX " . number_format($p->amount) . ")",
            ]);

        return response()->json($results);
    }

    public function invoices(Request $request): JsonResponse
    {
        $query = Invoice::with(['invoiceable', 'allocations']);

        if ($tenantId = $request->input('tenant_id')) {
            $query->whereHasMorph('invoiceable', [\App\Models\Lease::class], function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId);
            });
        }

        if ($customerId = $request->input('customer_id')) {
            $query->where('invoiceable_type', \App\Models\Customer::class)
                  ->where('invoiceable_id', $customerId);
        }

        if ($request->boolean('unpaid_only')) {
            $query->whereIn('status', ['unpaid', 'partial']);
        }

        if ($q = $request->input('q')) {
            $query->where(function ($builder) use ($q) {
                $builder->where('number', 'like', "%{$q}%")
                    ->orWhere('title', 'like', "%{$q}%")
                    ->orWhereHas('lease.tenant', fn ($t) => $t->where('name', 'like', "%{$q}%"));
            });
        }

        $results = $query->limit($request->input('limit', 20))->get()
            ->map(fn ($i) => [
                'id' => $i->id,
                'text' => "{$i->number} — ({$i->title}) — UGX " . number_format($i->unpaidBalance()),
            ]);

        return response()->json($results);
    }

    public function users(Request $request): JsonResponse
    {
        return $this->search(User::query(), 'name', $request);
    }

    private function search($query, string $column, Request $request): JsonResponse
    {
        if ($q = $request->input('q')) {
            $query->where($column, 'like', "%{$q}%");
        }

        $results = $query->limit($request->input('limit', 20))->get()
            ->map(fn ($model) => ['id' => $model->id, 'text' => $model->$column]);

        return response()->json($results);
    }
}
