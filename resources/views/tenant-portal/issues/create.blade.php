<x-tenant-layout title="Report an issue" subtitle="Your landlord is notified by email as soon as you send it">
    <x-slot name="actions"><a href="{{ route('tenant.issues.index') }}" class="text-sm font-medium text-gray-500 hover:text-gray-900">← All issues</a></x-slot>

    <a href="{{ route('tenant.issues.index') }}" class="inline-block mb-3 text-sm text-gray-500 hover:text-gray-900 lg:hidden">← Issues</a>
    <h1 class="text-xl font-bold text-gray-900 mb-4 lg:hidden">Report an issue</h1>

    <div class="lg:grid lg:grid-cols-3 lg:gap-6 lg:items-start">

    <form method="POST" action="{{ route('tenant.issues.store') }}" enctype="multipart/form-data" class="bg-white rounded-2xl border border-gray-200 p-4 lg:p-6 lg:shadow-sm space-y-4 lg:space-y-5 lg:col-span-2"
          x-data="{ category: '{{ old('category', 'repair') }}', preview: null, busy: false }" @submit="busy = true">
        @csrf

        <div>
            <x-label :required="true">What kind of issue?</x-label>
            <div class="grid grid-cols-3 gap-2">
                @foreach(\App\Models\Issue::CATEGORIES as $value => $label)
                    <label class="cursor-pointer">
                        <input type="radio" name="category" value="{{ $value }}" x-model="category" class="sr-only">
                        <span class="block rounded-xl border px-2 py-2.5 text-center text-xs font-medium transition" :class="category === '{{ $value }}' ? 'border-orange-500 bg-orange-50 text-orange-700' : 'border-gray-200 text-gray-600'">{{ Str::before($label, ' /') }}</span>
                    </label>
                @endforeach
            </div>
        </div>

        @if($leases->count() > 1)
            <div>
                <x-label>Which unit?</x-label>
                <x-select name="lease_id">
                    @foreach($leases as $lease)
                        <option value="{{ $lease->id }}" @selected((string) old('lease_id') === (string) $lease->id)>Unit {{ $lease->unit?->name }}{{ $lease->unit?->estate ? ', '.$lease->unit->estate->name : '' }}</option>
                    @endforeach
                </x-select>
            </div>
        @endif

        <div>
            <x-label :required="true">Title</x-label>
            <x-input name="title" value="{{ old('title') }}" maxlength="150" placeholder="e.g. Leaking pipe under the kitchen sink" required />
            @error('title')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <x-label :required="true">Details</x-label>
            <x-textarea name="description" rows="5" maxlength="5000" placeholder="What happened, where exactly, and since when?" required>{{ old('description') }}</x-textarea>
            @error('description')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <x-label>Photo (optional)</x-label>
            <label class="flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-gray-200 px-4 py-5 text-center cursor-pointer hover:border-orange-400">
                <template x-if="preview"><img :src="preview" alt="" class="max-h-48 rounded-lg"></template>
                <span class="text-sm text-gray-600" x-text="preview ? 'Change photo' : 'Take or choose a photo'"></span>
                <input type="file" name="photo" accept="image/*" class="sr-only" @change="const f = $event.target.files[0]; preview = f ? URL.createObjectURL(f) : null">
            </label>
            @error('photo')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
        </div>

        <x-button type="submit" class="w-full lg:w-auto justify-center py-3 lg:px-6" x-bind:disabled="busy">
            <span x-show="!busy">Send to my landlord</span>
            <span x-show="busy" x-cloak>Sending…</span>
        </x-button>
    </form>

    <aside class="hidden lg:block rounded-2xl border border-gray-200 bg-white p-6 shadow-sm text-sm text-gray-600 lg:sticky lg:top-24">
        <h2 class="font-semibold text-gray-900">What happens next</h2>
        <ol class="mt-4 space-y-4">
            <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-orange-100 text-xs font-semibold text-orange-700">1</span><span>Your landlord gets an email with the details and your photo.</span></li>
            <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-orange-100 text-xs font-semibold text-orange-700">2</span><span>They update the status as they work on it. You'll see every update here.</span></li>
            <li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-orange-100 text-xs font-semibold text-orange-700">3</span><span>Not fixed? Add a note to a resolved issue and it opens again.</span></li>
        </ol>
        <p class="mt-5 rounded-xl bg-red-50 border border-red-100 p-3 text-xs text-red-700">For emergencies (fire, gas, flooding), call your landlord and the emergency services directly.</p>
    </aside>
    </div>
</x-tenant-layout>
