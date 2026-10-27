<x-staff-layout>
    <x-slot name="header">SMS</x-slot>

    @php
        $avg = $stats['avg_cost'];
        $fmtCost = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 2), '0'), '.');
    @endphp

    <div class="py-2" x-data="{
        purchaseModal: false,
        purchase: {},
        bundleModal: false,
        bundle: {},
        newPurchase() { this.purchase = { id: null, provider: '', quantity: '', total_cost: '', paid_from: 'bank', purchased_on: '{{ today()->toDateString() }}', reference: '', notes: '' }; this.purchaseModal = true; },
        editPurchase(p) { this.purchase = { ...p }; this.purchaseModal = true; },
        newBundle() { this.bundle = { id: null, name: '', quantity: '', price: '', sort_order: 0, is_active: true }; this.bundleModal = true; },
        editBundle(b) { this.bundle = { ...b }; this.bundleModal = true; },
    }">
        <div class="max-w-7xl mx-auto space-y-6">
            @include('staff.sms._tabs')

            {{-- Stock position --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <x-stat-card label="Bought from providers" :value="number_format($stats['purchased']).' SMS'">
                    <x-slot name="sub">UGX {{ number_format($stats['cost']) }} spent · avg UGX {{ $fmtCost($avg) }} / SMS</x-slot>
                </x-stat-card>
                <x-stat-card label="Sent by landlords" :value="number_format($stats['sent']).' SMS'">
                    <x-slot name="sub">{{ number_format($stats['sent_month']) }} this month</x-slot>
                </x-stat-card>
                <x-stat-card label="Held by landlords" :value="number_format($stats['held']).' SMS'">
                    <x-slot name="sub">Prepaid / free credit not yet used</x-slot>
                </x-stat-card>
                <div class="bg-white rounded-2xl shadow-sm border {{ $stats['unallocated'] < 0 ? 'border-red-300' : 'border-gray-200' }} p-5">
                    <p class="text-xs font-medium text-gray-600 uppercase tracking-wide">Unallocated stock</p>
                    <p class="mt-2 text-2xl font-bold {{ $stats['unallocated'] < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format($stats['unallocated']) }} SMS</p>
                    <p class="mt-1 text-xs {{ $stats['unallocated'] < 0 ? 'text-red-600' : 'text-gray-500' }}">
                        @if($stats['unallocated'] < 0)
                            Landlords hold more credit than recorded stock. Buy more SMS (or record purchases).
                        @else
                            {{ number_format($stats['provider_balance']) }} left with providers, less what landlords hold
                        @endif
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <x-stat-card label="SMS sales this month" :value="'UGX '.number_format($salesMonth)" />
                <x-stat-card label="SMS sold (all time)" :value="number_format($stats['sold'])" />
                <x-stat-card label="Given free (starter)" :value="number_format($stats['given_free'])" />
                @staffcan('revenue.view')
                    <a href="{{ route('staff.revenue.index') }}" class="bg-white rounded-2xl shadow-sm border border-gray-200 p-5 hover:border-orange-300 transition">
                        <p class="text-xs font-medium text-gray-600 uppercase tracking-wide">Revenue & margins</p>
                        <p class="mt-2 text-sm font-semibold text-orange-600">Open revenue report →</p>
                    </a>
                @endstaffcan
            </div>

            {{-- Provider purchases --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">Provider purchases</h3>
                        <p class="mt-0.5 text-sm text-gray-500">SMS Primora buys from providers. These set stock and the cost of SMS sold.</p>
                    </div>
                    @staffcan('sms.purchases')
                        <x-button type="button" @click="newPurchase()">Record purchase</x-button>
                    @endstaffcan
                </div>
                @if($purchases->isEmpty())
                    <p class="px-6 py-10 text-center text-sm text-gray-500">No provider purchases recorded yet.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <thead>
                                <tr class="border-b border-gray-200">
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Provider</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">SMS</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Cost</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Per SMS</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Reference</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Recorded by</th>
                                    @staffcan('sms.purchases')<th class="px-6 py-3"></th>@endstaffcan
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($purchases as $p)
                                    <tr>
                                        <td class="px-6 py-3 text-sm text-gray-600 whitespace-nowrap">{{ $p->purchased_on->format('d M Y') }}</td>
                                        <td class="px-6 py-3 text-sm text-gray-900">
                                            {{ $p->provider }}
                                            @if($p->notes)<span class="block text-xs text-gray-500">{{ $p->notes }}</span>@endif
                                        </td>
                                        <td class="px-6 py-3 text-sm text-gray-900 text-right">{{ number_format($p->quantity) }}</td>
                                        <td class="px-6 py-3 text-sm text-gray-900 text-right whitespace-nowrap">UGX {{ number_format((float) $p->total_cost) }}</td>
                                        <td class="px-6 py-3 text-sm text-gray-600 text-right">{{ $fmtCost($p->unitCost()) }}</td>
                                        <td class="px-6 py-3 text-xs text-gray-500 font-mono">{{ $p->reference ?: '—' }}</td>
                                        <td class="px-6 py-3 text-sm text-gray-600">{{ $p->recordedBy?->name ?? '—' }}</td>
                                        @staffcan('sms.purchases')
                                            <td class="px-6 py-3 text-right whitespace-nowrap">
                                                <button type="button" class="text-sm font-medium text-gray-600 hover:text-orange-600"
                                                        @click="editPurchase({{ Js::from(['id' => $p->id, 'provider' => $p->provider, 'quantity' => $p->quantity, 'total_cost' => (float) $p->total_cost, 'paid_from' => $p->paid_from, 'purchased_on' => $p->purchased_on->toDateString(), 'reference' => $p->reference, 'notes' => $p->notes]) }})">Edit</button>
                                                <form action="{{ route('staff.sms.purchases.destroy', $p) }}" method="POST" class="inline" @submit.prevent="$store.confirm.open('Delete this purchase? Stock and cost figures will change.', $el)">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="ml-3 text-sm font-medium text-red-600 hover:text-red-700">Delete</button>
                                                </form>
                                            </td>
                                        @endstaffcan
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if($purchases->hasPages())
                        <div class="px-6 py-4 border-t border-gray-200">{{ $purchases->links() }}</div>
                    @endif
                @endif
            </div>

            {{-- Bundles --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">Bundles</h3>
                        <p class="mt-0.5 text-sm text-gray-500">What landlords can buy on their SMS Credits page. Only active bundles are shown to them.</p>
                    </div>
                    @staffcan('sms.manage')
                        <x-button type="button" @click="newBundle()">Add bundle</x-button>
                    @endstaffcan
                </div>
                @if($bundles->isEmpty())
                    <p class="px-6 py-10 text-center text-sm text-gray-500">No bundles yet. Landlords can't buy SMS until at least one active bundle exists.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <thead>
                                <tr class="border-b border-gray-200">
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Bundle</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">SMS</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Price</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Per SMS</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Margin</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                                    @staffcan('sms.manage')<th class="px-6 py-3"></th>@endstaffcan
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($bundles as $b)
                                    @php $margin = $avg !== null && $b->unitPrice() > 0 ? ($b->unitPrice() - $avg) / $b->unitPrice() * 100 : null; @endphp
                                    <tr>
                                        <td class="px-6 py-3 text-sm font-medium text-gray-900">{{ $b->name }}</td>
                                        <td class="px-6 py-3 text-sm text-gray-900 text-right">{{ number_format($b->quantity) }}</td>
                                        <td class="px-6 py-3 text-sm text-gray-900 text-right whitespace-nowrap">UGX {{ number_format((float) $b->price) }}</td>
                                        <td class="px-6 py-3 text-sm text-gray-600 text-right">{{ $fmtCost($b->unitPrice()) }}</td>
                                        <td class="px-6 py-3 text-sm text-right {{ $margin !== null && $margin < 0 ? 'text-red-600 font-medium' : 'text-gray-600' }}" title="Against the average provider cost">{{ $margin === null ? '—' : number_format($margin, 0).'%' }}</td>
                                        <td class="px-6 py-3"><x-badge :color="$b->is_active ? 'green' : 'gray'">{{ $b->is_active ? 'On sale' : 'Hidden' }}</x-badge></td>
                                        @staffcan('sms.manage')
                                            <td class="px-6 py-3 text-right whitespace-nowrap">
                                                <button type="button" class="text-sm font-medium text-gray-600 hover:text-orange-600"
                                                        @click="editBundle({{ Js::from(['id' => $b->id, 'name' => $b->name, 'quantity' => $b->quantity, 'price' => (float) $b->price, 'sort_order' => $b->sort_order, 'is_active' => $b->is_active]) }})">Edit</button>
                                                <form action="{{ route('staff.sms.bundles.destroy', $b) }}" method="POST" class="inline" @submit.prevent="$store.confirm.open('Delete this bundle? If it has been bought before it will be hidden instead.', $el)">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="ml-3 text-sm font-medium text-red-600 hover:text-red-700">Delete</button>
                                                </form>
                                            </td>
                                        @endstaffcan
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- Settings --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-base font-semibold text-gray-900">Settings</h3>
                </div>
                <form method="POST" action="{{ route('staff.sms.settings.update') }}" class="p-6 grid grid-cols-1 sm:grid-cols-3 gap-6 items-end">
                    @csrf @method('PUT')
                    <div>
                        <x-label :required="true">Free starter SMS</x-label>
                        <x-input type="number" name="starter_credits" min="0" value="{{ old('starter_credits', $settings['starter_credits']) }}" :disabled="! auth('staff')->user()->hasPermission('sms.manage')" />
                        <p class="mt-1 text-xs text-gray-500">Given once to each new landlord. 0 = none.</p>
                    </div>
                    <div>
                        <x-label :required="true">Low-balance warning at</x-label>
                        <x-input type="number" name="low_balance_threshold" min="0" value="{{ old('low_balance_threshold', $settings['low_balance_threshold']) }}" :disabled="! auth('staff')->user()->hasPermission('sms.manage')" />
                        <p class="mt-1 text-xs text-gray-500">Landlords see a "buy SMS" warning at or below this many SMS.</p>
                    </div>
                    @staffcan('sms.manage')
                        <div><x-button type="submit">Save settings</x-button></div>
                    @endstaffcan
                </form>
            </div>
        </div>

        {{-- Purchase modal --}}
        <template x-if="purchaseModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) purchaseModal = false })">
                <x-modal title="Provider purchase">
                    <form method="POST" :action="purchase.id ? '{{ url('staff/sms/purchases') }}/' + purchase.id : '{{ route('staff.sms.purchases.store') }}'" class="space-y-4">
                        @csrf
                        <template x-if="purchase.id"><input type="hidden" name="_method" value="PUT"></template>
                        <div>
                            <x-label :required="true">Provider</x-label>
                            <x-input name="provider" x-model="purchase.provider" list="sms-providers" placeholder="e.g. Marz, Africa's Talking" required />
                            <datalist id="sms-providers">
                                @foreach($purchases->pluck('provider')->unique() as $provider)<option value="{{ $provider }}">@endforeach
                            </datalist>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <x-label :required="true">Number of SMS</x-label>
                                <x-input type="number" name="quantity" min="1" x-model="purchase.quantity" required />
                            </div>
                            <div>
                                <x-label :required="true">Total cost (UGX)</x-label>
                                <x-input type="number" name="total_cost" min="0" step="0.01" x-model="purchase.total_cost" required />
                            </div>
                        </div>
                        <div>
                            <x-label :required="true">Paid from</x-label>
                            <x-select name="paid_from" x-model="purchase.paid_from">
                                <option value="bank">Bank account</option>
                                <option value="yo_pool">Yo! Payments pool</option>
                                <option value="momo_wallet">Mobile money wallet</option>
                                <option value="cash">Cash on hand</option>
                            </x-select>
                        </div>
                        <p class="text-xs text-gray-500" x-show="purchase.quantity > 0 && purchase.total_cost !== ''" x-text="'UGX ' + (purchase.total_cost / purchase.quantity).toFixed(2) + ' per SMS'"></p>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <x-label :required="true">Purchased on</x-label>
                                <x-input type="date" name="purchased_on" x-model="purchase.purchased_on" max="{{ today()->toDateString() }}" required />
                            </div>
                            <div>
                                <x-label>Reference / invoice no.</x-label>
                                <x-input name="reference" x-model="purchase.reference" />
                            </div>
                        </div>
                        <div>
                            <x-label>Notes</x-label>
                            <x-textarea name="notes" rows="2" x-model="purchase.notes"></x-textarea>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" @click="show = false" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900">Cancel</button>
                            <x-button type="submit">Save</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>

        {{-- Bundle modal --}}
        <template x-if="bundleModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) bundleModal = false })">
                <x-modal title="SMS bundle">
                    <form method="POST" :action="bundle.id ? '{{ url('staff/sms/bundles') }}/' + bundle.id : '{{ route('staff.sms.bundles.store') }}'" class="space-y-4">
                        @csrf
                        <template x-if="bundle.id"><input type="hidden" name="_method" value="PUT"></template>
                        <div>
                            <x-label :required="true">Name</x-label>
                            <x-input name="name" x-model="bundle.name" placeholder="e.g. Starter, Standard, Business" required />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <x-label :required="true">Number of SMS</x-label>
                                <x-input type="number" name="quantity" min="1" x-model="bundle.quantity" required />
                            </div>
                            <div>
                                <x-label :required="true">Price (UGX)</x-label>
                                <x-input type="number" name="price" min="500" step="1" x-model="bundle.price" required />
                            </div>
                        </div>
                        <p class="text-xs text-gray-500" x-show="bundle.quantity > 0 && bundle.price > 0">
                            <span x-text="'UGX ' + (bundle.price / bundle.quantity).toFixed(2) + ' per SMS'"></span>
                            @if($avg !== null)<span x-text="' · margin ' + Math.round(((bundle.price / bundle.quantity) - {{ $avg }}) / (bundle.price / bundle.quantity) * 100) + '% on avg cost UGX {{ $fmtCost($avg) }}'"></span>@endif
                        </p>
                        <div class="grid grid-cols-2 gap-4 items-end">
                            <div>
                                <x-label>Display order</x-label>
                                <x-input type="number" name="sort_order" min="0" x-model="bundle.sort_order" />
                            </div>
                            <label class="flex items-center gap-2 pb-2 text-sm text-gray-700">
                                <input type="hidden" name="is_active" value="0">
                                <input type="checkbox" name="is_active" value="1" x-model="bundle.is_active" class="rounded border-gray-300 text-orange-600 focus:ring-orange-500">
                                On sale to landlords
                            </label>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" @click="show = false" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900">Cancel</button>
                            <x-button type="submit">Save</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>
    </div>
</x-staff-layout>
