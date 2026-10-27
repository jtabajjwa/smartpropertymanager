<x-staff-layout>
    <x-slot name="header">Plans</x-slot>

    <div class="py-2" x-data="{
        createModal: false,
        editModal: false,
        createForm: {
            pricing_model: 'flat',
            currency: 'UGX',
            monthly_price: '',
            quarterly_price: '',
            annual_price: '',
            tiers: [{ min_units: 1, max_units: '', monthly_price: '', quarterly_price: '', annual_price: '' }],
        },
        editing: {
            id: null,
            name: '',
            pricing_model: 'flat',
            monthly_price: '',
            quarterly_price: '',
            annual_price: '',
            currency: 'UGX',
            features: [],
            tiers: [],
        },
        addTier(form) {
            form.tiers.push({ min_units: '', max_units: '', monthly_price: '', quarterly_price: '', annual_price: '' });
        },
        removeTier(form, index) {
            form.tiers.splice(index, 1);
        },
        edit(item) {
            this.editing = {
                id: item.id,
                name: item.name,
                pricing_model: item.pricing_model,
                monthly_price: item.monthly_price,
                quarterly_price: item.quarterly_price,
                annual_price: item.annual_price,
                currency: item.currency,
                show_on_website: !!item.show_on_website,
                features: item.features ?? {{ Js::from(array_keys(\App\Support\PlanFeatures::FEATURES)) }},
                tiers: (item.tiers && item.tiers.length) ? item.tiers.map(t => ({
                    min_units: t.min_units,
                    max_units: t.max_units,
                    monthly_price: t.monthly_price,
                    quarterly_price: t.quarterly_price,
                    annual_price: t.annual_price,
                })) : [{ min_units: 1, max_units: '', monthly_price: '', quarterly_price: '', annual_price: '' }],
            };
            this.editModal = true;
        }
    }">
        <div class="max-w-7xl mx-auto">

            {{-- Header --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-6">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Subscription Plans</h3>
                    <p class="mt-1 text-sm text-gray-500">Manage the plans landlords can subscribe to — flat-rate or tiered by unit count.</p>
                </div>
                @staffcan('plans.manage')<x-button @click="createModal = true" type="button">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Add Plan
                </x-button>@endstaffcan
            </div>

            {{-- Table (md and up) --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
                <div class="hidden md:block overflow-x-auto">
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Name</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Pricing</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Monthly</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Quarterly</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Annual</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Currency</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($plans as $plan)
                                <tr class="hover:bg-gray-50 transition-colors align-top">
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $plan->name }}</td>
                                    <td class="px-6 py-4">
                                        <x-badge :color="$plan->isTiered() ? 'blue' : 'gray'">{{ $plan->isTiered() ? 'Tiered' : 'Flat' }}</x-badge>
                                        @if($plan->show_on_website && $plan->is_active) <x-badge color="green" class="ml-1">On website</x-badge> @endif
                                        <span class="mt-1 block text-xs text-gray-500" title="{{ collect($plan->featureKeys())->map(fn ($k) => \App\Support\PlanFeatures::name($k))->implode(', ') }}">{{ $plan->features === null ? 'All features' : count($plan->featureKeys()).' of '.count(\App\Support\PlanFeatures::FEATURES).' features' }}</span>
                                    </td>
                                    @if($plan->isTiered())
                                        <td class="px-6 py-4 text-sm text-gray-600" colspan="3">
                                            <div class="space-y-1">
                                                @foreach($plan->tiers as $tier)
                                                    <div>
                                                        <span class="text-gray-500">{{ $tier->min_units }}{{ $tier->max_units ? '–' . $tier->max_units : '+' }} units:</span>
                                                        {{ number_format($tier->monthly_price, 2) }} / {{ number_format($tier->quarterly_price, 2) }} / {{ number_format($tier->annual_price, 2) }}
                                                    </div>
                                                @endforeach
                                            </div>
                                        </td>
                                    @else
                                        <td class="px-6 py-4 text-sm text-gray-600">{{ number_format($plan->monthly_price, 2) }}</td>
                                        <td class="px-6 py-4 text-sm text-gray-600">{{ number_format($plan->quarterly_price, 2) }}</td>
                                        <td class="px-6 py-4 text-sm text-gray-600">{{ number_format($plan->annual_price, 2) }}</td>
                                    @endif
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ $plan->currency }}</td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            @staffcan('plans.manage')<x-dropdown align="right" width="48">
                                                <x-slot name="content">
                                                    <button @click="edit({{ Js::from($plan) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-600 hover:bg-gray-50 transition">Edit</button>
                                                    <form action="{{ route('staff.plans.destroy', $plan) }}" method="POST" @submit.prevent="$store.confirm.open('Delete this plan? This cannot be undone.', $el)">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-50 transition">Delete</button>
                                                    </form>
                                                </x-slot>
                                            </x-dropdown>@endstaffcan
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-6 py-12 text-center">
                                        <p class="text-sm text-gray-600">No plans yet.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Cards (below md) --}}
                <div class="md:hidden divide-y divide-gray-100">
                    @forelse ($plans as $plan)
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-900">{{ $plan->name }}</p>
                                    <div class="mt-1">
                                        <x-badge :color="$plan->isTiered() ? 'blue' : 'gray'">{{ $plan->isTiered() ? 'Tiered' : 'Flat' }}</x-badge>
                                        @if($plan->show_on_website && $plan->is_active) <x-badge color="green" class="ml-1">On website</x-badge> @endif
                                        <span class="mt-1 block text-xs text-gray-500" title="{{ collect($plan->featureKeys())->map(fn ($k) => \App\Support\PlanFeatures::name($k))->implode(', ') }}">{{ $plan->features === null ? 'All features' : count($plan->featureKeys()).' of '.count(\App\Support\PlanFeatures::FEATURES).' features' }}</span>
                                    </div>
                                </div>
                                @staffcan('plans.manage')<x-dropdown align="right" width="48">
                                    <x-slot name="content">
                                        <button @click="edit({{ Js::from($plan) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-600 hover:bg-gray-50 transition">Edit</button>
                                        <form action="{{ route('staff.plans.destroy', $plan) }}" method="POST" @submit.prevent="$store.confirm.open('Delete this plan? This cannot be undone.', $el)">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-50 transition">Delete</button>
                                        </form>
                                    </x-slot>
                                </x-dropdown>@endstaffcan
                            </div>

                            <div class="mt-3 text-sm text-gray-600 space-y-1">
                                @if($plan->isTiered())
                                    @foreach($plan->tiers as $tier)
                                        <div>
                                            <span class="text-gray-500">{{ $tier->min_units }}{{ $tier->max_units ? '–' . $tier->max_units : '+' }} units:</span>
                                            {{ number_format($tier->monthly_price, 2) }} / {{ number_format($tier->quarterly_price, 2) }} / {{ number_format($tier->annual_price, 2) }} {{ $plan->currency }}
                                        </div>
                                    @endforeach
                                @else
                                    <div><span class="text-gray-500">Monthly:</span> {{ number_format($plan->monthly_price, 2) }} {{ $plan->currency }}</div>
                                    <div><span class="text-gray-500">Quarterly:</span> {{ number_format($plan->quarterly_price, 2) }} {{ $plan->currency }}</div>
                                    <div><span class="text-gray-500">Annual:</span> {{ number_format($plan->annual_price, 2) }} {{ $plan->currency }}</div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="p-8 text-center">
                            <p class="text-sm text-gray-600">No plans yet.</p>
                        </div>
                    @endforelse
                </div>

                @if ($plans->hasPages())
                    <div class="px-4 md:px-6 py-4 border-t border-gray-200">
                        {{ $plans->links() }}
                    </div>
                @endif
            </div>
        </div>

        {{-- Create Modal --}}
        <template x-if="createModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) createModal = false })">
                <x-modal title="Add Plan" maxWidth="xl">
                    <form action="{{ route('staff.plans.store') }}" method="POST" class="space-y-4">
                        @csrf

                        <div>
                            <x-label :required="true">Plan Name</x-label>
                            <x-input name="name" placeholder="e.g. Standard" required />
                        </div>

                        <div>
                            <x-label :required="true">Pricing Model</x-label>
                            <x-select name="pricing_model" x-model="createForm.pricing_model" required>
                                <option value="flat">Flat rate — same price regardless of unit count</option>
                                <option value="tiered">Tiered — price scales with number of units</option>
                            </x-select>
                        </div>

                        <div>
                            <x-label :required="true">Currency</x-label>
                            <x-input name="currency" x-model="createForm.currency" maxlength="3" required class="max-w-[120px]" />
                        </div>

                        <label class="flex items-start gap-3 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 cursor-pointer">
                            <input type="checkbox" name="show_on_website" value="1"  class="mt-0.5 rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                            <span>
                                <span class="block text-sm font-medium text-gray-900">Show on website</span>
                                <span class="block text-xs text-gray-500">Publish this plan's prices on primoraproptech.com. The site picks up changes within about 15 minutes.</span>
                            </span>
                        </label>
                        {{-- Plan features (everything else is on every plan) --}}
                        <div>
                            <input type="hidden" name="features_sent" value="1">
                            <x-label>Features included</x-label>
                            <p class="mb-2 text-xs text-gray-500">Properties, tenants, invoices, payments, the tenant portal, collections, payouts and SMS are on every plan. Leave all ticked to include future features too.</p>
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                @foreach(\App\Support\PlanFeatures::FEATURES as $key => [$fname, $fgives])
                                    <label class="flex items-start gap-2.5 rounded-xl border border-gray-200 px-3 py-2 cursor-pointer hover:border-gray-300">
                                        <input type="checkbox" name="features[]" value="{{ $key }}" checked class="mt-0.5 rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                                        <span><span class="block text-sm font-medium text-gray-900">{{ $fname }}</span><span class="block text-xs text-gray-500">{{ $fgives }}</span></span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        {{-- Flat pricing --}}
                        <div x-show="createForm.pricing_model === 'flat'" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <x-label>Monthly Price</x-label>
                                <x-input type="number" step="0.01" min="0" name="monthly_price" x-model="createForm.monthly_price" :required="false" />
                            </div>
                            <div>
                                <x-label>Quarterly Price</x-label>
                                <x-input type="number" step="0.01" min="0" name="quarterly_price" x-model="createForm.quarterly_price" :required="false" />
                            </div>
                            <div>
                                <x-label>Annual Price</x-label>
                                <x-input type="number" step="0.01" min="0" name="annual_price" x-model="createForm.annual_price" :required="false" />
                            </div>
                        </div>

                        {{-- Tiered pricing --}}
                        <div x-show="createForm.pricing_model === 'tiered'" class="space-y-3">
                            <div class="flex items-center justify-between">
                                <x-label>Unit Tiers</x-label>
                                <button type="button" @click="addTier(createForm)" class="text-xs font-medium text-orange-600 hover:text-orange-700 transition">+ Add Tier</button>
                            </div>

                            <template x-for="(tier, index) in createForm.tiers" :key="index">
                                <div class="grid grid-cols-2 sm:grid-cols-6 gap-3 sm:gap-2 items-end border border-gray-100 rounded-xl p-3">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 mb-1">Min Units</label>
                                        <input type="number" min="0" :name="'tiers[' + index + '][min_units]'" x-model="tier.min_units" required class="w-full rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-2 text-sm" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 mb-1">Max Units</label>
                                        <input type="number" min="0" :name="'tiers[' + index + '][max_units]'" x-model="tier.max_units" placeholder="&infin;" class="w-full rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-2 text-sm" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 mb-1">Monthly</label>
                                        <input type="number" step="0.01" min="0" :name="'tiers[' + index + '][monthly_price]'" x-model="tier.monthly_price" required class="w-full rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-2 text-sm" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 mb-1">Quarterly</label>
                                        <input type="number" step="0.01" min="0" :name="'tiers[' + index + '][quarterly_price]'" x-model="tier.quarterly_price" required class="w-full rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-2 text-sm" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 mb-1">Annual</label>
                                        <input type="number" step="0.01" min="0" :name="'tiers[' + index + '][annual_price]'" x-model="tier.annual_price" required class="w-full rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-2 text-sm" />
                                    </div>
                                    <div class="flex items-center justify-end">
                                        <button type="button" @click="removeTier(createForm, index)" x-show="createForm.tiers.length > 1" class="p-2 text-red-500 hover:text-red-600 transition" title="Remove tier">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    </div>
                                </div>
                            </template>
                            <p class="text-xs text-gray-500">Leave Max Units blank on the highest tier for an open-ended range. Ranges must not overlap.</p>
                        </div>

                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="createModal = false">Cancel</x-button>
                            <x-button>Save Plan</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>

        {{-- Edit Modal --}}
        <template x-if="editModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) editModal = false })">
                <x-modal title="Edit Plan" maxWidth="xl">
                    <form :action="'{{ url('staff/plans') }}/' + editing.id" method="POST" class="space-y-4">
                        @csrf
                        @method('PUT')

                        <div>
                            <x-label :required="true">Plan Name</x-label>
                            <x-input name="name" x-model="editing.name" required />
                        </div>

                        <div>
                            <x-label :required="true">Pricing Model</x-label>
                            <x-select name="pricing_model" x-model="editing.pricing_model" required>
                                <option value="flat">Flat rate — same price regardless of unit count</option>
                                <option value="tiered">Tiered — price scales with number of units</option>
                            </x-select>
                        </div>

                        <div>
                            <x-label :required="true">Currency</x-label>
                            <x-input name="currency" x-model="editing.currency" maxlength="3" required class="max-w-[120px]" />
                        </div>

                        <label class="flex items-start gap-3 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 cursor-pointer">
                            <input type="checkbox" name="show_on_website" value="1" x-model="editing.show_on_website" class="mt-0.5 rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                            <span>
                                <span class="block text-sm font-medium text-gray-900">Show on website</span>
                                <span class="block text-xs text-gray-500">Publish this plan's prices on primoraproptech.com. The site picks up changes within about 15 minutes.</span>
                            </span>
                        </label>
                        {{-- Plan features (everything else is on every plan) --}}
                        <div>
                            <input type="hidden" name="features_sent" value="1">
                            <x-label>Features included</x-label>
                            <p class="mb-2 text-xs text-gray-500">Properties, tenants, invoices, payments, the tenant portal, collections, payouts and SMS are on every plan. Leave all ticked to include future features too.</p>
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                @foreach(\App\Support\PlanFeatures::FEATURES as $key => [$fname, $fgives])
                                    <label class="flex items-start gap-2.5 rounded-xl border border-gray-200 px-3 py-2 cursor-pointer hover:border-gray-300">
                                        <input type="checkbox" name="features[]" value="{{ $key }}" x-model="editing.features" class="mt-0.5 rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                                        <span><span class="block text-sm font-medium text-gray-900">{{ $fname }}</span><span class="block text-xs text-gray-500">{{ $fgives }}</span></span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        {{-- Flat pricing --}}
                        <div x-show="editing.pricing_model === 'flat'" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <x-label>Monthly Price</x-label>
                                <x-input type="number" step="0.01" min="0" name="monthly_price" x-model="editing.monthly_price" :required="false" />
                            </div>
                            <div>
                                <x-label>Quarterly Price</x-label>
                                <x-input type="number" step="0.01" min="0" name="quarterly_price" x-model="editing.quarterly_price" :required="false" />
                            </div>
                            <div>
                                <x-label>Annual Price</x-label>
                                <x-input type="number" step="0.01" min="0" name="annual_price" x-model="editing.annual_price" :required="false" />
                            </div>
                        </div>

                        {{-- Tiered pricing --}}
                        <div x-show="editing.pricing_model === 'tiered'" class="space-y-3">
                            <div class="flex items-center justify-between">
                                <x-label>Unit Tiers</x-label>
                                <button type="button" @click="addTier(editing)" class="text-xs font-medium text-orange-600 hover:text-orange-700 transition">+ Add Tier</button>
                            </div>

                            <template x-for="(tier, index) in editing.tiers" :key="index">
                                <div class="grid grid-cols-2 sm:grid-cols-6 gap-3 sm:gap-2 items-end border border-gray-100 rounded-xl p-3">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 mb-1">Min Units</label>
                                        <input type="number" min="0" :name="'tiers[' + index + '][min_units]'" x-model="tier.min_units" required class="w-full rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-2 text-sm" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 mb-1">Max Units</label>
                                        <input type="number" min="0" :name="'tiers[' + index + '][max_units]'" x-model="tier.max_units" placeholder="&infin;" class="w-full rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-2 text-sm" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 mb-1">Monthly</label>
                                        <input type="number" step="0.01" min="0" :name="'tiers[' + index + '][monthly_price]'" x-model="tier.monthly_price" required class="w-full rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-2 text-sm" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 mb-1">Quarterly</label>
                                        <input type="number" step="0.01" min="0" :name="'tiers[' + index + '][quarterly_price]'" x-model="tier.quarterly_price" required class="w-full rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-2 text-sm" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-500 mb-1">Annual</label>
                                        <input type="number" step="0.01" min="0" :name="'tiers[' + index + '][annual_price]'" x-model="tier.annual_price" required class="w-full rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-2 text-sm" />
                                    </div>
                                    <div class="flex items-center justify-end">
                                        <button type="button" @click="removeTier(editing, index)" x-show="editing.tiers.length > 1" class="p-2 text-red-500 hover:text-red-600 transition" title="Remove tier">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    </div>
                                </div>
                            </template>
                            <p class="text-xs text-gray-500">Leave Max Units blank on the highest tier for an open-ended range. Ranges must not overlap.</p>
                        </div>

                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="editModal = false">Cancel</x-button>
                            <x-button>Update Plan</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>
    </div>
</x-staff-layout>
