<x-app-layout>
    <x-slot name="header">Company Profile</x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">

            {{-- Header --}}
            <div class="mb-6">
                <h3 class="text-lg font-semibold text-gray-900">Company Profile & Invoice Branding</h3>
                <p class="mt-1 text-sm text-gray-500">Your logo, brand color, and footer note appear on every generated invoice and email.</p>
                @if($landlord->number)
                    <p class="mt-2 text-sm text-gray-500">Your landlord number is <strong class="text-gray-900 tabular-nums">{{ $landlord->number }}</strong>. Every unit ID you're given starts with it; tenants sign in to the portal with their unit ID.</p>
                @endif
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
                <form action="{{ route('company-profile.update') }}" method="POST" enctype="multipart/form-data" class="space-y-6" x-data="{ color: '{{ old('brand_color', $landlord->brand_color) }}' }">
                    @csrf
                    @method('PUT')

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-label :required="true">Company Name</x-label>
                            <x-input name="name" value="{{ old('name', $landlord->name) }}" required />
                        </div>
                        <div>
                            <x-label>Trading Name</x-label>
                            <x-input name="trading_name" value="{{ old('trading_name', $landlord->trading_name) }}" />
                        </div>
                    </div>

                    <div>
                        <x-label>Address</x-label>
                        <x-input name="address" value="{{ old('address', $landlord->address) }}" placeholder="Shown under your logo on invoices and agreements" />
                    </div>

                    <div class="border-t border-gray-100 pt-6">
                        <p class="text-xs font-semibold uppercase tracking-wider text-gray-400 mb-3">Invoice Branding</p>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                            <div>
                                <x-label>Logo</x-label>
                                @if($landlord->logo)
                                    <div class="mb-2">
                                        <img src="{{ $landlord->logoUrl() }}" alt="Current logo" class="h-16 w-auto rounded-lg border border-gray-200 bg-gray-50 p-2" />
                                    </div>
                                @endif
                                <input type="file" name="logo" accept="image/png,image/jpeg,image/svg+xml" class="block w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-medium file:bg-orange-500/10 file:text-orange-600 hover:file:bg-orange-500/20 cursor-pointer" />
                                <p class="mt-1 text-xs text-gray-500">PNG, JPG or SVG, up to 2MB.</p>
                            </div>

                            <div>
                                <x-label :required="true">Brand Color</x-label>
                                <div class="flex items-center gap-3">
                                    <input type="color" name="brand_color" x-model="color" class="h-10 w-14 rounded-lg border border-gray-200 cursor-pointer bg-white" />
                                    <input type="text" x-model="color" placeholder="#c1704a" pattern="^#[0-9a-fA-F]{6}$" class="w-full rounded-xl border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 placeholder-gray-400 focus:bg-gray-100 focus:border-orange-500 focus:ring-1 focus:ring-orange-500 transition font-mono" />
                                </div>
                                <p class="mt-1 text-xs text-gray-500">Used for accents on invoices and email receipts.</p>
                            </div>
                        </div>

                        <div class="mt-6">
                            <x-label>Invoice Footer Note</x-label>
                            <x-textarea name="invoice_footer_note" placeholder="e.g. bank account details, payment instructions, thank-you message...">{{ old('invoice_footer_note', $landlord->invoice_footer_note) }}</x-textarea>
                            <p class="mt-1 text-xs text-gray-500">Printed at the bottom of every invoice PDF.</p>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <x-button>Save Changes</x-button>
                    </div>
                </form>
            </div>

            {{-- Tenant mobile money payments --}}
            <div class="mt-6 bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-base font-semibold text-gray-900">Tenant mobile money payments</h3>
                @if($landlord->collect_payments)
                    <p class="mt-1 text-sm text-gray-500">Your tenants can pay invoices and rent in advance by mobile money from the tenant portal ({{ route('tenant.login') }}). Primora collects the money and settles it to you.</p>
                    <form action="{{ route('company-profile.collections') }}" method="POST" class="mt-4 space-y-4">
                        @csrf
                        @method('PUT')
                        <p class="text-sm font-medium text-gray-700">Who pays the mobile money collection fee?</p>
                        <label class="flex items-start gap-3 rounded-xl border border-gray-200 p-4 cursor-pointer has-[:checked]:border-orange-500 has-[:checked]:bg-orange-50/40">
                            <input type="radio" name="pass_collection_fee" value="0" @checked(! $landlord->pass_collection_fee) class="mt-0.5 text-orange-600 focus:ring-orange-500">
                            <span><span class="block text-sm font-medium text-gray-900">I pay it</span><span class="block text-xs text-gray-500">Tenants pay exactly what they owe. The fee is deducted from what Primora settles to you.</span></span>
                        </label>
                        <label class="flex items-start gap-3 rounded-xl border border-gray-200 p-4 cursor-pointer has-[:checked]:border-orange-500 has-[:checked]:bg-orange-50/40">
                            <input type="radio" name="pass_collection_fee" value="1" @checked($landlord->pass_collection_fee) class="mt-0.5 text-orange-600 focus:ring-orange-500">
                            <span><span class="block text-sm font-medium text-gray-900">My tenants pay it</span><span class="block text-xs text-gray-500">The fee is added to what tenants pay, and you receive the full amount. Tenants see the fee before they pay.</span></span>
                        </label>
                        <div class="flex justify-end"><x-button>Save</x-button></div>
                    </form>
                @else
                    <p class="mt-1 text-sm text-gray-500">Want your tenants to pay rent by mobile money from their phones? Contact Primora to switch it on for your account.</p>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
