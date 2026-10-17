@props([
    'name',
    'value' => '',
    'placeholder' => '0',
    'required' => false,
    'readonly' => false,
])

<div
    x-data="{
        raw: '{{ $value }}',
        display: '',
        formatNumber(val) {
            if (!val && val !== 0 && val !== '0') return '';
            let str = String(val).replace(/[^0-9.]/g, '');
            if (!str) return '';
            let parts = str.split('.');
            parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            return parts.length > 1 ? parts[0] + '.' + parts[1].slice(0, 2) : parts[0];
        },
        onInput(e) {
            let val = e.target.value.replace(/[^0-9.]/g, '');
            // Prevent multiple dots
            let dotIndex = val.indexOf('.');
            if (dotIndex !== -1) {
                val = val.slice(0, dotIndex + 1) + val.slice(dotIndex + 1).replace(/\./g, '');
            }
            this.raw = val;
            // Preserve cursor position
            let pos = e.target.selectionStart;
            let beforeCommas = (e.target.value.slice(0, pos).match(/,/g) || []).length;
            this.display = this.formatNumber(val);
            this.$nextTick(() => {
                let afterCommas = (this.display.slice(0, pos).match(/,/g) || []).length;
                let newPos = pos + (afterCommas - beforeCommas);
                e.target.setSelectionRange(newPos, newPos);
            });
        },
        setValue(val) {
            this.raw = val ? String(val) : '';
            this.display = this.formatNumber(this.raw);
        },
        init() {
            this.display = this.formatNumber(this.raw);
        }
    }"
    x-on:set-money-input.window="if ($event.detail.name === '{{ $name }}') setValue($event.detail.value)"
    class="relative"
    {{ $attributes }}
>
    <input type="hidden" name="{{ $name }}" :value="raw">
    <input
        type="text"
        inputmode="decimal"
        x-model="display"
        @input="onInput($event)"
        placeholder="{{ $placeholder }}"
        {{ $required ? 'required' : '' }}
        {{ $readonly ? 'readonly' : '' }}
        class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 placeholder-gray-400 focus:bg-gray-100 focus:border-orange-500 focus:ring-1 focus:ring-orange-500 transition {{ $readonly ? 'opacity-50 cursor-not-allowed' : '' }}"
        autocomplete="off"
    >
</div>
