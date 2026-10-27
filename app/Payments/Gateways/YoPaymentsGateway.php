<?php

namespace App\Payments\Gateways;

use App\Models\PaymentGatewayLog;
use App\Models\PaymentTransaction;
use App\Models\Liquidation;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Contracts\PayoutGateway;
use App\Payments\GatewayLogger;
use App\Payments\GatewayNotification;
use App\Payments\GatewayResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;

/**
 * Yo! Payments (Yo! Uganda) — API v3.48.
 *
 *  - acdepositfunds (pull, NonBlocking=TRUE): the payer gets an approval
 *    prompt on their phone; Yo! answers straight away with PENDING.
 *  - IPN / failure notification: Yo! posts to our callback URLs; signatures
 *    are SHA1-with-RSA, checked against Yo!'s public certificate.
 *  - actransactioncheckstatus: authoritative status, looked up by our own
 *    reference (sent as ExternalReference → PrivateTransactionReference).
 *  - acwithdrawfunds (landlord payouts): signed with Primora's private key
 *    (spec §4.1: SHA1-with-RSA over username, amount, account, narrative,
 *    external reference and a single-use nonce). Never re-sent automatically.
 */
class YoPaymentsGateway implements PaymentGateway, PayoutGateway
{
    public function __construct(protected array $config)
    {
    }

    public function name(): string
    {
        return 'yo';
    }

    public function isConfigured(): bool
    {
        return filled($this->config['api_username'] ?? null) && filled($this->config['api_password'] ?? null);
    }

    public function requestPayment(PaymentTransaction $transaction): GatewayResult
    {
        $fields = [
            'Method' => 'acdepositfunds',
            'NonBlocking' => 'TRUE',
            'Amount' => $this->formatAmount($transaction->amount),
            'Account' => $transaction->msisdn,
            'Narrative' => $transaction->narrative,
            'ExternalReference' => $transaction->reference,
            'ProviderReferenceText' => $this->config['provider_reference_text'] ?? null,
            'InstantNotificationUrl' => route('payments.callback', ['gateway' => $this->name(), 'type' => 'ipn']),
            'FailureNotificationUrl' => route('payments.callback', ['gateway' => $this->name(), 'type' => 'failure']),
        ];

        try {
            $response = $this->send($fields, $transaction);
        } catch (ConnectionException $e) {
            if ($this->neverSent($e)) {
                // No connection was made, so Yo! has nothing: fail now, clearly.
                Log::error('Yo! Payments unreachable; payment request not sent', ['reference' => $transaction->reference, 'error' => $e->getMessage()]);

                return GatewayResult::failed('We could not connect to the mobile money service, so no payment request was sent. Please try again shortly.');
            }

            // Sent, but no clear answer (e.g. timed out): Yo! may have it.
            // Keep it open; the status check (by our ExternalReference) will find out.
            return GatewayResult::pending('No reply from Yo! Payments yet: '.$e->getMessage());
        }

        return $this->interpret($response);
    }

    /**
     * True for errors that happen before the request leaves this server
     * (DNS, refused connection, TLS/certificate problems). A timeout or an
     * unreadable reply is NOT one of these: Yo! may have received it.
     */
    protected function neverSent(ConnectionException $e): bool
    {
        // libcurl: 3 bad URL, 5/6 can't resolve, 7 can't connect, 35 TLS handshake,
        // 51/53/58/59/60/77/83 certificate & TLS setup problems.
        return (bool) preg_match('/cURL error (3|5|6|7|35|51|53|58|59|60|77|83):/', $e->getMessage());
    }

    public function checkStatus(PaymentTransaction $transaction): GatewayResult
    {
        $fields = ['Method' => 'actransactioncheckstatus'];

        // Yo!'s reference takes precedence; fall back to ours (ExternalReference).
        if ($transaction->gateway_reference) {
            $fields['TransactionReference'] = $transaction->gateway_reference;
        } else {
            $fields['PrivateTransactionReference'] = $transaction->reference;
        }
        $fields['DepositTransactionType'] = 'PULL';

        try {
            $response = $this->send($fields, $transaction);
        } catch (ConnectionException $e) {
            return GatewayResult::pending('Could not reach Yo! Payments: '.$e->getMessage());
        }

        return $this->interpret($response);
    }

