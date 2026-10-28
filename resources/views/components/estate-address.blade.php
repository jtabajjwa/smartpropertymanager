{{--
    An estate's address, country and district. Ugandan districts are picked
    from a list; for other countries the district is typed.
    Posts `address`, `country` and `district`.

    model: Alpine object holding the estate being edited (e.g. "editing"); omit when adding.
--}}
@props(['model' => null])

<div x-data="{ country: ({{ $model ? $model.'?.country' : 'null' }}) || @js(\App\Support\Places::DEFAULT_COUNTRY), district: ({{ $model ? $model.'?.district' : 'null' }}) || '' }" class="space-y-4">
    <div>
        <x-label>Address</x-label>
        <x-input name="address" placeholder="e.g. Plot 4, Kololo Hill Drive" maxlength="255" :x-model="$model ? $model.'.address' : null" />
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <x-label :required="true">Country</x-label>
            <x-select name="country" x-model="country" required>
                @foreach(\App\Support\Places::COUNTRIES as $country)
                    <option value="{{ $country }}">{{ $country }}</option>
                @endforeach
            </x-select>
        </div>
        <div>
            <x-label>District</x-label>
            <template x-if="country === 'Uganda'">
                <x-select name="district" x-model="district">
                    <option value="">Select a district…</option>
                    @foreach(\App\Support\Places::UGANDA_DISTRICTS as $district)
                        <option value="{{ $district }}">{{ $district }}</option>
                    @endforeach
                </x-select>
            </template>
            <template x-if="country !== 'Uganda'">
                <x-input name="district" x-model="district" placeholder="District, county or region" maxlength="100" />
            </template>
        </div>
    </div>
</div>
