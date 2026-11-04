<?php

namespace App\Ussd;

use App\Payments\Msisdn;
use App\Payments\RsaSha1;
use App\Services\TenantPortal\RentPayments;
use App\Services\TenantPortal\TenantLogin;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Answers Yo!'s Custom USSD call-outs for the Primora menu (*217*XXX#), as
 * set out in "PrimoraPropTech_USSD_Process_V.1.0" and Yo!'s "Third Party URL
 * Call-Out API" v2.7:
 *
 *   unit()    step 2.1.2      tenant typed a unit ID
 *   units()   steps 2.2.1.2 / 2.2.2.4   tenancies for the caller's own phone, or another tenant's
 *   confirm() steps 2.2.1.7 / 2.2.2.9   the unit picked from that list, and the amount
 *
 * Every request is signed by Yo! (SHA1 of datetime + anumbermsisdn, RSA,
 * base64). Some menu steps overwrite anumbermsisdn with the menu choice and
 * sign datetime + msisdn instead; the caller is whichever phone the
 * signature covers. Answering "validated": false ends the USSD session, showing the
 * message. Values under ussd_processor_params are what Yo! staff put into the
 * menu texts (tenant_name, estate_name, ...) and payment_external_reference
 * is sent with the mobile money request, so the payment comes back to us.
 */
class YoUssdCallout
{
    /** Tenancies listed on one screen (USSD screens hold ~160 characters). */
    public const MAX_LISTED = 4;

    public function __construct(protected UssdPayments $ussd)
    {
    }

    /** Why the last verify() failed, for the log. */
    public array $failure = [];

    /** The caller's phone as covered by the signature, once verify() has passed. */
    protected ?string $signedPhone = null;

    /** Signature check (Yo! spec, "Request Signature Verification"), with the key for the mode: sandbox on staging, production live. */
    public function verify(array $payload): bool
    {
        // A "+" in the signature arrives as a space if it was form-posted without URL-encoding.
        $raw = str_replace(' ', '+', preg_replace('/[\r\n\t]/', '', (string) ($payload['signature'] ?? '')));
        $signature = base64_decode($raw, true);
        $mode = config('services.yo_ussd.mode', 'sandbox');
        $this->failure = [];
        $this->signedPhone = null;

        if (!$signature || !isset($payload['datetime'], $payload['anumbermsisdn'])) {
            $this->failure = ['reason' => 'missing or unreadable fields', 'signature_chars' => strlen($raw), 'signature_decoded' => $signature !== false,
                'has_datetime' => isset($payload['datetime']), 'has_anumbermsisdn' => isset($payload['anumbermsisdn'])];

            return false;
        }

        // The spec signs anumbermsisdn; failing that, msisdn (still Yo!'s signature, with this mode's key).
        foreach (['anumbermsisdn', 'msisdn'] as $field) {
            if (isset($payload[$field]) && is_scalar($payload[$field])
                && $this->verifiesWith($mode, sha1($payload['datetime'].$payload[$field]), $signature)) {
                $this->signedPhone = trim((string) $payload[$field]);

                return true;
            }
        }

        $data = sha1($payload['datetime'].$payload['anumbermsisdn']);

        // Diagnosis only (never accepted): was it signed with the other environment's key, and what was signed?
        $other = $mode === 'production' ? 'sandbox' : 'production';
        $this->failure = [
            'reason' => $this->keyFor($mode) ? 'signature mismatch' : 'no key file',
            'signature_bytes' => strlen($signature),
            'signed_with_'.$other.'_key' => $this->verifiesWith($other, $data, $signature),
            'datetime' => (string) $payload['datetime'],
        ] + $this->whatWasSigned($mode, $signature, $payload);

        return false;
    }

