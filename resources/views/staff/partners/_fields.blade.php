{{-- Partner details, for adding and editing ($p = the partner, or null). --}}
<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <x-label :required="true">Type</x-label>
        <x-select name="type">
            @foreach(\App\Models\Partner::TYPES as $v => $l)<option value="{{ $v }}" @selected(old('type', $p?->type ?? 'agent') === $v)>{{ $l }}</option>@endforeach
        </x-select>
    </div>
    <div>
        <x-label :required="true">Name (person or company)</x-label>
        <x-input name="name" value="{{ old('name', $p?->name) }}" maxlength="255" required />
    </div>
    <div>
        <x-label>Contact person</x-label>
        <x-input name="contact_name" value="{{ old('contact_name', $p?->contact_name) }}" maxlength="255" />
    </div>
    <div>
        <x-label>Phone</x-label>
        <x-input type="tel" name="phone" value="{{ old('phone', $p?->phone) }}" />
    </div>
    <div>
        <x-label>Email</x-label>
        <x-input type="email" name="email" value="{{ old('email', $p?->email) }}" />
    </div>
    <div>
        <x-label>TIN</x-label>
        <x-input name="tin" value="{{ old('tin', $p?->tin) }}" maxlength="30" />
    </div>
    <div>
        <x-label>ID type</x-label>
        <x-select name="id_type">
            <option value="">—</option>
            @foreach(['national_id' => 'National ID', 'passport' => 'Passport', 'company_registration' => 'Company registration'] as $v => $l)<option value="{{ $v }}" @selected(old('id_type', $p?->id_type) === $v)>{{ $l }}</option>@endforeach
        </x-select>
    </div>
    <div>
        <x-label>ID number</x-label>
        <x-input name="id_number" value="{{ old('id_number', $p?->id_number) }}" maxlength="60" />
    </div>
    <div class="sm:col-span-2">
        <x-label>Address</x-label>
        <x-input name="address" value="{{ old('address', $p?->address) }}" maxlength="300" />
    </div>
    <div class="sm:col-span-2">
        <x-label>Commission plan</x-label>
        <x-select name="commission_plan_id">
            <option value="">The default plan{{ \App\Services\PartnerCommissions::defaultPlan() ? ' ('.\App\Services\PartnerCommissions::defaultPlan()->name.')' : ' (none set: earns nothing)' }}</option>
            @foreach(\App\Models\CommissionPlan::where('is_active', true)->orderBy('name')->get() as $cp)<option value="{{ $cp->id }}" @selected((int) old('commission_plan_id', $p?->commission_plan_id) === $cp->id)>{{ $cp->name }} · {{ $cp->summary() }}</option>@endforeach
        </x-select>
    </div>
    <div class="sm:col-span-2">
        <x-label>Default promotion</x-label>
        <x-select name="default_promotion_id">
            <option value="">None: no discount with their codes</option>
            @foreach($promotions as $pr)<option value="{{ $pr->id }}" @selected((int) old('default_promotion_id', $p?->default_promotion_id) === $pr->id)>{{ $pr->name }} · {{ $pr->summary() }}{{ $pr->is_active ? '' : ' (off)' }}</option>@endforeach
        </x-select>
        <p class="mt-1 text-xs text-gray-500">What landlords get with this partner's main code and agent number (a code can have its own).</p>
    </div>
</div>
