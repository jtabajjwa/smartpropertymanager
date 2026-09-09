<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Invoice;
use App\Models\Lease;

$invoices = Invoice::whereNotNull('lease_id')->whereNull('invoiceable_type')->get();
$count = 0;

foreach ($invoices as $invoice) {
    $invoice->invoiceable_type = Lease::class;
    $invoice->invoiceable_id = $invoice->lease_id;
    // Use saveQuietly to prevent any auditing or side-effect events from firing unnecessarily
    $invoice->saveQuietly();
    $count++;
}

echo "Successfully fixed $count invoices.\n";
$remaining = Invoice::whereNotNull('lease_id')->whereNull('invoiceable_type')->count();
echo "Remaining null invoiceable_type count: $remaining\n";
