{{--
    Photo picker with live preview. Use inside a multipart form.
    `current` is an Alpine expression for the existing photo URL (e.g. "editing.photo_url"),
    or null on create forms. Posts `photo` (file) and, when ticked, `remove_photo`.
--}}
@props(['name' => 'photo', 'current' => null, 'label' => 'Photo'])

<div x-data="{
        preview: null,
        removed: false,
        tooBig: false,
        get current() { return {{ $current ?? 'null' }}; },
        get shown() { return this.preview || (this.removed ? null : this.current); },
        pick(event) {
            const file = event.target.files[0];
            this.tooBig = false;
            if (!file) { this.preview = null; return; }
            if (file.size > 10 * 1024 * 1024) {
                this.tooBig = true;
                event.target.value = '';
                this.preview = null;
                return;
            }
            this.preview = URL.createObjectURL(file);
            this.removed = false;
        },
        clear() {
            this.preview = null;
            this.$refs.file.value = '';
        }
    }">
    <x-label>{{ $label }}</x-label>
    <div class="flex items-start gap-4">
        <div class="w-24 h-24 shrink-0 rounded-xl border border-gray-200 bg-gray-50 overflow-hidden flex items-center justify-center">
            <template x-if="shown">
                <img :src="shown" alt="" class="w-full h-full object-cover">
            </template>
            <template x-if="!shown">
                <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"/></svg>
            </template>
        </div>

        <div class="min-w-0 space-y-2">
            <div class="flex flex-wrap items-center gap-2">
                <label class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl border border-gray-200 bg-white text-sm font-medium text-gray-700 hover:border-orange-500 hover:text-orange-600 cursor-pointer transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z"/></svg>
                    <span x-text="shown ? 'Change photo' : 'Add photo'"></span>
                    <input x-ref="file" type="file" name="{{ $name }}" accept="image/jpeg,image/png,image/webp" class="sr-only" @change="pick($event)">
                </label>
                <button type="button" x-show="preview" @click="clear()" class="text-sm text-gray-500 hover:text-gray-800 transition">Undo</button>
            </div>

            <template x-if="current && !preview">
                <label class="flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" name="remove_photo" value="1" x-model="removed" class="rounded border-gray-300 text-orange-500 focus:ring-orange-500">
                    Remove current photo
                </label>
            </template>

            <p class="text-xs text-gray-500">JPG, PNG or WebP, up to 10 MB. Large photos are resized automatically.</p>
            <p x-show="tooBig" class="text-xs text-red-600">That file is larger than 10 MB. Please choose a smaller photo.</p>
        </div>
    </div>
</div>
