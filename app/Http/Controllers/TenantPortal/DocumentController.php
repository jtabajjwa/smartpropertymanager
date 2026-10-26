<?php

namespace App\Http\Controllers\TenantPortal;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\LeaseTemplate;
use App\Services\TenantPortal\TenantAccount;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Invoices and tenancy agreements as PDFs, for the signed-in tenant only. */
class DocumentController extends Controller
{
    // Ids, not route-model binding: binding runs before the tenant's landlord scope is set.
    public function invoice(Request $request, int $invoice)
    {
        $invoice = Invoice::findOrFail($invoice);
        abort_unless((new TenantAccount($request->attributes->get('tenant')))->ownsInvoice($invoice), 404);

        $invoice->load(['landlord', 'lease.unit.estate']);

        return Pdf::loadView('pdfs.invoice', ['invoice' => $invoice])->stream("invoice-{$invoice->number}.pdf");
    }

    /**
     * The signed agreement the landlord uploaded, if there is one; otherwise
     * the agreement generated from the landlord's default template.
     */
    public function agreement(Request $request, int $lease)
    {
        $lease = Lease::findOrFail($lease);
        abort_unless($lease->tenant_id === $request->attributes->get('tenant')->id, 404);

        if ($lease->agreement_file && Storage::disk('public')->exists($lease->agreement_file)) {
            return Storage::disk('public')->response($lease->agreement_file, "agreement-{$lease->number}.".pathinfo($lease->agreement_file, PATHINFO_EXTENSION));
        }

        $template = LeaseTemplate::where('is_default', true)->first() ?? LeaseTemplate::oldest('id')->first();
        abort_unless($template, 404, 'Your landlord has not added a tenancy agreement yet.');

        return Pdf::loadView('pdfs.lease-agreement', [
            'lease' => $lease->load(['tenant', 'unit.estate.location', 'landlord']),
            'content' => $template->renderFor($lease),
        ])->stream("agreement-{$lease->number}.pdf");
    }
}
