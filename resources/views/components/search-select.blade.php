@props([
    'name',
    'url',
    'value' => '',
    'label' => '',
    'placeholder' => 'Search...',
    'required' => false,
])

<div
    x-data="{
        open: false,
        search: '{{ $label }}',
        selectedId: '{{ $value }}',
        baseUrl: '{{ $url }}',
        results: [],
        loading: false,
        debounce: null,
        async fetchResults() {
            if (this.selectedId || !this.baseUrl) return;
            this.loading = true;
            try {
                const sep = this.baseUrl.includes('?') ? '&' : '?';
                const response = await fetch(`${this.baseUrl}${sep}q=${encodeURIComponent(this.search)}&limit=20`);
                this.results = await response.json();
            } catch (e) {
                this.results = [];
            }
            this.loading = false;
        },
        onInput() {
            this.selectedId = '';
            this.open = true;
            clearTimeout(this.debounce);
            this.debounce = setTimeout(() => this.fetchResults(), 250);
        },
        select(item) {
            this.selectedId = item.id;
            this.search = item.text;
            this.open = false;
            this.results = [];
            this.$dispatch('search-selected', { name: '{{ $name }}', id: item.id, text: item.text });
        },
        clear() {
            this.selectedId = '';
            this.search = '';
            this.results = [];
            this.open = false;
            this.$dispatch('search-selected', { name: '{{ $name }}', id: '', text: '' });
        },
        onFocus() {
            if (this.results.length === 0) this.fetchResults();
            this.open = true;
        },
        setValue(id, text) {
            this.selectedId = id ? String(id) : '';
            this.search = text || '';
            this.results = [];
            this.open = false;
            this.$dispatch('search-selected', { name: '{{ $name }}', id: this.selectedId, text: this.search });
        }
    }"
    x-on:set-search-select.window="if ($event.detail.name === '{{ $name }}') setValue($event.detail.id, $event.detail.text)"
    x-on:set-search-url.window="if ($event.detail.name === '{{ $name }}') { baseUrl = $event.detail.url; results = []; }"
    @click.outside="open = false"
    class="relative"
>
    <input type="hidden" name="{{ $name }}" :value="selectedId">
    <div class="relative">
        <input
            type="text"
            x-model="search"
            @input="onInput()"
            @focus="onFocus()"
            @keydown.escape="open = false"
            placeholder="{{ $placeholder }}"
            {{ $required ? 'required' : '' }}
            class="w-full rounded-xl border border-white/10 bg-gray-800 px-4 py-2.5 text-sm text-white placeholder-gray-500 focus:bg-gray-700 focus:border-orange-500 focus:ring-1 focus:ring-orange-500 transition pr-8"
            autocomplete="off"
        >
        <button
            type="button"
            x-show="selectedId || search"
            @click="clear()"
            class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-500 hover:text-white transition"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    <!-- Dropdown -->
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 -translate-y-1"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="absolute z-50 mt-1 w-full bg-gray-800 border border-white/10 rounded-xl shadow-xl max-h-48 overflow-y-auto"
        style="display: none;"
    >
        <template x-if="loading">
            <div class="px-4 py-3 text-sm text-gray-500">Searching...</div>
        </template>
        <template x-if="!loading && results.length === 0">
            <div class="px-4 py-3 text-sm text-gray-500">No results found</div>
        </template>
        <template x-for="item in results" :key="item.id">
            <button
                type="button"
                @click="select(item)"
                class="w-full text-left px-4 py-2.5 text-sm text-gray-300 hover:bg-white/10 hover:text-white transition"
                x-text="item.text"
            ></button>
        </template>
    </div>
</div>
