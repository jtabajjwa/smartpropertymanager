<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTenantRequest;
use App\Http\Requests\UpdateTenantRequest;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Tenants: list and search, one tenant with their leases, balance, invoices and payments; add and edit. */
class TenantController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->input('search'));

        $tenants = Tenant::with(['leases' => fn ($q) => $q->where('status', 'active')->with('unit.estate')])
            ->when($search, fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('number', 'like', "%{$search}%")
                ->orWhere('primary_phone', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')->paginate(30);

        return response()->json($tenants->through(fn (Tenant $t) => self::summary($t)));
    }

    public function show(Tenant $tenant)
    {
        $tenant->load(['leases.unit.estate']);
        $leaseIds = $tenant->leases->pluck('id');

        return response()->json(self::summary($tenant) + [
            'secondary_phone' => $tenant->secondary_phone,
            'id_type' => $tenant->id_type, 'id_number' => $tenant->id_number,
            'emergency_contact' => ['name' => $tenant->emergency_contact_name, 'relationship' => $tenant->emergency_contact_relationship,
                'phone' => $tenant->emergency_contact_phone, 'email' => $tenant->emergency_contact_email, 'address' => $tenant->emergency_contact_address],
            'notes' => $tenant->notes,
            'photo_url' => $tenant->photo ? Storage::disk('public')->url($tenant->photo) : null,
            'balance' => round($tenant->pendingInvoiceBalance(), 2),
            'deposit_balance' => round($tenant->securityDepositBalance(), 2),
            'leases' => $tenant->leases->sortByDesc('start_date')->values()->map(fn (Lease $l) => LeaseResource::make($l)),
            'invoices' => Invoice::with('allocations')->whereIn('lease_id', $leaseIds)->latest('invoice_date')->latest('id')->limit(20)->get()
                ->map(fn (Invoice $i) => InvoiceController::summary($i)),
            'payments' => Payment::where('payable_type', Tenant::class)->where('payable_id', $tenant->id)->latest('payment_date')->latest('id')->limit(20)->get()
                ->map(fn (Payment $p) => PaymentController::summary($p->setRelation('payable', $tenant))),
        ]);
    }

    public function store(StoreTenantRequest $request, CreateTenant $create)
    {
        $tenant = $create($request->validated(), [
            'photo' => $request->file('photo'), 'id_scan' => $request->file('id_scan'), 'lc_letter' => $request->file('lc_letter'),
        ]);

        return response()->json(self::summary($tenant->fresh()), 201);
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant)
    {
        $data = collect($request->validated())->except(array_keys(CreateTenant::FILE_FOLDERS))->all();
        foreach (CreateTenant::FILE_FOLDERS as $field => $folder) {
            if ($request->hasFile($field)) {
                $data[$field] = $request->file($field)->store($folder, 'public');
            }
        }
        $tenant->update($data);

        return response()->json(self::summary($tenant->fresh()));
    }

    public static function summary(Tenant $t): array
    {
        $active = $t->relationLoaded('leases') ? $t->leases->firstWhere('status', 'active') : null;

        return [
            'id' => $t->id, 'number' => $t->number, 'type' => $t->type, 'name' => $t->name,
            'email' => $t->email, 'phone' => $t->primary_phone,
            'unit' => $active ? trim(($active->unit?->estate?->name ? $active->unit->estate->name.' · ' : '').$active->unit?->name) : null,
            'active_lease_id' => $active?->id,
        ];
    }
}
