<x-staff-layout>
    <x-slot name="header">{{ $partner->name }}</x-slot>

    @php
        [$statusText, $statusColor] = $partner->statusLabel();
        $states = ['trial' => ['Trial', 'orange'], 'trial_ended' => ['Trial ended', 'gray'], 'active' => ['Paying', 'green'], 'expiring' => ['Renewal due', 'yellow'], 'expired' => ['Lapsed', 'gray'], 'past_due' => ['Overdue', 'red'], 'cancelled' => ['Cancelled', 'gray']];
    @endphp

    <div class="max-w-7xl mx-auto space-y-6" x-data="{ editing: false, codeModal: false, userModal: false, assignModal: false, statusForm: null }">
        @include('staff.partners._nav')

        {{-- Header --}}
        <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-xl font-bold text-gray-900">{{ $partner->name }}</h2>
                    <x-badge :color="$statusColor">{{ $statusText }}</x-badge>
                </div>
                <p class="mt-1 text-sm text-gray-500">{{ $partner->typeLabel() }} · agent number <span class="font-mono font-semibold text-gray-800">{{ $partner->number }}</span>
                    · agreement {{ $partner->agreement_accepted_at ? 'accepted '.$partner->agreement_accepted_at->format('d M Y') : 'not accepted yet' }}
                    @if($partner->approved_at) · approved {{ $partner->approved_at->format('d M Y') }}{{ $partner->approvedBy ? ' by '.$partner->approvedBy->name : '' }}@endif
                </p>
                @if($partner->status_note)<p class="mt-1 text-sm text-gray-600">Note: {{ $partner->status_note }}</p>@endif
            </div>
            @staffcan('partners.manage')
                <div class="flex flex-wrap gap-2">
                    @if($partner->status !== 'active')
                        <form method="POST" action="{{ route('staff.partners.status', $partner) }}">@csrf<input type="hidden" name="status" value="active">
                            <x-button type="submit">{{ $partner->approved_at ? 'Reactivate' : 'Approve' }}</x-button></form>
                    @endif
                    @if($partner->status === 'pending')
                        <button type="button" @click="statusForm = 'rejected'" class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:border-red-300 hover:text-red-600">Turn down</button>
                    @elseif($partner->status === 'active')
                        <button type="button" @click="statusForm = 'suspended'" class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:border-red-300 hover:text-red-600">Suspend</button>
                    @endif
                    <button type="button" @click="editing = true" class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:border-orange-300">Edit details</button>
                </div>
            @endstaffcan
        </div>

        <form method="POST" action="{{ route('staff.partners.status', $partner) }}" x-show="statusForm" x-cloak class="bg-red-50 border border-red-200 rounded-2xl p-4 flex flex-wrap items-end gap-3">
            @csrf
            <input type="hidden" name="status" :value="statusForm">
            <div class="flex-1 min-w-64">
                <x-label :required="true"><span x-text="statusForm === 'suspended' ? 'Why suspend? (the partner is told)' : 'Why not approve? (the applicant is told)'"></span></x-label>
                <x-input name="note" maxlength="300" required />
            </div>
            <button type="submit" class="rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white" x-text="statusForm === 'suspended' ? 'Suspend' : 'Turn down'"></button>
            <button type="button" @click="statusForm = null" class="px-3 py-2 text-sm text-gray-600">Cancel</button>
        </form>

        <div class="grid gap-6 lg:grid-cols-3">
            {{-- Details --}}
            <section class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <h3 class="px-5 py-3.5 border-b border-gray-100 font-semibold text-gray-900">Details</h3>
                <dl class="divide-y divide-gray-100 text-sm">
                    @foreach([['Contact', $partner->contact_name], ['Phone', $partner->phone], ['Email', $partner->email], ['ID', trim(ucwords(str_replace('_', ' ', (string) $partner->id_type)).' '.$partner->id_number)], ['TIN', $partner->tin], ['Address', $partner->address], ['Default offer', $partner->defaultPromotion?->name], ['Commission', $plan ? $plan->name.($partner->commission_plan_id ? '' : ' (default)') : 'None: earns nothing']] as [$k, $v])
                        <div class="px-5 py-2.5 flex justify-between gap-4"><dt class="text-gray-500">{{ $k }}</dt><dd class="text-gray-900 text-right">{{ $v ?: '—' }}</dd></div>
                    @endforeach
                </dl>
                @if($partner->about)<p class="px-5 py-3 border-t border-gray-100 text-sm text-gray-600"><span class="block text-xs font-semibold uppercase tracking-wide text-gray-400">From the application</span>{{ $partner->about }}</p>@endif
            </section>

            {{-- Codes --}}
            <section class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="font-semibold text-gray-900">Codes</h3>
                    @staffcan('partners.manage')<button type="button" @click="codeModal = true" class="text-sm font-medium text-orange-600 hover:text-orange-700">+ Add code</button>@endstaffcan
                </div>
                @forelse($partner->codes as $c)
                    <div class="px-5 py-3 text-sm {{ ! $loop->last ? 'border-b border-gray-100' : '' }} {{ $c->is_active ? '' : 'opacity-60' }}" x-data="{ edit: false }">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <span class="font-mono font-semibold text-gray-900">{{ $c->code }}</span>
                                @if($c->is_default)<span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] uppercase text-gray-500">main · = {{ $partner->number }}</span>@endif
                                <p class="text-xs text-gray-500">{{ $c->label ?: '—' }} · {{ $c->effectivePromotion()?->name ?? 'no discount' }} · {{ $codeUses[$c->id] ?? 0 }} landlords</p>
                            </div>
                            @staffcan('partners.manage')<button type="button" @click="edit = !edit" class="text-xs text-gray-400 hover:text-orange-600">Edit</button>@endstaffcan
                        </div>
                        @staffcan('partners.manage')
                            <form method="POST" action="{{ route('staff.partners.codes.update', [$partner, $c]) }}" x-show="edit" x-cloak class="mt-3 space-y-2">
                                @csrf @method('PUT')
                                <x-input name="label" value="{{ $c->label }}" placeholder="Campaign label" maxlength="120" />
                                <x-select name="promotion_id">
                                    <option value="">Partner's default offer</option>
                                    @foreach($promotions as $pr)<option value="{{ $pr->id }}" @selected($c->promotion_id === $pr->id)>{{ $pr->name }}</option>@endforeach
                                </x-select>
                                @unless($c->is_default)
                                    <label class="flex items-center gap-2 text-xs text-gray-600"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($c->is_active) class="rounded border-gray-300 text-orange-600"> Active</label>
                                @endunless
                                <x-button type="submit">Save</x-button>
                            </form>
                        @endstaffcan
                    </div>
                @empty
                    <p class="px-5 py-6 text-sm text-gray-500">The main code is created when the partner is approved.</p>
                @endforelse
            </section>

            {{-- Sign-ins --}}
            <section class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="font-semibold text-gray-900">Portal sign-ins</h3>
                    @staffcan('partners.manage')<button type="button" @click="userModal = true" class="text-sm font-medium text-orange-600 hover:text-orange-700">+ Add</button>@endstaffcan
                </div>
                @foreach($partner->users as $u)
                    <div class="px-5 py-3 text-sm flex items-center justify-between gap-3 {{ ! $loop->last ? 'border-b border-gray-100' : '' }}">
                        <div class="{{ $u->is_active ? '' : 'opacity-60' }}">
                            <p class="font-medium text-gray-900">{{ $u->name }}</p>
                            <p class="text-xs text-gray-500">{{ $u->email }} · {{ $u->last_login_at ? 'last in '.$u->last_login_at->diffForHumans() : 'never signed in' }}</p>
                        </div>
                        @staffcan('partners.manage')
                            <form method="POST" action="{{ route('staff.partners.users.toggle', [$partner, $u]) }}">@csrf
                                <button type="submit" class="text-xs {{ $u->is_active ? 'text-gray-400 hover:text-red-600' : 'text-orange-600' }}">{{ $u->is_active ? 'Switch off' : 'Switch on' }}</button></form>
                        @endstaffcan
                    </div>
                @endforeach
            </section>
        </div>

        {{-- Earnings --}}
        <section class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden" x-data="{ adjusting: false }">
            <div class="px-5 py-3.5 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="font-semibold text-gray-900">Earnings</h3>
                    <p class="text-xs text-gray-500">{{ $plan ? $plan->summary() : 'No commission plan: set one in Edit details, or a default under Commissions.' }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-4 text-sm">
                    <span>On hold <strong>{{ number_format($balances['on_hold']) }}</strong></span>
                    <span>Available <strong class="text-green-700">{{ number_format($balances['available']) }}</strong></span>
                    <span>Paid out <strong>{{ number_format($balances['paid']) }}</strong></span>
                    @staffcan('commissions.manage')<button type="button" @click="adjusting = !adjusting" class="font-medium text-orange-600 hover:text-orange-700">Adjust…</button>@endstaffcan
                </div>
            </div>
            @staffcan('commissions.manage')
                <form method="POST" action="{{ route('staff.partners.adjust', $partner) }}" x-show="adjusting" x-cloak class="px-5 py-3 border-b border-gray-100 bg-gray-50 flex flex-wrap items-end gap-3">
                    @csrf
                    <div class="w-40"><x-label :required="true">Amount (UGX)</x-label><x-input type="number" name="amount" step="1" placeholder="5000 or -5000" required /></div>
                    <div class="flex-1 min-w-64"><x-label :required="true">Reason (the partner sees it)</x-label><x-input name="reason" maxlength="200" required /></div>
                    <x-button type="submit">Record</x-button>
                </form>
            @endstaffcan
            @if($earnings->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-gray-500">Nothing earned yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="border-b border-gray-200 text-xs uppercase tracking-wider text-gray-600">
                            <tr>
                                <th class="px-5 py-2.5 text-left font-semibold">Date</th>
                                <th class="px-5 py-2.5 text-left font-semibold">What</th>
                                <th class="px-5 py-2.5 text-right font-semibold">Earned</th>
                                <th class="px-5 py-2.5 text-right font-semibold">Tax</th>
                                <th class="px-5 py-2.5 text-right font-semibold">Net</th>
                                <th class="px-5 py-2.5 text-left font-semibold">Status</th>
                                <th class="px-5 py-2.5"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($earnings as $e)
                                @php [$sl, $sc] = $e->stateLabel(); @endphp
                                <tr x-data="{ reversing: false }">
                                    <td class="px-5 py-2.5 whitespace-nowrap text-gray-600">{{ $e->earned_at->format('d M Y') }}</td>
                                    <td class="px-5 py-2.5">
                                        <span class="text-gray-900">{{ $e->typeLabel() }}</span>
                                        <span class="block text-xs text-gray-500">{{ $e->description }}@if($e->rate_percent) · {{ rtrim(rtrim(number_format($e->rate_percent, 2), '0'), '.') }}% of {{ number_format($e->base_amount) }}@endif{{ $e->createdBy ? ' · by '.$e->createdBy->name : '' }}</span>
                                    </td>
                                    <td class="px-5 py-2.5 text-right whitespace-nowrap">{{ number_format($e->gross) }}</td>
                                    <td class="px-5 py-2.5 text-right whitespace-nowrap text-gray-500">{{ $e->wht ? number_format($e->wht) : '' }}</td>
                                    <td class="px-5 py-2.5 text-right whitespace-nowrap font-medium {{ $e->net < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format($e->net) }}</td>
                                    <td class="px-5 py-2.5 whitespace-nowrap"><x-badge :color="$sc">{{ $sl }}</x-badge></td>
                                    <td class="px-5 py-2.5 text-right">
                                        @staffcan('commissions.manage')
                                            @if(in_array($e->state(), ['on_hold', 'available'], true) && $e->type !== 'reversal')
                                                <button type="button" x-show="!reversing" @click="reversing = true" class="text-xs text-gray-400 hover:text-red-600">Reverse…</button>
                                                <form method="POST" action="{{ route('staff.commissions.reverse', $e) }}" x-show="reversing" x-cloak class="flex justify-end gap-2">
                                                    @csrf
                                                    <x-input name="reason" placeholder="Reason" required minlength="3" class="w-44" />
                                                    <button type="submit" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white">Reverse</button>
                                                </form>
                                            @endif
                                        @endstaffcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        {{-- Landlords --}}
        <section class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between">
                <h3 class="font-semibold text-gray-900">Landlords ({{ $landlords->count() }})</h3>
                @staffcan('partners.manage')<button type="button" @click="assignModal = true" class="text-sm font-medium text-orange-600 hover:text-orange-700">+ Link a landlord</button>@endstaffcan
            </div>
            @if($landlords->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-gray-500">No landlords linked yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="border-b border-gray-200 text-xs uppercase tracking-wider text-gray-600">
                            <tr>
                                <th class="px-5 py-2.5 text-left font-semibold">Landlord</th>
                                <th class="px-5 py-2.5 text-left font-semibold">How</th>
                                <th class="px-5 py-2.5 text-left font-semibold">Offer</th>
                                <th class="px-5 py-2.5 text-left font-semibold">Subscription</th>
                                <th class="px-5 py-2.5 text-right font-semibold">Earned</th>
                                <th class="px-5 py-2.5"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($landlords as $l)
                                @php [$label, $color] = $states[$l->subscriptionState()] ?? [ucfirst($l->subscriptionState()), 'gray']; @endphp
                                <tr x-data="{ removing: false }">
                                    <td class="px-5 py-2.5"><span class="font-medium text-gray-900">{{ $l->name }}</span><span class="block text-xs text-gray-500">{{ $l->contact_phone }}</span></td>
                                    <td class="px-5 py-2.5 text-gray-600">{{ \App\Services\PartnerProgram::SOURCES[$l->partner_source] ?? '—' }}<span class="block text-xs text-gray-400">{{ $l->partnerCode?->code }} · {{ $l->partner_attributed_at?->format('d M Y') }}{{ $l->partner_locked_at ? ' · fixed' : '' }}</span></td>
                                    <td class="px-5 py-2.5 text-gray-600">{{ $l->promotion?->name ?? '—' }}@if($l->promotion)<span class="block text-xs text-gray-400">{{ $l->promotion_periods_used }} of {{ $l->promotion->periods }} used</span>@endif</td>
                                    <td class="px-5 py-2.5"><x-badge :color="$color">{{ $label }}</x-badge><span class="block text-xs text-gray-500">{{ $l->plan?->name }}</span></td>
                                    <td class="px-5 py-2.5 text-right whitespace-nowrap">{{ number_format((float) ($earnedByLandlord[$l->id] ?? 0)) }}</td>
                                    <td class="px-5 py-2.5 text-right">
                                        @staffcan('partners.manage')
                                            <button type="button" x-show="!removing" @click="removing = true" class="text-xs text-gray-400 hover:text-red-600">Unlink…</button>
                                            <form method="POST" action="{{ route('staff.partners.landlords.remove', [$partner, $l]) }}" x-show="removing" x-cloak class="flex justify-end gap-2">
                                                @csrf
                                                <x-input name="reason" placeholder="Reason" required minlength="3" class="w-48" />
                                                <button type="submit" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white">Unlink</button>
                                            </form>
                                        @endstaffcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        @staffcan('partners.manage')
            <template x-if="editing">
                <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) editing = false })">
                    <x-modal title="Edit partner" maxWidth="2xl">
                        <form method="POST" action="{{ route('staff.partners.update', $partner) }}" class="space-y-4">
                            @csrf @method('PUT')
                            @include('staff.partners._fields', ['p' => $partner])
                            <div class="flex justify-end gap-3 pt-2">
                                <button type="button" @click="show = false" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900">Cancel</button>
                                <x-button type="submit">Save</x-button>
                            </div>
                        </form>
                    </x-modal>
                </div>
            </template>

            <template x-if="codeModal">
                <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) codeModal = false })">
                    <x-modal title="Add a code">
                        <form method="POST" action="{{ route('staff.partners.codes.store', $partner) }}" class="space-y-4">
                            @csrf
                            <div>
                                <x-label :required="true">Code</x-label>
                                <x-input name="code" maxlength="30" placeholder="e.g. JOHN-EXPO" required class="uppercase" />
                                <p class="mt-1 text-xs text-gray-500">Letters, numbers and dashes. Landlords type it at sign-up.</p>
                            </div>
                            <div>
                                <x-label>Campaign</x-label>
                                <x-input name="label" maxlength="120" placeholder="e.g. Kampala property expo" />
                            </div>
                            <div>
                                <x-label>Offer</x-label>
                                <x-select name="promotion_id">
                                    <option value="">The partner's default offer</option>
                                    @foreach($promotions as $pr)<option value="{{ $pr->id }}">{{ $pr->name }} · {{ $pr->summary() }}</option>@endforeach
                                </x-select>
                            </div>
                            <div class="flex justify-end gap-3 pt-2">
                                <button type="button" @click="show = false" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900">Cancel</button>
                                <x-button type="submit">Add code</x-button>
                            </div>
                        </form>
                    </x-modal>
                </div>
            </template>

            <template x-if="userModal">
                <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) userModal = false })">
                    <x-modal title="Add a portal sign-in">
                        <form method="POST" action="{{ route('staff.partners.users.store', $partner) }}" class="space-y-4">
                            @csrf
                            <div><x-label :required="true">Name</x-label><x-input name="name" maxlength="255" required /></div>
                            <div><x-label :required="true">Email</x-label><x-input type="email" name="email" required /></div>
                            <p class="text-xs text-gray-500">They get a temporary password by email, and choose their own when they first sign in.</p>
                            <div class="flex justify-end gap-3 pt-2">
                                <button type="button" @click="show = false" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900">Cancel</button>
                                <x-button type="submit">Add</x-button>
                            </div>
                        </form>
                    </x-modal>
                </div>
            </template>

            <template x-if="assignModal">
                <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) assignModal = false })">
                    <x-modal title="Link a landlord to {{ $partner->name }}">
                        <form method="POST" action="{{ route('staff.partners.landlords.assign', $partner) }}" class="space-y-4">
                            @csrf
                            <div>
                                <x-label :required="true">Landlord</x-label>
                                <x-select name="landlord_id" required>
                                    <option value="">Choose…</option>
                                    @foreach($unlinked as $l)<option value="{{ $l->id }}">{{ $l->name }}{{ $l->contact_phone ? ' · '.$l->contact_phone : '' }}</option>@endforeach
                                </x-select>
                                <p class="mt-1 text-xs text-gray-500">Landlords without a partner. To move one from another partner, unlink them there first.</p>
                            </div>
                            @if($partner->codes->count() > 1)
                                <div>
                                    <x-label>Code</x-label>
                                    <x-select name="code_id">@foreach($partner->codes as $c)<option value="{{ $c->id }}" @selected($c->is_default)>{{ $c->code }}</option>@endforeach</x-select>
                                </div>
                            @endif
                            <div><x-label :required="true">Reason</x-label><x-input name="reason" maxlength="200" placeholder="e.g. Signed up at the expo; forgot the code" required /></div>
                            <div class="flex justify-end gap-3 pt-2">
                                <button type="button" @click="show = false" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900">Cancel</button>
                                <x-button type="submit">Link</x-button>
                            </div>
                        </form>
                    </x-modal>
                </div>
            </template>
        @endstaffcan
    </div>
</x-staff-layout>
