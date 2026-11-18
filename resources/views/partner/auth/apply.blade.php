<x-partner-layout title="Become a partner" :guest="true">
    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
        <h1 class="text-xl font-bold text-gray-900">Become a Primora partner</h1>
        <p class="mt-1 text-sm text-gray-500">Agents and marketing partners sign landlords up to Primora and earn a share of their subscriptions. Primora reviews every application.</p>

        <form method="POST" action="{{ route('partner.apply') }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <x-label :required="true">I am</x-label>
                <div class="grid grid-cols-2 gap-2">
                    @foreach(\App\Models\Partner::TYPES as $value => $label)
                        <label class="flex items-center gap-2 rounded-xl border border-gray-200 px-3 py-2 text-sm cursor-pointer has-[:checked]:border-orange-500 has-[:checked]:bg-orange-50">
                            <input type="radio" name="type" value="{{ $value }}" @checked(old('type', 'agent') === $value) class="text-orange-600"> {{ $value === 'agent' ? 'An agent (individual)' : 'A marketing company' }}
                        </label>
                    @endforeach
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-label :required="true">Name (yours, or the company's)</x-label>
                    <x-input name="name" value="{{ old('name') }}" maxlength="255" required />
                    @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <x-label :required="true">Contact person</x-label>
                    <x-input name="contact_name" value="{{ old('contact_name') }}" maxlength="255" required />
                    @error('contact_name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <x-label :required="true">Email</x-label>
                    <x-input type="email" name="email" value="{{ old('email') }}" required />
                    @error('email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <x-label :required="true">Phone</x-label>
                    <x-input type="tel" name="phone" value="{{ old('phone') }}" placeholder="0772 123 456" required />
                    @error('phone')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <x-label :required="true">ID type</x-label>
                    <x-select name="id_type">
                        @foreach(['national_id' => 'National ID', 'passport' => 'Passport', 'company_registration' => 'Company registration'] as $v => $l)
                            <option value="{{ $v }}" @selected(old('id_type') === $v)>{{ $l }}</option>
                        @endforeach
                    </x-select>
                </div>
                <div>
                    <x-label :required="true">ID number</x-label>
                    <x-input name="id_number" value="{{ old('id_number') }}" maxlength="60" required />
                    @error('id_number')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>
            <div>
                <x-label>How will you find landlords?</x-label>
                <x-textarea name="about" rows="3" maxlength="1000" placeholder="e.g. I work with property owners in Wakiso and Mukono">{{ old('about') }}</x-textarea>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-label :required="true">Password</x-label>
                    <x-input type="password" name="password" autocomplete="new-password" required />
                    @error('password')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <x-label :required="true">Confirm password</x-label>
                    <x-input type="password" name="password_confirmation" autocomplete="new-password" required />
                </div>
            </div>
            <label class="flex items-start gap-3 text-sm text-gray-600">
                <input type="checkbox" name="agreement" value="1" @checked(old('agreement')) class="mt-0.5 rounded border-gray-300 text-orange-600">
                <span>I have read and accept the <a href="{{ route('partner.agreement.public') }}" target="_blank" hx-boost="false" class="font-medium text-orange-600 underline">partner agreement</a>.</span>
            </label>
            @error('agreement')<p class="-mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
            <x-button type="submit" class="w-full justify-center">Apply</x-button>
        </form>
    </div>
    <p class="mt-6 text-center text-sm text-gray-500">Already a partner? <a href="{{ route('partner.login') }}" class="font-medium text-orange-600 hover:text-orange-700">Sign in</a></p>
</x-partner-layout>
