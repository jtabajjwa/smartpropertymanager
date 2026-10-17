<x-app-layout>
    <x-slot name="header">Lease Templates</x-slot>

    <div class="py-8" x-data="{
        createModal: false,
        editModal: false,
        editing: {
            id: null,
            name: '',
            content: '',
            is_default: false
        },
        edit(item) {
            this.editing = { ...item };
            this.editModal = true;
        },
        insertToken(field, token) {
            const el = document.getElementById(field);
            if (!el) return;
            const start = el.selectionStart;
            const end = el.selectionEnd;
            const value = el.value;
            const insert = '{{' + token + '}}';
            el.value = value.slice(0, start) + insert + value.slice(end);
            el.dispatchEvent(new Event('input'));
            el.focus();
            el.selectionStart = el.selectionEnd = start + insert.length;
        }
    }">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            {{-- Header --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between mb-6">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Lease Agreement Templates</h3>
                    <p class="mt-1 text-sm text-gray-500">Draft reusable agreement templates. Generate a filled-in PDF for any lease, then upload the signed scan back onto that lease.</p>
                </div>
                <x-button @click="createModal = true" type="button">
                    <svg class="w-4 h-4 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Add Template
                </x-button>
            </div>

            {{-- Table (md and up) --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-200">
                <div class="hidden md:block overflow-x-auto">
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-b border-gray-200">
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Name</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Preview</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Default</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($leaseTemplates as $template)
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $template->name }}</td>
                                    <td class="px-6 py-4 text-sm text-gray-600">{{ Str::limit(strip_tags($template->content), 70) }}</td>
                                    <td class="px-6 py-4">
                                        @if($template->is_default)
                                            <x-badge color="green">Default</x-badge>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <x-dropdown align="right" width="48">
                                                <x-slot name="content">
                                                    <button @click="edit({{ Js::from($template) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-600 hover:bg-gray-50 transition">Edit</button>
                                                    <form action="{{ route('lease-templates.destroy', $template) }}" method="POST" @submit.prevent="$store.confirm.open('Delete this template?', $el)">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-50 transition">Delete</button>
                                                    </form>
                                                </x-slot>
                                            </x-dropdown>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-12 text-center">
                                        <p class="text-sm text-gray-600">No templates yet.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Cards (below md) --}}
                <div class="md:hidden divide-y divide-gray-100">
                    @forelse ($leaseTemplates as $template)
                        <div class="p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-900 break-words">{{ $template->name }}</p>
                                    <p class="mt-0.5 text-sm text-gray-600 break-words">{{ Str::limit(strip_tags($template->content), 70) }}</p>
                                </div>
                                <x-dropdown align="right" width="48">
                                    <x-slot name="content">
                                        <button @click="edit({{ Js::from($template) }})" type="button" class="w-full text-left px-4 py-2 text-sm text-orange-600 hover:bg-gray-50 transition">Edit</button>
                                        <form action="{{ route('lease-templates.destroy', $template) }}" method="POST" @submit.prevent="$store.confirm.open('Delete this template?', $el)">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-50 transition">Delete</button>
                                        </form>
                                    </x-slot>
                                </x-dropdown>
                            </div>
                            @if($template->is_default)
                                <div class="mt-3"><x-badge color="green">Default</x-badge></div>
                            @endif
                        </div>
                    @empty
                        <div class="p-8 text-center">
                            <p class="text-sm text-gray-600">No templates yet.</p>
                        </div>
                    @endforelse
                </div>

                @if ($leaseTemplates->hasPages())
                    <div class="px-4 md:px-6 py-4 border-t border-gray-200">
                        {{ $leaseTemplates->links() }}
                    </div>
                @endif
            </div>
        </div>

        {{-- Create Modal --}}
        <template x-if="createModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) createModal = false })">
                <x-modal title="Add Lease Template" maxWidth="2xl">
                    <form action="{{ route('lease-templates.store') }}" method="POST" class="space-y-5">
                        @csrf
                        <div>
                            <x-label :required="true">Template Name</x-label>
                            <x-input name="name" placeholder="e.g. Standard Residential Agreement" required />
                        </div>
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <x-label :required="true">Content</x-label>
                            </div>
                            <textarea id="create_content" name="content" rows="12" required placeholder="Draft the agreement here. Click a token below to insert it at the cursor..."
                                class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 placeholder-gray-400 focus:bg-gray-100 focus:border-orange-500 focus:ring-1 focus:ring-orange-500 transition font-mono"></textarea>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @foreach(\App\Models\LeaseTemplate::TOKENS as $token => $description)
                                    <button type="button" @click="insertToken('create_content', '{{ $token }}')" title="{{ $description }}"
                                        class="px-2 py-1 text-xs font-mono rounded-lg bg-orange-500/10 text-orange-700 hover:bg-orange-500/20 transition">{{ \App\Models\LeaseTemplate::wrappedToken($token) }}</button>
                                @endforeach
                            </div>
                        </div>
                        <div class="flex items-center gap-2 pt-2">
                            <input type="checkbox" id="create_is_default" name="is_default" value="1" class="w-4 h-4 text-orange-500 bg-gray-50 border-gray-200 rounded focus:ring-orange-500 focus:ring-offset-white transition duration-200 cursor-pointer">
                            <label for="create_is_default" class="text-sm text-gray-700 font-medium cursor-pointer select-none">Use as default template</label>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="createModal = false">Cancel</x-button>
                            <x-button>Save Template</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>

        {{-- Edit Modal --}}
        <template x-if="editModal">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true); $watch('show', v => { if (!v) editModal = false })">
                <x-modal title="Edit Lease Template" maxWidth="2xl">
                    <form :action="'{{ url('lease-templates') }}/' + editing.id" method="POST" class="space-y-5">
                        @csrf
                        @method('PUT')
                        <div>
                            <x-label :required="true">Template Name</x-label>
                            <x-input name="name" x-model="editing.name" required />
                        </div>
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <x-label :required="true">Content</x-label>
                            </div>
                            <textarea id="edit_content" name="content" rows="12" required x-model="editing.content"
                                class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 placeholder-gray-400 focus:bg-gray-100 focus:border-orange-500 focus:ring-1 focus:ring-orange-500 transition font-mono"></textarea>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @foreach(\App\Models\LeaseTemplate::TOKENS as $token => $description)
                                    <button type="button" @click="insertToken('edit_content', '{{ $token }}')" title="{{ $description }}"
                                        class="px-2 py-1 text-xs font-mono rounded-lg bg-orange-500/10 text-orange-700 hover:bg-orange-500/20 transition">{{ \App\Models\LeaseTemplate::wrappedToken($token) }}</button>
                                @endforeach
                            </div>
                        </div>
                        <div class="flex items-center gap-2 pt-2">
                            <input type="checkbox" id="edit_is_default" name="is_default" value="1" x-model="editing.is_default" class="w-4 h-4 text-orange-500 bg-gray-50 border-gray-200 rounded focus:ring-orange-500 focus:ring-offset-white transition duration-200 cursor-pointer">
                            <label for="edit_is_default" class="text-sm text-gray-700 font-medium cursor-pointer select-none">Use as default template</label>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <x-button variant="secondary" type="button" @click="editModal = false">Cancel</x-button>
                            <x-button>Update Template</x-button>
                        </div>
                    </form>
                </x-modal>
            </div>
        </template>
    </div>
</x-app-layout>
