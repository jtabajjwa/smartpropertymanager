{{-- The payments put against the invoice in `viewing` (Invoice::paymentTrail()), with totals. --}}
<template x-if="!viewing.payments || !viewing.payments.length">
    <p class="text-sm text-gray-500">No payments have been put against this invoice yet.</p>
</template>
<template x-if="viewing.payments && viewing.payments.length">
    <div>
        <ul class="divide-y divide-gray-100">
            <template x-for="p in viewing.payments" :key="p.number + p.applied_at">
                <li class="py-2 flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <a :href="'{{ route('payments.index') }}?search=' + encodeURIComponent(p.number)" class="text-sm font-medium text-blue-600 hover:underline" x-text="p.number"></a>
                        <p class="text-xs text-gray-500">
                            <span x-text="[p.date, p.method].filter(Boolean).join(' · ')"></span>
                            <template x-if="p.reference"><span> · Ref <span class="break-all" x-text="p.reference"></span></span></template>
                        </p>
                        <p class="text-xs text-gray-500" x-show="p.payment_amount > p.applied" x-text="'Part of a UGX ' + Number(p.payment_amount).toLocaleString() + ' payment'"></p>
                    </div>
                    <p class="text-sm font-medium text-gray-900 tabular-nums whitespace-nowrap" x-text="'UGX ' + Number(p.applied).toLocaleString()"></p>
                </li>
            </template>
        </ul>
        <div class="space-y-1 border-t border-gray-200 pt-2 text-sm tabular-nums">
            <div class="flex justify-between">
                <span class="text-gray-500">Invoice amount</span>
                <span class="text-gray-900" x-text="'UGX ' + Number(viewing.amount).toLocaleString()"></span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-500">Total paid</span>
                <span class="font-medium text-gray-900" x-text="'UGX ' + viewing.payments.reduce((s, p) => s + Number(p.applied), 0).toLocaleString()"></span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-500">Balance</span>
                <span class="font-medium" :class="viewing.balance > 0 ? 'text-orange-600' : 'text-green-600'" x-text="'UGX ' + Number(viewing.balance).toLocaleString()"></span>
            </div>
        </div>
    </div>
</template>
