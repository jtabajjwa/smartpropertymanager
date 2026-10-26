{{--
    Role + permission checkboxes for the staff-user form. Posts `role` and `permissions[]`.
    `role` / `permissions` are Alpine expressions for the starting values.
--}}
@props(['role' => "'user'", 'permissions' => '[]'])

<div x-data="{
        role: {{ $role }},
        perms: [...({{ $permissions }} || [])],
        presets: {{ Js::from(collect(\App\Support\StaffPermissions::PRESETS)->map(fn ($p) => $p['permissions'])) }},
        implies: {{ Js::from(\App\Support\StaffPermissions::IMPLIES) }},
        has(p) { return this.perms.includes(p) },
        toggle(p, on) {
            if (on) {
                const add = [p, ...(this.implies[p] || [])];
                this.perms = [...new Set([...this.perms, ...add])];
            } else {
                this.perms = this.perms.filter(x => x !== p);
            }
        },
        applyPreset(key) { this.perms = [...this.presets[key]] },
    }" class="space-y-4">
    <div>
        <x-label :required="true">Role</x-label>
        <x-select name="role" x-model="role" required>
            <option value="user">Staff (chosen permissions)</option>
            <option value="admin">Admin (everything, incl. managing staff)</option>
        </x-select>
    </div>

    <div x-show="role === 'admin'" class="rounded-xl bg-orange-50 border border-orange-100 px-4 py-3 text-sm text-orange-800">
        Admins can do everything in the staff portal, including managing staff accounts and their permissions.
    </div>

    <div x-show="role !== 'admin'" class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs font-medium text-gray-500">Start from:</span>
            @foreach(\App\Support\StaffPermissions::PRESETS as $key => $preset)
                <button type="button" @click="applyPreset('{{ $key }}')" class="px-2.5 py-1 rounded-lg border border-gray-200 text-xs font-medium text-gray-700 hover:border-orange-500 hover:text-orange-600 transition">{{ $preset['label'] }}</button>
            @endforeach
            <button type="button" @click="perms = []" class="px-2.5 py-1 text-xs text-gray-500 hover:text-gray-800">Clear</button>
            <span class="ml-auto text-xs text-gray-500" x-text="perms.length + ' selected'"></span>
        </div>

        <div class="max-h-80 overflow-y-auto rounded-xl border border-gray-200 divide-y divide-gray-100">
            @foreach(\App\Support\StaffPermissions::GROUPS as $group => $permissions)
                <fieldset class="px-4 py-3">
                    <legend class="text-xs font-semibold uppercase tracking-wider text-gray-400">{{ $group }}</legend>
                    <div class="mt-2 space-y-2">
                        @foreach($permissions as $key => [$label, $description])
                            <label class="flex items-start gap-3 cursor-pointer">
                                <input type="checkbox" name="permissions[]" value="{{ $key }}"
                                       :checked="has('{{ $key }}')" @change="toggle('{{ $key }}', $event.target.checked)"
                                       :disabled="role === 'admin'"
                                       class="mt-0.5 rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                                <span>
                                    <span class="block text-sm font-medium text-gray-900">{{ $label }}</span>
                                    <span class="block text-xs text-gray-500">{{ $description }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach
        </div>
        <p class="text-xs text-gray-500">Doing something includes seeing it: e.g. "Manage plans" also allows "View plans". Staff management is for admins only.</p>
    </div>
</div>
