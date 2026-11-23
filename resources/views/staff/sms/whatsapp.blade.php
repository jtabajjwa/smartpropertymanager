<x-staff-layout>
    <x-slot name="header">SMS</x-slot>

    @php
        $statusColors = ['APPROVED' => 'green', 'PENDING' => 'orange', 'IN_APPEAL' => 'orange', 'REJECTED' => 'red', 'PAUSED' => 'red', 'DISABLED' => 'red', 'NOT_SUBMITTED' => 'gray'];
        $statusText = ['APPROVED' => 'Approved', 'PENDING' => 'In review', 'IN_APPEAL' => 'In appeal', 'REJECTED' => 'Rejected', 'PAUSED' => 'Paused by WhatsApp', 'DISABLED' => 'Disabled', 'NOT_SUBMITTED' => 'Not submitted'];
        $alertNames = [
            'primora_invoice_issued' => 'New invoice (PDF attached)', 'primora_invoice_due_soon' => 'Invoice due soon', 'primora_invoice_overdue' => 'Invoice overdue',
            'primora_payment_received' => 'Payment received (receipt PDF)', 'primora_issue_update' => 'Issue update', 'primora_deposit_deduction' => 'Deposit deduction',
            'primora_deposit_refund' => 'Deposit refund', 'primora_lease_expiring' => 'Lease expiring',
        ];
        $canManage = auth('staff')->user()->hasPermission('sms.manage');
    @endphp

    <div class="py-2" x-data="{
        bundleModal: false,
        bundle: {},
        newBundle() { this.bundle = { id: null, name: '', quantity: '', price: '', sort_order: 0, is_active: true }; this.bundleModal = true; },
        editBundle(b) { this.bundle = { ...b }; this.bundleModal = true; },
    }">
        <div class="max-w-7xl mx-auto space-y-6">
            @include('staff.sms._tabs')

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="bg-white rounded-2xl border border-gray-200 p-5">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Landlords using it</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($stats['landlords_on']) }}</p>
                </div>
                <div class="bg-white rounded-2xl border border-gray-200 p-5">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Alerts sent this month</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($stats['sent_month']) }}</p>
                    <p class="text-xs text-gray-500">{{ number_format($stats['fallback_month']) }} went by SMS instead</p>
                </div>
                <div class="bg-white rounded-2xl border border-gray-200 p-5">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Held by landlords</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($stats['held']) }}</p>
                    <p class="text-xs text-gray-500">prepaid / free alerts not yet used</p>
                </div>
                <div class="bg-white rounded-2xl border border-gray-200 p-5">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Sales this month</p>
                    <p class="mt-1 text-2xl font-bold text-gray-900">UGX {{ number_format($stats['sales_month']) }}</p>
                </div>
            </div>

            {{-- Templates --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">Alert templates</h3>
                        <p class="mt-0.5 text-sm text-gray-500">WhatsApp must approve each alert before Primora can send it. Until then that alert goes by SMS. WhatsApp tells us when it decides; you can also check here.</p>
                    </div>
                    @if($canManage && $accountReady)
                        <div class="flex gap-2">
                            <form method="POST" action="{{ route('staff.sms.whatsapp.templates') }}">@csrf
                                <button type="submit" class="px-4 py-2 rounded-xl border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">Check status</button>
                            </form>
                            @if(collect($templates)->keys()->contains(fn ($n) => ($statuses[$n] ?? 'NOT_SUBMITTED') === 'NOT_SUBMITTED'))
                                <form method="POST" action="{{ route('staff.sms.whatsapp.templates') }}">@csrf
                                    <input type="hidden" name="submit" value="1">
                                    <x-button type="submit">Submit to WhatsApp</x-button>
                                </form>
                            @endif
                        </div>
                    @endif
                </div>
                @unless($accountReady)
                    <p class="px-6 py-3 text-sm text-orange-800 bg-orange-50 border-b border-orange-100">Set WHATSAPP_TOKEN and WHATSAPP_BUSINESS_ACCOUNT_ID on the server to submit and check templates.</p>
                @endunless
                <div class="divide-y divide-gray-100">
                    @foreach($templates as $name => $t)
                        @php $status = $statuses[$name] ?? 'NOT_SUBMITTED'; @endphp
                        <details class="px-6 py-3 group">
                            <summary class="flex items-center justify-between gap-3 cursor-pointer list-none">
                                <span class="min-w-0">
                                    <span class="text-sm font-medium text-gray-900">{{ $alertNames[$name] ?? $name }}</span>
                                    <span class="block text-xs text-gray-500 font-mono">{{ $name }}</span>
                                </span>
                                <x-badge :color="$statusColors[$status] ?? 'gray'">{{ $statusText[$status] ?? ucfirst(strtolower($status)) }}</x-badge>
                            </summary>
                            <div class="mt-3 rounded-xl bg-[#e7f7e3] border border-green-100 p-4 max-w-xl">
                                @if($t['document'])<p class="mb-2 text-xs font-medium text-gray-600">📄 PDF attached</p>@endif
                                <p class="text-sm text-gray-800 whitespace-pre-line">{{ \App\WhatsApp\Templates::render($name, $t['example']) }}</p>
                                <p class="mt-2 text-xs text-gray-500">Primora tenant alerts</p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach($t['buttons'] as $label)
                                        <span class="px-3 py-1 rounded-full bg-white border border-gray-200 text-xs font-medium text-sky-700">{{ $label }}</span>
                                    @endforeach
                                </div>
                            </div>
                        </details>
                    @endforeach
                </div>
            </div>

            {{-- Bundles --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-gray-900">WhatsApp bundles</h3>
                        <p class="mt-0.5 text-sm text-gray-500">What landlords can buy on their WhatsApp Alerts page. 1 credit = 1 alert. Price them above Meta's charge per utility message to Uganda.</p>
                    </div>
                    @if($canManage)
                        <x-button type="button" @click="newBundle()">Add bundle</x-button>
                    @endif
                </div>
                @if($bundles->isEmpty())
                    <p class="px-6 py-10 text-center text-sm text-gray-500">No bundles yet. Landlords can't buy WhatsApp alerts until at least one active bundle exists.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <thead>
                                <tr class="border-b border-gray-200">
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Bundle</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Alerts</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Price</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Per alert</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                                    @if($canManage)<th class="px-6 py-3"></th>@endif
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($bundles as $b)
                                    <tr>
                                        <td class="px-6 py-3 text-sm font-medium text-gray-900">{{ $b->name }}</td>
                                        <td class="px-6 py-3 text-sm text-gray-900 text-right">{{ number_format($b->quantity) }}</td>
                                        <td class="px-6 py-3 text-sm text-gray-900 text-right whitespace-nowrap">UGX {{ number_format((float) $b->price) }}</td>
                                        <td class="px-6 py-3 text-sm text-gray-600 text-right">{{ rtrim(rtrim(number_format($b->unitPrice(), 1), '0'), '.') }}</td>
                                        <td class="px-6 py-3"><x-badge :color="$b->is_active ? 'green' : 'gray'">{{ $b->is_active ? 'On sale' : 'Hidden' }}</x-badge></td>
                                        @if($canManage)
                                            <td class="px-6 py-3 text-right whitespace-nowrap">
                                                <button type="button" class="text-sm font-medium text-gray-600 hover:text-orange-600"
                                                        @click="editBundle({{ Js::from(['id' => $b->id, 'name' => $b->name, 'quantity' => $b->quantity, 'price' => (float) $b->price, 'sort_order' => $b->sort_order, 'is_active' => $b->is_active]) }})">Edit</button>
                                                <form action="{{ route('staff.sms.whatsapp.bundles.destroy', $b) }}" method="POST" class="inline" @submit.prevent="$store.confirm.open('Delete this bundle? If it has been bought before it will be hidden instead.', $el)">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="ml-3 text-sm font-medium text-red-600 hover:text-red-700">Delete</button>
                                                </form>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Settings --}}
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-base font-semibold text-gray-900">Settings</h3>
                    </div>
                    <form method="POST" action="{{ route('staff.sms.whatsapp.settings') }}" class="p-6 space-y-4">
                        @csrf @method('PUT')
                        <div>
                            <x-label :required="true">Free starter alerts</x-label>
                            <x-input type="number" name="starter_credits" min="0" value="{{ old('starter_credits', $settings['starter_credits']) }}" :disabled="! $canManage" />
                            <p class="mt-1 text-xs text-gray-500">Given once, the first time a landlord switches WhatsApp alerts on. 0 = none.</p>
                        </div>
                        <div>
                            <x-label :required="true">Low-balance warning at</x-label>
                            <x-input type="number" name="low_balance_threshold" min="0" value="{{ old('low_balance_threshold', $settings['low_balance_threshold']) }}" :disabled="! $canManage" />
                        </div>
                        @if($canManage)<div class="flex justify-end"><x-button type="submit">Save settings</x-button></div>@endif
                    </form>
                </div>

                {{-- Adjust --}}
                <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-base font-semibold text-gray-900">Correct a landlord's WhatsApp balance</h3>
                        <p class="mt-0.5 text-sm text-gray-500">Add (or take away, with a minus) alerts, e.g. a goodwill top-up. Recorded with your name.</p>
                    </div>
                    @staffcan('sms.credits')
                        <form method="POST" action="{{ route('staff.sms.whatsapp.adjust') }}" class="p-6 space-y-4">
                            @csrf
                            <div>
                                <x-label :required="true">Landlord</x-label>
                                <x-select name="landlord_id" required>
                                    <option value="">Choose…</option>
                                    @foreach($allLandlords as $l)
                                        <option value="{{ $l->id }}" @selected(old('landlord_id') == $l->id)>{{ $l->name }}{{ $l->trading_name ? ' ('.$l->trading_name.')' : '' }}</option>
                                    @endforeach
                                </x-select>
                            </div>
                            <div class="grid grid-cols-3 gap-4">
                                <div>
                                    <x-label :required="true">Alerts</x-label>
                                    <x-input type="number" name="quantity" value="{{ old('quantity') }}" placeholder="e.g. 50 or -10" required />
                                </div>
                                <div class="col-span-2">
                                    <x-label :required="true">Reason</x-label>
                                    <x-input name="note" value="{{ old('note') }}" maxlength="300" required />
                                </div>
                            </div>
                            <div class="flex justify-end"><x-button type="submit">Adjust balance</x-button></div>
                        </form>
                    @else
                        <p class="p-6 text-sm text-gray-500">You don't have permission to change balances.</p>
                    @endstaffcan
                </div>
            </div>

            {{-- Landlords --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-base font-semibold text-gray-900">Landlords with WhatsApp alerts or credit</h3>
                </div>
                @if($landlords->isEmpty())
                    <p class="px-6 py-10 text-center text-sm text-gray-500">No landlord has switched WhatsApp alerts on yet.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <thead>
                                <tr class="border-b border-gray-200">
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Landlord</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Tenant alerts</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Balance</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($landlords as $l)
                                    <tr>
                                        <td class="px-6 py-3 text-sm text-gray-900">{{ $l->name }}@if($l->trading_name)<span class="text-gray-500"> · {{ $l->trading_name }}</span>@endif</td>
                                        <td class="px-6 py-3"><x-badge :color="$l->usesWhatsApp() ? 'green' : 'gray'">{{ $l->alertChannelsLabel() }}</x-badge></td>
                                        <td class="px-6 py-3 text-sm text-right text-gray-900">{{ number_format($l->whatsapp_balance) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                @if($recent->isNotEmpty())
                    <div class="px-6 py-4 border-t border-gray-200">
                        <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 mb-2">Recent purchases and corrections</p>
                        <ul class="space-y-1 text-sm text-gray-700">
                            @foreach($recent as $r)
                                <li>{{ $r->created_at->format('d M Y, H:i') }} · {{ $r->landlord?->name }} · {{ $r->typeLabel() }} {{ $r->quantity >= 0 ? '+' : '' }}{{ number_format($r->quantity) }}@if($r->note) · {{ $r->note }}@endif @if($r->staffUser)<span class="text-gray-500">({{ $r->staffUser->name }})</span>@endif</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </div>

        {{-- Bundle modal --}}
        <template x-if="bundleModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) bundleModal = false })">
                <x-modal title="WhatsApp bundle">
                    <form method="POST" :action="bundle.id ? '{{ url('staff/sms/whatsapp/bundles') }}/' + bundle.id : '{{ route('staff.sms.whatsapp.bundles.store') }}'" class="space-y-4">
                        @csrf
                        <template x-if="bundle.id"><input type="hidden" name="_method" value="PUT"></template>
                        <div>
                            <x-label :required="true">Name</x-label>
                            <x-input name="name" x-model="bundle.name" placeholder="e.g. Starter, Standard, Business" required />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <x-label :required="true">Number of alerts</x-label>
                                <x-input type="number" name="quantity" min="1" x-model="bundle.quantity" required />
                            </div>
                            <div>
                                <x-label :required="true">Price (UGX)</x-label>
                                <x-input type="number" name="price" min="500" step="1" x-model="bundle.price" required />
                            </div>
                        </div>
                        <p class="text-xs text-gray-500" x-show="bundle.quantity > 0 && bundle.price > 0" x-text="'UGX ' + (bundle.price / bundle.quantity).toFixed(1) + ' per alert'"></p>
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
