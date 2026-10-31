{{--
    Phone number with a country-code picker, Uganda by default. Submits the
    full international number (+256772123456) under `name`.

    <x-phone-input name="phone" :value="old('phone')" required />
    <x-phone-input name="phone" model="editing.phone" />   (edit modals: follows the Alpine value)
--}}
@props(['name', 'value' => null, 'model' => null, 'required' => false, 'placeholder' => null])

@php
    [$iso, $local] = \App\Support\PhoneCountries::split($value);
    // A stored number without "+" (older records) is shown as typed.
    if ($value && !str_starts_with((string) $value, '+')) {
        $local = (string) $value;
    }
@endphp

<div x-data="phoneInput(@js(\App\Support\PhoneCountries::forJs()), @js($iso), @js($local))"
    @if ($model) x-init="$watch(() => {{ $model }}, v => set(v)); set({{ $model }})" @endif
    {{ $attributes->only('class') }}>
    <div class="flex rounded-xl border bg-gray-50 focus-within:bg-gray-100 focus-within:ring-1 transition"
        :class="error ? 'border-red-400 focus-within:border-red-500 focus-within:ring-red-500' : 'border-gray-200 focus-within:border-orange-500 focus-within:ring-orange-500'">
        {{-- The native select sits invisibly over the "UG +256" label, so phones get their own picker. --}}
        <div class="relative flex items-center gap-1 pl-4 pr-2.5 border-r border-gray-200 text-sm font-medium text-gray-700 shrink-0">
            <span x-text="iso + ' +' + country.dial">{{ $iso }} +{{ \App\Support\PhoneCountries::ALL[$iso][1] }}</span>
            <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
            <select x-model="iso" @change="touched = local.trim() !== ''" aria-label="Country code" class="absolute inset-0 w-full opacity-0 cursor-pointer">
                @foreach (\App\Support\PhoneCountries::ALL as $code => [$countryName, $dial])
                    <option value="{{ $code }}" @selected($code === $iso)>{{ $countryName }} (+{{ $dial }})</option>
                @endforeach
            </select>
        </div>
        <input type="tel" x-model="local" inputmode="numeric" autocomplete="tel-national" maxlength="16" @if ($required) required @endif
            @blur="touched = local.trim() !== ''"
            class="flex-1 min-w-0 border-0 bg-transparent px-3 py-2.5 text-sm text-gray-900 placeholder-gray-400 focus:ring-0 focus:outline-none"
            :placeholder="@js($placeholder) || country.example" placeholder="{{ $placeholder ?? \App\Support\PhoneCountries::ALL[$iso][3] }}" />
    </div>
    <input type="hidden" name="{{ $name }}" :value="full" value="{{ $value }}" />
    <p class="mt-1.5 text-xs text-red-600" x-show="error" x-text="error" x-cloak></p>
</div>