    public function parseNotification(Request $request, string $type): GatewayNotification
    {
        if ($type === 'ipn') {
            $p = fn (string $key) => (string) $request->input($key, '');

            $signed = $p('date_time').$p('amount').$p('narrative').$p('network_ref').$p('external_ref').$p('msisdn');

            return new GatewayNotification(
                kind: 'success',
                reference: $p('external_ref') ?: null,
                gatewayReference: null,
                verified: $this->verifySignature($signed, $p('signature')),
                networkReference: $this->networkReference($p('network_ref')),
                payload: $request->only(['date_time', 'amount', 'narrative', 'network_ref', 'external_ref', 'msisdn', 'payer_names']),
            );
        }

        if ($type === 'failure') {
            $ref = (string) $request->input('failed_transaction_reference', '');
            $signed = $ref.$request->input('transaction_init_date', '');

            // Yo! doesn't document which reference this is, so try it as both.
            return new GatewayNotification(
                kind: 'failure',
                reference: $ref ?: null,
                gatewayReference: $ref ?: null,
                verified: $this->verifySignature($signed, (string) $request->input('verification', '')),
                payload: $request->only(['failed_transaction_reference', 'transaction_init_date', 'transaction_date']),
            );
        }

        throw new \InvalidArgumentException("Unknown Yo! notification type [{$type}].");
    }

    public function notificationResponse(GatewayNotification $notification): string
    {
        // Yo! only needs HTTP 200. (A "narrative=..." body would trigger an SMS to the payer.)
        return 'OK';
    }


    // --- Payouts ------------------------------------------------------------
    //  mobile money: acwithdrawfunds (§4) / actransactioncheckstatus
    //  bank:         acwithdrawfundstobank (§5) / accheckbanksettlementstatus (§8),
    //                to accounts registered on the Yo! account (§16)

    public function canSendPayouts(): bool
    {
        // Production accounts have public key authentication on; sandbox may not.
        return $this->isConfigured() && ($this->privateKey() !== null || $this->mode() !== 'production');
    }

    public function sendPayout(Liquidation $liquidation): GatewayResult
    {
        return $liquidation->isMobileMoney() ? $this->sendMobileMoney($liquidation) : $this->sendToBank($liquidation);
    }

    public function checkPayout(Liquidation $liquidation): GatewayResult
    {
        return $liquidation->isMobileMoney() ? $this->checkMobileMoney($liquidation) : $this->checkBank($liquidation);
    }

    protected function sendMobileMoney(Liquidation $liquidation): GatewayResult
    {
        $nonce = $this->nonce();
        $fields = [
            'Method' => 'acwithdrawfunds',
            'NonBlocking' => 'TRUE',
            'Amount' => $this->formatAmount($liquidation->net_amount),
            'Account' => (string) ($liquidation->destination['msisdn'] ?? ''),
            'Narrative' => mb_substr("Primora payout {$liquidation->reference} to {$liquidation->landlord?->name}", 0, 250),
            'ExternalReference' => $liquidation->external_reference,
            'ProviderReferenceText' => $this->config['provider_reference_text'] ?? null,
        ];

        // §4.1: APIUsername, Amount, Account, Narrative, ExternalReference, nonce.
        $fields = $this->sign($fields, [$fields['Amount'], $fields['Account'], $fields['Narrative'], $fields['ExternalReference']], $nonce);
        if ($fields === null) {
            return GatewayResult::failed('Could not sign the withdrawal request (check the Yo! private key). Nothing was sent.');
        }

        return $this->sendMoneyOut($fields, $liquidation, fn (SimpleXMLElement $xml) => $this->interpret($xml));
    }

