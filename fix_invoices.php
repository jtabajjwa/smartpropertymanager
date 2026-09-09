<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Invoice;
use App\Models\Lease;
use Illuminate\Support\Facades\DB;

$count = Invoice::whereNotNull('lease_id')->whereNull('invoiceable_type')->update([
    'invoiceable_type' => Lease::class,
    'invoiceable_id' => DB::raw('lease_id')
]);

echo "Fixed $count invoices.\n";
