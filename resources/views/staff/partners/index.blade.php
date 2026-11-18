<x-staff-layout>
    <x-slot name="header">Partners</x-slot>

    <div class="max-w-7xl mx-auto space-y-6" x-data="{ addModal: {{ $errors->any() ? 'true' : 'false' }} }">
        @include('staff.partners._nav')

        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <form method="GET" class="flex gap-2">
                <input type="hidden" name="status" value="{{ $status }}">
                <x-input name="search" value="{{ $search }}" placeholder="Name, agent number, code, phone" class="w-72" />
                <x-button type="submit">Search</x-button>
            </form>
            @staffcan('partners.manage')<x-button type="button" @click="addModal = true">+ Add partner</x-button>@endstaffcan
        </div>

        <div class="flex gap-2 overflow-x-auto">
            @foreach(\App\Http\Controllers\Staff\PartnerController::STATUS_TABS as $key => $label)
                @php $n = $key === 'all' ? $counts->sum() : ($counts[$key] ?? 0); @endphp
                <a href="{{ route('staff.partners.index', ['status' => $key, 'search' => $search ?: null]) }}" class="rounded-xl px-3.5 py-1.5 text-sm font-medium whitespace-nowrap {{ $status === $key ? 'bg-navy-800 text-white' : 'bg-white border border-gray-200 text-gray-600 hover:border-orange-300' }}">
                    {{ $label }} <span class="{{ $status === $key ? 'text-gray-300' : ($key === 'pending' && $n ? 'text-orange-600 font-semibold' : 'text-gray-400') }}">{{ $n }}</span>
                </a>
            @endforeach
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
            @if($partners->isEmpty())
                <p class="px-6 py-12 text-center text-sm text-gray-500">No partners here yet. Agents can apply at <a href="{{ route('partner.apply') }}" class="text-orange-600" target="_blank" hx-boost="false">{{ route('partner.apply') }}</a>.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="border-b border-gray-200 text-xs uppercase tracking-wider text-gray-600">
                            <tr>
                                <th class="px-5 py-3 text-left font-semibold">Partner</th>
                                <th class="px-5 py-3 text-left font-semibold">Agent no.</th>
                                <th class="px-5 py-3 text-left font-semibold">Contact</th>
                                <th class="px-5 py-3 text-right font-semibold">Landlords</th>
                                <th class="px-5 py-3 text-left font-semibold">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($partners as $p)
                                @php [$label, $color] = $p->statusLabel(); @endphp
                                <tr class="hover:bg-gray-50/60">
                                    <td class="px-5 py-3">
                                        <a href="{{ route('staff.partners.show', $p) }}" class="font-medium text-gray-900 hover:text-orange-600">{{ $p->name }}</a>
                                        <p class="text-xs text-gray-500">{{ $p->typeLabel() }} · since {{ $p->created_at->format('d M Y') }}</p>
                                    </td>
                                    <td class="px-5 py-3 font-mono text-gray-700">{{ $p->number }}</td>
                                    <td class="px-5 py-3 text-gray-700">{{ $p->contact_name }}<span class="block text-xs text-gray-500">{{ $p->phone }} {{ $p->email }}</span></td>
                                    <td class="px-5 py-3 text-right font-medium">{{ $p->landlords_count }}</td>
                                    <td class="px-5 py-3"><x-badge :color="$color">{{ $label }}</x-badge></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
        @if($partners->hasPages())<div>{{ $partners->links() }}</div>@endif

        @staffcan('partners.manage')
            <template x-if="addModal">
                <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) addModal = false })">
                    <x-modal title="Add a partner" maxWidth="2xl">
                        <form method="POST" action="{{ route('staff.partners.store') }}" class="space-y-4">
                            @csrf
                            <p class="text-sm text-gray-600">Partners you add are approved straight away. They get a sign-in by email and accept the partner agreement when they first sign in.</p>
                            @include('staff.partners._fields', ['p' => null])
                            <div>
                                <x-label :required="true">Sign-in email</x-label>
                                <x-input type="email" name="user_email" value="{{ old('user_email') }}" required />
                                @error('user_email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div class="flex justify-end gap-3 pt-2">
                                <button type="button" @click="show = false" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900">Cancel</button>
                                <x-button type="submit">Add partner</x-button>
                            </div>
                        </form>
                    </x-modal>
                </div>
            </template>
        @endstaffcan
    </div>
</x-staff-layout>