    protected function sendToBank(Liquidation $liquidation): GatewayResult
    {
        $d = $liquidation->destination ?? [];
        if (empty($d['yo_bank_identifier'])) {
            return GatewayResult::failed('This bank account is not registered with Yo! Payments. Nothing was sent.');
        }

        $nonce = $this->nonce();
        $fields = [
            'Method' => 'acwithdrawfundstobank',
            'Amount' => $this->formatAmount($liquidation->net_amount),
            'CurrencyCode' => 'UGX',
            'BankAccountName' => (string) ($d['account_name'] ?? ''),
            'BankAccountNumber' => (string) ($d['account_number'] ?? ''),
            'BankAccountIdentifier' => (string) $d['yo_bank_identifier'],
            'TransferTransactionType' => $liquidation->transfer_type,
            'PrivateTransactionReference' => $liquidation->external_reference,
        ];

        // §5.1: APIUsername, Amount, CurrencyCode, BankAccountName, BankAccountNumber,
        // BankAccountIdentifier, TransferTransactionType, PrivateTransactionReference, nonce.
        $fields = $this->sign($fields, [
            $fields['Amount'], $fields['CurrencyCode'], $fields['BankAccountName'], $fields['BankAccountNumber'],
            $fields['BankAccountIdentifier'], (string) $fields['TransferTransactionType'], $fields['PrivateTransactionReference'],
        ], $nonce);
        if ($fields === null) {
            return GatewayResult::failed('Could not sign the bank withdrawal request (check the Yo! private key). Nothing was sent.');
        }

        return $this->sendMoneyOut($fields, $liquidation, fn (SimpleXMLElement $xml) => $this->interpretBank($xml));
    }

    protected function checkMobileMoney(Liquidation $liquidation): GatewayResult
    {
        $fields = ['Method' => 'actransactioncheckstatus'];
        if ($liquidation->gateway_reference) {
            $fields['TransactionReference'] = $liquidation->gateway_reference;
        } else {
            $fields['PrivateTransactionReference'] = $liquidation->external_reference;
        }

        try {
            $response = $this->send($fields, null, $liquidation);
        } catch (ConnectionException $e) {
            return GatewayResult::pending('Could not reach Yo! Payments: '.$e->getMessage());
        }

        return $this->interpret($response);
    }

    protected function checkBank(Liquidation $liquidation): GatewayResult
    {
        $fields = ['Method' => 'accheckbanksettlementstatus'];
        if ($liquidation->gateway_reference) {
            $fields['SettlementTransactionIdentifier'] = $liquidation->gateway_reference;
        } else {
            $fields['PrivateTransactionReference'] = $liquidation->external_reference;
        }

        try {
            $response = $this->send($fields, null, $liquidation);
        } catch (ConnectionException $e) {
            return GatewayResult::pending('Could not reach Yo! Payments: '.$e->getMessage());
        }

        return $this->interpretBank($response);
    }

    /** §16: register a verified bank account on Primora's Yo! account; returns its API Bank Identifier. */
    public function registerBankAccount(\App\Models\PayoutAccount $account, array $bank): string
    {
        $info = [
            'Name' => $bank['name'],
            'StreetAddressLine1' => $bank['address1'],
            'StreetAddressLine2' => $bank['address2'],
            'City' => $bank['city'],
            'State' => $bank['state'] ?? '',
            'Country' => $bank['country'],
            'SwiftCode' => $bank['swift'] ?? '',
            'SortCode' => '',
            'ABANumber' => '',
            'RoutingNumber' => '',
        ];
        $fields = [
            'Method' => 'accreateverifiedbankaccount',
            'AccountName' => $account->account_name,
            'AccountNumber' => $account->account_number,
            'BankInformation' => $info,
        ];

        // §16.1: APIUsername, AccountName, AccountNumber, the BankInformation fields in order, nonce.
        $fields = $this->sign($fields, array_merge([$account->account_name, $account->account_number], array_values($info)), $this->nonce());
        if ($fields === null) {
            throw new \RuntimeException('Could not sign the request (check the Yo! private key).');
        }

        try {
            $response = $this->send($fields, null, null, failoverOnlyIfUnsent: true);
        } catch (ConnectionException $e) {
            throw new \RuntimeException($this->neverSent($e)
                ? 'Could not connect to Yo! Payments. Nothing was registered; try again.'
                : 'No clear reply from Yo! Payments. It may have registered the account: check the Yo! web portal (Account Information → Bank Account Information) and enter its API Bank Identifier here instead of registering again.');
        }

        $r = $response->Response;
        $identifier = trim((string) $r->ApiBankIdentifier);
        if (strtoupper((string) $r->Status) !== 'OK' || $identifier === '') {
            throw new \RuntimeException('Yo! Payments did not register the account: '.(trim((string) $r->ErrorMessage) ?: trim((string) $r->StatusMessage) ?: 'no reason given').'.');
        }

        return $identifier;
    }

