<?php

namespace App\Http\Controllers;

use App\Exports\InvoicesExport;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Models\Invoice;
use App\Services\InvoiceEmailService;
use App\Services\SmsNotificationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    public function index()
    {
        $query = Invoice::with(['invoiceable', 'lease.tenant', 'allocations']);

        if (request('status')) {
            if (request('status') === 'unpaid') {
                $query->whereIn('status', ['unpaid', 'partial']);
            } else {
                $query->where('status', request('status'));
            }
        }

        if (request('tenant_id')) {
            $query->whereHas('lease', fn ($q) => $q->where('tenant_id', request('tenant_id')));
        }

        if (request('customer_id')) {
            $query->where('invoiceable_type', \App\Models\Customer::class)
                  ->where('invoiceable_id', request('customer_id'));
        }

        if (request('estate_id')) {
            $query->whereHas('lease.unit', fn ($q) => $q->where('estate_id', request('estate_id')));
        }

        if (request('lease_id')) {
            $query->where('lease_id', request('lease_id'));
        }

        if (request('type')) {
            $query->where('type', request('type'));
        }

        if (request('due_from')) {
            $query->whereDate('due_date', '>=', request('due_from'));
        }

        if (request('due_to')) {
            $query->whereDate('due_date', '<=', request('due_to'));
        }

        if (request('search')) {
            $search = request('search');
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhereHas('lease.tenant', fn ($t) => $t->where('name', 'like', "%{$search}%"));
            });
        }

        $invoices = $query->latest()->paginate(15)->withQueryString();

        return view('invoices.index', compact('invoices'));
    }

    public function store(StoreInvoiceRequest $request)
    {
        $data = $request->validated();

        $invoice = DB::transaction(function () use ($data) {
            // Determine the polymorphic owner
            if (isset($data['customer_id'])) {
                $data['invoiceable_type'] = \App\Models\Customer::class;
                $data['invoiceable_id'] = $data['customer_id'];
                unset($data['customer_id']);
            } elseif (isset($data['lease_id'])) {
                $data['invoiceable_type'] = \App\Models\Lease::class;
                $data['invoiceable_id'] = $data['lease_id'];
            }

            unset($data['send_sms'], $data['send_email']); // Don't save these to the DB

            $invoice = Invoice::create($data);
            $invoice->update(['number' => 'INV-'.str_pad($invoice->id, 5, '0', STR_PAD_LEFT)]);

            return $invoice;
        });

        if ($request->boolean('send_sms')) {
            app(SmsNotificationService::class)->invoiceCreated($invoice->load('lease.tenant'));
        }

        if ($request->boolean('send_email')) {
            app(InvoiceEmailService::class)->invoiceCreated($invoice->load('lease.tenant'));
        }

        return redirect()->back()->with('success', 'Invoice created successfully.');
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice)
    {
        if ($invoice->status === 'cancelled') {
            return redirect()->back()->with('error', 'Cancelled invoices cannot be edited.');
        }

        if ($invoice->allocations()->exists()) {
            return redirect()->back()->with('error', 'Cannot edit an invoice with existing payment allocations.');
        }

        $invoice->update($request->validated());

        return redirect()->back()->with('success', 'Invoice updated successfully.');
    }

    public function export(Request $request)
    {
        return (new InvoicesExport($request))->download('invoices.csv');
    }

    public function cancel(Invoice $invoice)
    {
        if ($invoice->allocations()->exists()) {
            return redirect()->back()->with('error', 'Cannot cancel an invoice with existing allocations.');
        }

        $invoice->update(['status' => 'cancelled']);

        return redirect()->back()->with('success', 'Invoice cancelled successfully.');
    }

    public function writeOff(Invoice $invoice)
    {
        if ($invoice->invoiceable_type !== \App\Models\Lease::class || ! in_array($invoice->invoiceable?->status, ['terminated', 'evicted'])) {
            return redirect()->back()->with('error', 'Invoices can only be written off if the lease is terminated or evicted.');
        }

        if ($invoice->status === 'paid') {
            return redirect()->back()->with('error', 'Cannot write off a fully paid invoice.');
        }

        $invoice->update([
            'status' => 'written_off',
            'notes' => trim($invoice->notes."\nWritten off due to ".$invoice->invoiceable->status.' lease'),
        ]);

        return redirect()->back()->with('success', 'Invoice written off successfully.');
    }

    public function pdf(Invoice $invoice)
    {
        $invoice->load(['landlord', 'lease.unit.estate']);

        $pdf = Pdf::loadView('pdfs.invoice', ['invoice' => $invoice]);

        return $pdf->stream("invoice-{$invoice->number}.pdf");
    }

    public function sendEmail(Invoice $invoice, InvoiceEmailService $emails)
    {
        $result = $emails->send($invoice->load('lease.tenant'));

        if (!$result['sent']) {
            return redirect()->back()->with('error', $result['reason']);
        }

        return redirect()->back()->with('success', 'Invoice emailed successfully.');
    }
}
