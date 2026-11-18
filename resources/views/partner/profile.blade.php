<x-partner-layout title="Profile">
    <div class="grid gap-6 lg:grid-cols-2">
        <section class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <h2 class="font-semibold text-gray-900">{{ $partner->name }}</h2>
                @php [$label, $color] = $partner->statusLabel(); @endphp
                <x-badge :color="$color">{{ $label }}</x-badge>
            </div>
            <dl class="divide-y divide-gray-100 text-sm">
                @foreach([['Type', $partner->typeLabel()], ['Agent number', $partner->number], ['Contact person', $partner->contact_name], ['Email', $partner->email], ['Phone', $partner->phone], ['ID', trim(ucwords(str_replace('_', ' ', (string) $partner->id_type)).' '.$partner->id_number)], ['TIN', $partner->tin]] as [$k, $v])
                    <div class="px-5 py-2.5 flex justify-between gap-4"><dt class="text-gray-500">{{ $k }}</dt><dd class="text-gray-900 text-right">{{ $v ?: '—' }}</dd></div>
                @endforeach
            </dl>
            <p class="px-5 py-3 text-xs text-gray-500 border-t border-gray-100">To change these details, contact Primora.</p>
        </section>

        <div class="space-y-6">
            <section class="bg-white rounded-2xl border border-gray-200 shadow-sm p-5 text-sm">
                <h2 class="font-semibold text-gray-900">Partner agreement</h2>
                <p class="mt-1 text-gray-600">{{ $partner->agreement_accepted_at ? 'Accepted on '.$partner->agreement_accepted_at->format('d M Y').'.' : 'Not accepted yet.' }}</p>
                <a href="{{ route('partner.agreement') }}" class="mt-2 inline-block font-medium text-orange-600 hover:text-orange-700">{{ $partner->agreement_accepted_at ? 'Read it' : 'Read and accept' }}</a>
            </section>
            <section class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <h2 class="px-5 py-4 border-b border-gray-100 font-semibold text-gray-900">People who sign in</h2>
                @foreach($users as $u)
                    <div class="px-5 py-3 text-sm flex justify-between gap-3 {{ ! $loop->last ? 'border-b border-gray-100' : '' }}">
                        <span><span class="font-medium text-gray-900">{{ $u->name }}</span> <span class="block text-xs text-gray-500">{{ $u->email }}</span></span>
                        <span class="text-xs text-gray-400">{{ $u->is_active ? ($u->last_login_at ? 'Last in '.$u->last_login_at->diffForHumans() : 'Not signed in yet') : 'Switched off' }}</span>
                    </div>
                @endforeach
                <p class="px-5 py-3 text-xs text-gray-500 border-t border-gray-100">Primora can add sign-ins for your team.</p>
            </section>
        </div>
    </div>
</x-partner-layout>