    /** Send a money-out request: only fail over if it never left; never resend once Yo! may have it. */
    protected function sendMoneyOut(array $fields, Liquidation $liquidation, callable $interpret): GatewayResult
    {
        try {
            $response = $this->send($fields, null, $liquidation, failoverOnlyIfUnsent: true);
        } catch (ConnectionException $e) {
            if ($this->neverSent($e)) {
                return GatewayResult::failed('Could not connect to Yo! Payments, so no money was sent. You can try again.');
            }

            return new GatewayResult(\App\Models\PaymentTransaction::INDETERMINATE, message: 'No clear reply from Yo! Payments; checking status. Do not resend: '.$e->getMessage());
        }

        return $interpret($response);
    }

    /**
     * Bank withdrawal / bank settlement status (§5.2, §8.2): PENDING, CAPTURED
     * (taken from the pool for transfer to the bank: done), CANCELLED.
     */
    protected function interpretBank(SimpleXMLElement $xml): GatewayResult
    {
        $r = $xml->Response;
        $status = strtoupper((string) $r->Status);
        $code = (int) $r->StatusCode;
        $txStatus = strtoupper((string) $r->TransactionStatus);
        $message = trim((string) $r->ErrorMessage) ?: trim((string) $r->StatusMessage) ?: null;
        $reference = trim((string) $r->SettlementTransactionIdentifier) ?: trim((string) $r->SettlementRequestIdentifier) ?: null;

        $raw = json_decode(json_encode($r), true) ?: [];
        unset($raw['APIUsername'], $raw['APIPassword']);

        if ($code === -30) {
            return new GatewayResult(\App\Models\PaymentTransaction::PENDING, message: $message ?? 'Transaction not found yet.', raw: $raw, notFound: true);
        }

        $mapped = match (true) {
            $txStatus === 'CAPTURED' => \App\Models\PaymentTransaction::SUCCEEDED,
            $txStatus === 'CANCELLED' => \App\Models\PaymentTransaction::FAILED,
            $txStatus === 'PENDING' => \App\Models\PaymentTransaction::PENDING,
            $status === 'OK' && $code === 0 => \App\Models\PaymentTransaction::PENDING, // accepted; settlement follows
            $status === 'ERROR' && $code < 0 => \App\Models\PaymentTransaction::FAILED,
            default => \App\Models\PaymentTransaction::INDETERMINATE,
        };

        return new GatewayResult($mapped, gatewayReference: $reference, message: $txStatus === 'CAPTURED' ? 'Captured by Yo! for transfer to the bank.' : $message, amount: (string) $r->Amount !== '' ? (float) $r->Amount : null, raw: $raw);
    }

    /** Add the public key authentication nonce and signature (SHA1-with-RSA over the username, the given values, then the nonce). */
    protected function sign(array $fields, array $values, string $nonce): ?array
    {
        $key = $this->privateKey();
        if (!$key) {
            return $this->mode() === 'production' ? null : $fields; // sandbox may not need signing
        }

        $data = $this->config['api_username'].implode('', array_map(fn ($v) => mb_substr((string) $v, 0, 255), $values)).mb_substr($nonce, 0, 255);
        if (!openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA1)) {
            return null;
        }

