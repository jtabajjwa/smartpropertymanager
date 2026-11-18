{{-- Plain-language partner agreement. Have it reviewed before going live; bump config('partners.agreement_version') when it changes. --}}
<div class="prose prose-sm max-w-none text-gray-700 space-y-3">
    <p><strong>{{ config('app.name') }} partner agreement</strong> (version {{ config('partners.agreement_version', '1') }})</p>
    <ol class="list-decimal pl-5 space-y-2">
        <li><strong>What you do.</strong> You introduce landlords and property managers to {{ config('app.name') }}, by signing them up in this portal or by sharing your partner code or agent number. You are not an employee of {{ config('app.name') }} and may not make promises on its behalf beyond what is published.</li>
        <li><strong>Consent.</strong> You only sign a landlord up with their permission. They receive an email to set their own password and must accept {{ config('app.name') }}'s Terms of Service themselves.</li>
        <li><strong>Which landlords are yours.</strong> A landlord is linked to one partner: the one who signed them up, or whose code they used at sign-up or before their first subscription payment. The link is fixed once they first pay. {{ config('app.name') }} may correct a link that was made in error or by misuse, and its records decide.</li>
        <li><strong>Revenue share.</strong> You earn a share of the subscription payments your landlords make, on the terms {{ config('app.name') }} sets for your account and shows in this portal. Terms may change for future payments, with notice. Earnings on payments that are refunded or reversed are taken back.</li>
        <li><strong>Offers and codes.</strong> Discounts given with your codes are set by {{ config('app.name') }}. Where an offer says you bear part of a discount, that part comes off your earnings.</li>
        <li><strong>Payouts and tax.</strong> Earnings become available after a holding period and are paid to an account you register, subject to a minimum amount. Tax is withheld where the law requires it.</li>
        <li><strong>Landlords' information.</strong> You may see the contact details of your landlords so you can help them with {{ config('app.name') }}. Use them only for that, keep them confidential, and follow the Data Protection and Privacy Act, 2019.</li>
        <li><strong>Fair dealing.</strong> No false or misleading claims, no signing up yourself or people you control to earn, no spam. Breaking these rules can lead to suspension and loss of unpaid earnings.</li>
        <li><strong>Ending.</strong> Either side may end this agreement by notice. Earnings already available are still paid, less anything owed to {{ config('app.name') }}.</li>
    </ol>
</div>
