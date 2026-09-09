@props(['required' => false])

<label {{ $attributes->merge(['class' => 'block text-sm font-medium text-gray-600 mb-1']) }}>
    {{ $slot }}@if($required)<span class="text-red-500 ml-0.5">*</span>@endif
</label>
