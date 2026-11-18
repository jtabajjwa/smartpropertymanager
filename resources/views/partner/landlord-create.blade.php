<x-partner-layout title="Sign up a landlord" subtitle="They get a free trial, and an email to set their own password">
    <div class="max-w-2xl bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
        <form method="POST" action="{{ route('partner.landlords.store') }}" class="space-y-4">
            @csrf
            <div>
                <x-label :required="true">Company or landlord name</x-label>
                <x-input name="company_name" value="{{ old('company_name') }}" maxlength="255" placeholder="e.g. Kampala Properties Ltd" required />
                @error('company_name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <x-label :required="true">Contact person</x-label>
                    <x-input name="name" value="{{ old('name') }}" maxlength="255" placeholder="Grace Namata" required />
                    @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <x-label :required="true">Their email</x-label>
                    <x-input type="email" name="email" value="{{ old('email') }}" required />
                    @error('email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <x-label :required="true">Their phone</x-label>
                    <x-input type="tel" name="phone" value="{{ old('phone') }}" placeholder="0772 123 456" required />
                    @error('phone')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>
            @if($codes->count() > 1)
                <div>
                    <x-label>Code (the offer they get)</x-label>
                    <x-select name="code_id">
                        @foreach($codes as $c)
                            <option value="{{ $c->id }}" @selected((int) old('code_id') === $c->id || (! old('code_id') && $c->is_default))>{{ $c->code }}{{ $c->label ? ' · '.$c->label : '' }}{{ $c->effectivePromotion() ? ' · '.$c->effectivePromotion()->summary() : '' }}</option>
                        @endforeach
                    </x-select>
                </div>
            @elseif($codes->first()?->effectivePromotion())
                <p class="rounded-xl bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">They get your offer: {{ $codes->first()->effectivePromotion()->summary() }}.</p>
            @endif
            <label class="flex items-start gap-3 text-sm text-gray-700">
                <input type="checkbox" name="consent" value="1" @checked(old('consent')) class="mt-0.5 rounded border-gray-300 text-orange-600">
                <span>The landlord asked me to sign them up for Primora and agreed to be contacted.</span>
            </label>
            @error('consent')<p class="-mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
            <div class="flex justify-end"><x-button type="submit">Sign them up</x-button></div>
        </form>
    </div>
</x-partner-layout>
