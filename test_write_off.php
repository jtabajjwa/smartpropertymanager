<?php

use App\Models\Tenant;
use App\Models\Unit;
use App\Models\Lease;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Support\Facades\Artisan;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tenant = Tenant::first();
$unit = Unit::first();

$lease = Lease::create([
    'number' => 'L-TEST',
    'tenant_id' => $tenant->id,
    'unit_id' => $unit->id,
    'status' => 'active',
    'start_date' => now(),
    'end_date' => now()->addYear(),
    'next_invoice_date' => now()->addMonth(),
    'rent_amount' => 1000,
    'billing_cycle' => 'monthly',
    'security_deposit' => 0,
    'required_upfront_periods' => 1
]);

$invoice = Invoice::create([
    'number' => 'INV-TEST',
    'lease_id' => $lease->id,
    'invoiceable_type' => Lease::class,
    'invoiceable_id' => $lease->id,
    'type' => 'rent',
    'title' => 'Test Invoice',
    'invoice_date' => now(),
    'due_date' => now()->addDays(5),
    'amount' => 1000,
    'status' => 'unpaid'
]);

$payment = Payment::create([
    'number' => 'PAY-TEST',
    'payable_type' => Tenant::class,
    'payable_id' => $tenant->id,
    'amount' => 400,
    'payment_date' => now(),
    'payment_method' => 'cash',
    'status' => 'active'
]);

PaymentAllocation::create([
    'payment_id' => $payment->id,
    'invoice_id' => $invoice->id,
    'amount' => 400
]);

$invoice->syncStatus();

echo "Before write off:\n";
echo "Invoice status: " . $invoice->fresh()->status . "\n";
echo "Invoice amount: " . $invoice->fresh()->amount . "\n";
echo "Payment amount: " . $payment->fresh()->amount . "\n";
echo "Payment unallocated: " . $payment->fresh()->unallocatedAmount() . "\n";
echo "Payment status: " . $payment->fresh()->status . "\n";
echo "Allocations count: " . $invoice->fresh()->allocations()->count() . "\n";

$lease->update(['status' => 'terminated']);

// Emulate write off
$invoice->update([
    'status' => 'written_off',
    'notes' => trim($invoice->notes . "\n[Written off due to " . $invoice->invoiceable->status . " lease]")
]);

echo "\nAfter write off:\n";
echo "Invoice status: " . $invoice->fresh()->status . "\n";
echo "Invoice amount: " . $invoice->fresh()->amount . "\n";
echo "Payment amount: " . $payment->fresh()->amount . "\n";
echo "Payment unallocated: " . $payment->fresh()->unallocatedAmount() . "\n";
echo "Payment status: " . $payment->fresh()->status . "\n";
echo "Allocations count: " . $invoice->fresh()->allocations()->count() . "\n";

$lease->delete();
$invoice->delete();
$payment->delete();