        return $fields + ['PublicKeyAuthenticationNonce' => $nonce, 'PublicKeyAuthenticationSignatureBase64' => base64_encode($signature)];
    }

    /** Single-use, [a-zA-Z0-9-] as Yo! requires. */
    protected function nonce(): string
    {
        return now()->format('YmdHisv').'-'.bin2hex(random_bytes(12));
    }

    /** Primora's private key for signing withdrawals (YO_PRIVATE_KEY_PATH), if configured. */
    protected function privateKey(): \OpenSSLAsymmetricKey|null
    {
        $path = $this->config['private_key'] ?? null;
        if (!$path || !is_file($path)) {
            return null;
        }

        return openssl_pkey_get_private((string) file_get_contents($path), (string) ($this->config['private_key_passphrase'] ?? '')) ?: null;
    }

    // ------------------------------------------------------------------

    /**
     * Turn a Yo! XML response into a GatewayResult (spec §3.3–3.5, §20).
     */
    protected function interpret(SimpleXMLElement $xml): GatewayResult
    {
        $r = $xml->Response;
        $status = strtoupper((string) $r->Status);
        $code = (int) $r->StatusCode;
        $txStatus = strtoupper((string) $r->TransactionStatus);
        $message = trim((string) $r->ErrorMessage) ?: trim((string) $r->StatusMessage) ?: null;

        $raw = json_decode(json_encode($r), true) ?: [];
        unset($raw['APIUsername'], $raw['APIPassword']);

        $common = [
            'message' => $message,
            'gatewayReference' => trim((string) $r->TransactionReference) ?: null,
            'networkReference' => $this->networkReference((string) $r->MNOTransactionReferenceId),
            'receiptNumber' => trim((string) $r->IssuedReceiptNumber) ?: null,
            'amount' => (string) $r->Amount !== '' ? (float) $r->Amount : null,
            'raw' => $raw,
        ];

        // §20.4 −30: transaction not found.
        if ($code === -30) {
            return new GatewayResult(PaymentTransaction::PENDING, message: $message ?? 'Transaction not found yet.', raw: $raw, notFound: true);
        }

        $mapped = match (true) {
            $txStatus === 'SUCCEEDED' => PaymentTransaction::SUCCEEDED,
            $txStatus === 'PENDING' => PaymentTransaction::PENDING,
            $txStatus === 'FAILED' => PaymentTransaction::FAILED,
            $txStatus === 'INDETERMINATE' => PaymentTransaction::INDETERMINATE,
            // Status codes 4 and 6: Yo! says treat as succeeded (§20.4).
            in_array($code, [4, 6], true) => PaymentTransaction::SUCCEEDED,
            // Errors with no transaction status: request rejected outright (code < 0).
            $status === 'ERROR' && $code < 0 => PaymentTransaction::FAILED,
            $status === 'OK' && $code === 0 => PaymentTransaction::SUCCEEDED,
            $status === 'OK' && $code === 1 => PaymentTransaction::PENDING,
            default => PaymentTransaction::INDETERMINATE,
        };

        return new GatewayResult($mapped, ...$common);
    }

    /**
     * POST an XML request (spec §3.2). Production has two endpoints; the
     * second is tried if the first can't be reached. With $failoverOnlyIfUnsent
     * (money going out), the second is tried only when the first definitely
     * never received the request; otherwise we stop, so nothing is sent twice.
     */
    protected function send(array $fields, ?PaymentTransaction $transaction = null, ?Liquidation $liquidation = null, bool $failoverOnlyIfUnsent = false): SimpleXMLElement
    {
        $xml = $this->buildXml($fields);
        $urls = $this->config['urls'][$this->mode()] ?? [];
        $action = $fields['Method'] ?? 'unknown';
        $last = null;

        foreach ($urls as $url) {
            $started = microtime(true);

            try {
                $response = Http::withHeaders([
                    'Content-Type' => 'text/xml',
                    'Content-transfer-encoding' => 'text',
                ])
                    ->timeout($this->config['timeout'] ?? 45)
                    ->withBody($xml, 'text/xml')
                    ->post($url);
            } catch (ConnectionException $e) {
                $this->logExchange($transaction, $action, $url, $xml, $started, error: $e->getMessage(), liquidation: $liquidation);
                $last = $e;

                if ($failoverOnlyIfUnsent && !$this->neverSent($e)) {
                    throw $e;
                }

                continue;
            }

            $this->logExchange($transaction, $action, $url, $xml, $started, $response->status(), $response->body(), liquidation: $liquidation);

            $parsed = @simplexml_load_string($response->body());
            if ($parsed === false || !isset($parsed->Response)) {
                Log::warning('Yo! Payments: unreadable response', ['url' => $url, 'status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);
                $last = new ConnectionException('Unreadable response from Yo! Payments (HTTP '.$response->status().').');

                if ($failoverOnlyIfUnsent) {
                    throw $last; // it reached Yo!: don't send again
                }

                continue;
            }

            return $parsed;
        }

        throw $last ?? new ConnectionException('No Yo! Payments URL configured for mode "'.$this->mode().'".');
    }

    /** One request/response pair in payment_gateway_logs, with credentials masked. */
    protected function logExchange(?PaymentTransaction $transaction, string $action, string $url, string $xml, float $started, ?int $status = null, ?string $body = null, ?string $error = null, ?Liquidation $liquidation = null): void
    {
        app(GatewayLogger::class)->record(
            transaction: $transaction,
            gateway: $this->name(),
            action: $action,
            direction: PaymentGatewayLog::OUTBOUND,
            url: $url,
            requestBody: $this->maskCredentials($xml),
            responseStatus: $status,
            responseBody: $body,
            error: $error,
            durationMs: (int) round((microtime(true) - $started) * 1000),
            liquidation: $liquidation,
        );
    }

    /** Never store the API password; keep just enough of the username to tell accounts apart. */
    protected function maskCredentials(string $xml): string
    {
        $xml = preg_replace('#<APIPassword>.*?</APIPassword>#s', '<APIPassword>********</APIPassword>', $xml);
        $xml = preg_replace('#<PublicKeyAuthenticationSignatureBase64>(.{12}).*?</PublicKeyAuthenticationSignatureBase64>#s', '<PublicKeyAuthenticationSignatureBase64>$1…</PublicKeyAuthenticationSignatureBase64>', $xml);

        return preg_replace_callback('#<APIUsername>(.*?)</APIUsername>#s', function ($m) {
            $user = html_entity_decode($m[1], ENT_XML1);

            return '<APIUsername>'.htmlspecialchars(mb_substr($user, 0, 3).str_repeat('*', max(mb_strlen($user) - 3, 0)), ENT_XML1).'</APIUsername>';
        }, $xml);
    }

    protected function buildXml(array $fields): string
    {
        $fields = ['APIUsername' => $this->config['api_username'], 'APIPassword' => $this->config['api_password']] + $fields;

        return '<?xml version="1.0" encoding="UTF-8"?><AutoCreate><Request>'.$this->xmlElements($fields).'</Request></AutoCreate>';
    }

    /** Elements for a request; an array value becomes a nested element (e.g. BankInformation). */
    protected function xmlElements(array $fields): string
    {
        $body = '';
        foreach ($fields as $name => $value) {
            if (is_array($value)) {
                $body .= "<{$name}>".$this->xmlElements($value)."</{$name}>";

                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $body .= "<{$name}>".htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8')."</{$name}>";
        }

        return $body;
    }

    /** SHA1-with-RSA signature check against Yo!'s public certificate (spec §6.3.4 / §6.4.3). */
    protected function verifySignature(string $data, string $signatureBase64): bool
    {
        $certPath = $this->config['certificates'][$this->mode()] ?? null;
        $signature = base64_decode($signatureBase64, true);

        if (!$certPath || !is_file($certPath) || $signature === false || $signature === '') {
            return false;
        }

        $key = openssl_pkey_get_public((string) file_get_contents($certPath));

        return $key !== false && openssl_verify($data, $signature, $key, OPENSSL_ALGO_SHA1) === 1;
    }

    /**
     * The mobile network's own reference (MTN / Airtel), the short one payers
     * see in their SMS. Yo! may append extra text after ",,," (spec §3.3).
     */
    protected function networkReference(string $value): ?string
    {
        return trim(explode(',,,', $value, 2)[0]) ?: null;
    }

    protected function mode(): string
    {
        return ($this->config['mode'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox';
    }

    /** Yo! takes plain numbers; mobile money is whole shillings. */
    protected function formatAmount($amount): string
    {
        $amount = (float) $amount;

        return fmod($amount, 1.0) === 0.0 ? (string) (int) $amount : number_format($amount, 2, '.', '');
    }
}