    /**
     * Recovers the digest inside the signature (RSA "decrypt" with the public
     * key) and tries the likely ways of building the signed data, so the log
     * says what Yo! actually signs.
     */
    protected function whatWasSigned(string $mode, string $signature, array $payload): array
    {
        $key = $this->keyFor($mode);
        if (!$key || !openssl_public_decrypt($signature, $block, $key, OPENSSL_PKCS1_PADDING)) {
            return ['signed_digest' => 'not recoverable with this key'];
        }

        $prefixes = ['sha1' => '3021300906052b0e03021a05000414', 'sha256' => '3031300d060960864801650304020105000420', 'md5' => '3020300c06082a864886f70d020505000410'];
        $hex = bin2hex($block);
        $algo = null;
        foreach ($prefixes as $name => $prefix) {
            if (str_starts_with($hex, $prefix)) {
                [$algo, $digest] = [$name, substr($hex, strlen($prefix))];
            }
        }
        if (!$algo) {
            return ['signed_digest' => 'raw '.$hex];
        }

        $v = fn ($k) => isset($payload[$k]) && is_scalar($payload[$k]) ? (string) $payload[$k] : '';
        $strings = [
            'datetime+anumbermsisdn' => $v('datetime').$v('anumbermsisdn'),
            'datetime+msisdn' => $v('datetime').$v('msisdn'),
            'anumbermsisdn+datetime' => $v('anumbermsisdn').$v('datetime'),
            'datetime' => $v('datetime'),
            'anumbermsisdn' => $v('anumbermsisdn'),
            'datetime+code+anumbermsisdn' => $v('datetime').$v('code').$v('anumbermsisdn'),
        ];
        foreach ($strings as $label => $s) {
            foreach (['sha1 hex of ' => sha1($s), 'sha1 bytes of ' => sha1($s, true), '' => $s, 'sha256 hex of ' => hash('sha256', $s)] as $how => $signed) {
                if (hash($algo, $signed) === $digest) {
                    return ['signed_data' => $algo.' signature over '.$how.$label];
                }
            }
        }

        return ['signed_digest' => $algo.' '.$digest, 'expected_digest' => $algo.' '.hash($algo, sha1($strings['datetime+anumbermsisdn']))];
    }

    protected function keyFor(string $mode): ?\OpenSSLAsymmetricKey
    {
        $path = config("services.yo_ussd.public_keys.{$mode}");

        return $path && is_file($path) ? (openssl_pkey_get_public((string) file_get_contents($path)) ?: null) : null;
    }

    protected function verifiesWith(string $mode, string $data, string $signature): bool
    {
        $key = $this->keyFor($mode);

        return $key !== null && RsaSha1::verify($data, $signature, $key);
    }

    /** Step 2.1.2: a unit ID was typed. */
    public function unit(array $p): array
    {
        $input = (string) ($p['property_unit_number'] ?? '');
        $tenancy = $this->ussd->tenancyForUnitCode($input);

        if (!$tenancy) {
            return $this->fail("We couldn't find a rented unit with ID ".Tenancy::plain(Str::limit($input, 20, '')).'. Check the unit ID on your invoice and try again.');
        }
        if (!$tenancy->acceptsPayments) {
            return $this->fail("This unit's landlord doesn't take payments through Primora yet. Please pay them directly.");
        }

        $intent = $this->ussd->reserve($tenancy, $this->caller($p));

        return $this->ok(
            "Primora PropTech:\nEstate: {$tenancy->estateName()}\nTenant: {$tenancy->tenantName()}\nUnit No: {$this->unitLabel($tenancy)}\nBal: ".$this->money($tenancy->balance),
            $this->tenancyParams($tenancy) + [
                'balance' => (int) round($tenancy->balance),
                'balance_formatted' => $this->money($tenancy->balance),
                'payment_external_reference' => $intent->reference,
            ],
            withIpn: true,
        );
    }

    /** Steps 2.2.1.2 (the caller's own phone) and 2.2.2.4 (another tenant's phone). */
    public function units(array $p): array
    {
        $other = array_key_exists('other_tenant_phonenumber', $p);
        $msisdn = $other ? Msisdn::normalize((string) $p['other_tenant_phonenumber']) : $this->caller($p);

        if (!$msisdn) {
            return $this->fail('Enter a valid mobile number, e.g. 0772123456.');
        }

        $tenancies = $this->payable($msisdn);

        if ($tenancies->isEmpty()) {
            return $this->fail($other
                ? 'No tenancy is registered to '.TenantLogin::mask($msisdn).'. Check the number, or pay with the unit ID instead.'
                : 'No tenancy is registered to your phone number. Choose "Use Property Unit NO." and enter the unit ID on your invoice.');
        }

        return $this->ok(
            'Primora PropTech: '.$tenancies->count().' active property '.Str::plural('unit', $tenancies->count()).' found against '.($other ? 'that' : 'your').' phone number.',
            [
                'property_unit_list_text' => $this->listText($tenancies),
                'property_unit_count' => $tenancies->count(),
                'user_phonenumber' => $msisdn,
            ],
        );
    }

