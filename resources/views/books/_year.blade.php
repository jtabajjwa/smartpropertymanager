{{-- Fiscal year picker. Needs $years, $year; $custom = true adds a from/to option. --}}
@php $custom = $custom ?? false; @endphp
<form method="GET" class="flex flex-wrap items-end gap-3" x-data="{ mode: '{{ $year ? 'year' : 'custom' }}' }">
    <div class="w-44">
        <x-label>Fiscal year</x-label>
        <x-select name="year" x-bind:disabled="mode === 'custom'" @change="if ($event.target.value === 'custom') { mode = 'custom' } else { $el.form.requestSubmit() }">
            @foreach($years as $value => $label)
                <option value="{{ $value }}" @selected($year === $value)>{{ $label }}</option>
            @endforeach
            @if($custom)<option value="custom" @selected(!$year)>Custom dates…</option>@endif
        </x-select>
    </div>
    @if($custom)
        <template x-if="mode === 'custom'">
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-40"><x-label>From</x-label><x-input type="date" name="from" value="{{ $from->toDateString() }}" required /></div>
                <div class="w-40"><x-label>To</x-label><x-input type="date" name="to" value="{{ $to->toDateString() }}" required /></div>
                <x-button type="submit">Apply</x-button>
                <a href="{{ request()->url() }}" class="pb-2.5 text-sm text-gray-500 hover:text-orange-600">Back to fiscal years</a>
            </div>
        </template>
    @endif
</form>
