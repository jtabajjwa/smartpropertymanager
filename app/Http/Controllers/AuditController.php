<?php

namespace App\Http\Controllers;

use App\Models\Audit;

class AuditController extends Controller
{
    public function index()
    {
        // Eager load polymorphic auditable relation
        $query = Audit::with(['user', 'auditable'])->latest();

        if (request('action')) {
            $query->where('action', request('action'));
        }

        if (request('user_id')) {
            $query->where('user_id', request('user_id'));
        }

        if (request('auditable_type')) {
            $query->where('auditable_type', request('auditable_type'));
        }

        if (request('search')) {
            $search = strtolower(request('search'));
            $query->where(function ($q) use ($search) {
                $q->where('auditable_type', 'like', "%{$search}%")
                  ->orWhereRaw('LOWER(old_values) LIKE ?', ["%{$search}%"])
                  ->orWhereRaw('LOWER(new_values) LIKE ?', ["%{$search}%"])
                  ->orWhereHas('user', fn ($u) => $u->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"]));
            });
        }

        $audits = $query->paginate(25)->withQueryString();

        return view('audits.index', compact('audits'));
    }
}
