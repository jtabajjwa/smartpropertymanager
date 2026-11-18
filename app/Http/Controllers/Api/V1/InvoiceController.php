<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateInvoice;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Models\Invoice;
use Illuminate\Http\Request;

/** Invoices: list with filters, one invoice with what has been paid against it, and raising one. */
class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['status' => ['nullable', 'in:unpaid,partial,paid,overdue,cancelled,open'], 'lease_id' => ['nullable', 'integer'], 'tenant_id' => ['nullable', 'integer']]);
        $search = trim((string) $request->input('search'));

        $invoices = Invoice::with(['allocations', 'invoiceable', 'lease.tenant', 'lease.unit.estate'])
            ->when($request->input('status'), fn ($q, $s) => match ($s) {
                'open' => $q->whereIn('status', ['unpaid', 'partial']),
                'overdue' => $q->whereIn('status', ['unpaid', 'partial'])->where('due_date', '<', today()),
                default => $q->where('status', $s),
            })
            ->when($request->input('lease_id'), fn ($q, $id) => $q->where('lease_id', $id))
            ->when($request->input('tenant_id'), fn ($q, $id) => $q->whereHas('lease', fn ($l) => $l->where('tenant_id', $id)))
            ->when($search, fn ($q) => $q->where(fn ($q) => $q->where('number', 'like', "%{$search}%")->orWhere('title', 'like', "%{$search}%")
                ->orWhereHas('lease.tenant', fn ($t) => $t->where('name', 'like', "%{$search}%"))))
            ->latest('invoice_date')->latest('id')->paginate(30);

        return response()->json($invoices->through(fn (Invoice $i) => self::summary($i)));
    }

    public function show(Invoice $invoice)
    {
        $invoice->load(['allocations.payment', 'invoiceable', 'lease.tenant', 'lease.unit.estate']);

        return response()->json(self::summary($invoice) + [
            'notes' => $invoice->notes,
            'payments' => $invoice->paymentTrail(),
            'lease' => $invoice->lease ? LeaseResource::make($invoice->lease) : null,
        ]);
    }

    public function store(StoreInvoiceRequest $request, CreateInvoice $create)
    {
        $invoice = $create($request->validated());

        return response()->json(self::summary($invoice->fresh(['allocations', 'invoiceable', 'lease.tenant', 'lease.unit.estate'])), 201);
    }

    public static function summary(Invoice $i): array
    {
        $paid = (float) ($i->relationLoaded('allocations') ? $i->allocations->sum('amount') : $i->allocations()->sum('amount'));

        return [
            'id' => $i->id,
            'number' => $i->number,
            'type' => $i->type,
            'title' => $i->title,
            'payer' => $i->display_payer_name,
            'unit' => $i->relationLoaded('lease') && $i->lease?->relationLoaded('unit') ? trim(($i->lease->unit?->estate?->name ? $i->lease->unit->estate->name.' · ' : '').$i->lease->unit?->name) : null,
            'lease_id' => $i->lease_id,
            'invoice_date' => $i->invoice_date?->toDateString(),
            'due_date' => $i->due_date?->toDateString(),
            'amount' => (float) $i->amount,
            'paid' => round($paid, 2),
            'balance' => round((float) $i->amount - $paid, 2),
            'status' => $i->status,
            'overdue' => $i->isOverdue(),
        ];
    }
}