    /** Steps 2.2.1.7 / 2.2.2.9: the unit chosen from the list, and the amount. */
    public function confirm(array $p): array
    {
        $msisdn = Msisdn::normalize((string) ($p['user_phonenumber'] ?? ''));
        $tenancies = $msisdn ? $this->payable($msisdn) : collect();
        $choice = (int) preg_replace('/\D/', '', (string) ($p['selected_property_number'] ?? ''));
        $tenancy = $tenancies->get($choice - 1);

        if (!$tenancy) {
            return $this->fail('That is not one of the units listed. Please dial again and choose a number from the list.');
        }

        $amount = (int) preg_replace('/\D/', '', (string) ($p['amount'] ?? ''));
        if ($amount < RentPayments::MIN_AMOUNT) {
            return $this->fail('The smallest amount you can pay is '.$this->money(RentPayments::MIN_AMOUNT).'.');
        }
        if ($amount > RentPayments::MAX_AMOUNT) {
            return $this->fail('The most you can pay at once is '.$this->money(RentPayments::MAX_AMOUNT).'.');
        }

        $intent = $this->ussd->reserve($tenancy, $this->caller($p), $amount);

        return $this->ok(
            "Primora PropTech:\nProperty unit (".trim($tenancy->unitName().' '.$tenancy->unitCode()).') and amount ('.$this->money($amount).') are valid.',
            $this->tenancyParams($tenancy) + [
                'amount' => $amount,
                'amount_formatted' => $this->money($amount),
                'payment_external_reference' => $intent->reference,
            ],
            withIpn: true,
        );
    }

    public function fail(string $message): array
    {
        return ['validated' => false, 'message' => 'Primora: '.$message];
    }

    // ------------------------------------------------------------------

    /** @return Collection<int, Tenancy> only those whose landlord takes payments through Primora, numbered as listed */
    protected function payable(string $msisdn): Collection
    {
        return $this->ussd->tenanciesForPhone($msisdn)
            ->filter(fn (Tenancy $t) => $t->acceptsPayments)
            ->take(self::MAX_LISTED)
            ->values();
    }

    /** "1. Sunrise Apts A1 (Julian, Bal: UGX 450,000)", one per line. */
    protected function listText(Collection $tenancies): string
    {
        return "Primora PropTech:\n".$tenancies->values()->map(fn (Tenancy $t, int $i) => ($i + 1).'. '
            .Str::limit($t->estateName(), 18, '').' '.$t->unitName()
            .' ('.Str::before($t->tenantName(), ' ').', Bal: '.$this->money($t->balance).')')->join("\n");
    }

    protected function tenancyParams(Tenancy $t): array
    {
        return [
            'tenant_name' => $t->tenantName(),
            'estate_name' => $t->estateName(),
            'unit_number' => $this->unitLabel($t),
            'property_unit_number' => $t->unitCode(),
        ];
    }

    /** "A1 (1000124)": the landlord's name for the unit and its unit ID. */
    protected function unitLabel(Tenancy $t): string
    {
        return trim($t->unitName().($t->unitCode() ? " ({$t->unitCode()})" : ''));
    }

    protected function caller(array $p): string
    {
        $phone = $this->signedPhone ?? (string) ($p['anumbermsisdn'] ?? '');

        return Msisdn::normalize($phone) ?? preg_replace('/\D/', '', $phone);
    }

    protected function money(float|int $amount): string
    {
        return 'UGX '.number_format($amount);
    }

    protected function ok(string $message, array $params, bool $withIpn = false): array
    {
        return array_filter([
            'validated' => true,
            'message' => $message,
            'ussd_processor_params' => $params,
            // Yo!'s payment notifications for this payment (the same endpoints as the portal's payments).
            'success_ipn_url' => $withIpn ? route('payments.callback', ['gateway' => 'yo', 'type' => 'ipn']) : null,
            'failure_ipn_url' => $withIpn ? route('payments.callback', ['gateway' => 'yo', 'type' => 'failure']) : null,
        ], fn ($v) => $v !== null);
    }
}
