{{-- Period picker for books pages. Needs $period, $from, $to. --}}
<form method="GET" class="flex flex-wrap items-end gap-3" x-data="{ period: '{{ $period }}' }">
    @foreach(request()->except(['period', 'from', 'to', 'page']) as $k => $v)
        @if(is_scalar($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
    @endforeach
    <div class="w-44">
        <x-label>Period</x-label>
        <x-select name="period" x-model="period" @change="if (period !== 'custom') $el.form.requestSubmit()">
            @foreach(\App\Http\Controllers\Staff\BooksController::PERIODS as $value => $label)
                <option value="{{ $value }}" @selected($period === $value)>{{ $label }}</option>
            @endforeach
        </x-select>
    </div>
    <template x-if="period === 'custom'">
        <div class="flex items-end gap-3">
            <div class="w-40"><x-label>From</x-label><x-input type="date" name="from" value="{{ $from?->toDateString() }}" /></div>
            <div class="w-40"><x-label>To</x-label><x-input type="date" name="to" value="{{ $to?->toDateString() }}" /></div>
            <x-button type="submit">Apply</x-button>
        </div>
    </template>
</form>
