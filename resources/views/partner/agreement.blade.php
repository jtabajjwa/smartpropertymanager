<x-partner-layout title="Partner agreement" :guest="! auth('partner')->check()">
    <div class="{{ auth('partner')->check() ? 'max-w-3xl' : '' }} bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
        @include('partner._agreement-text')

        @auth('partner')
            @if($partner->agreement_accepted_at)
                <p class="mt-6 text-sm text-green-700">Accepted on {{ $partner->agreement_accepted_at->format('d M Y') }}.</p>
            @else
                <form method="POST" action="{{ route('partner.agreement.accept') }}" class="mt-6 space-y-3">
                    @csrf
                    <label class="flex items-start gap-3 text-sm text-gray-700">
                        <input type="checkbox" name="agreement" value="1" class="mt-0.5 rounded border-gray-300 text-orange-600">
                        <span>I accept this agreement for {{ $partner->name }}.</span>
                    </label>
                    @error('agreement')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                    <x-button type="submit">Accept</x-button>
                </form>
            @endif
        @endauth
    </div>
</x-partner-layout>
