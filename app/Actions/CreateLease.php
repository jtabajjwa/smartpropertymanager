<?php

namespace App\Actions;

use App\Models\Invoice;
use App\Models\Lease;
use App\Services\CurrentLandlord;
use App\Services\InvoicingService;
use App\Services\NumberGenerator;
use App\Services\UnitStatusService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * A new lease: numbered, the unit marked occupied, and its first invoices
 * (deposit, and rent for the upfront periods). Shared by the web portal and
 * the mobile app's API. $data is StoreLeaseRequest's validated data.
 */
class CreateLease
{
    /** @return array{0: Lease, 1: list<Invoice>} */
    public function __invoke(array $data, ?UploadedFile $agreement = null): array
    {
        $data['status'] = $data['status'] ?? 'active';
        $data['security_deposit'] = $data['security_deposit'] ?? 0;
        unset($data['agreement_file']);
        if ($agreement) {
            $data['agreement_file'] = $agreement->store('leases/agreements', 'public');
        }

        $invoicing = app(InvoicingService::class);
        $data['next_invoice_date'] = $invoicing->calculateNextInvoiceDate($data['start_date'], $data['billing_cycle'], (int) $data['required_upfront_periods']);

        return DB::transaction(function () use ($data, $invoicing) {
            $lease = Lease::create($data);
            $lease->update(['number' => app(NumberGenerator::class)->next(CurrentLandlord::id(), 'lease', 'LSE')]);
            app(UnitStatusService::class)->onLeaseCreated($lease);

            return [$lease, $invoicing->createLeaseInvoices($lease)];
        });
    }
}
