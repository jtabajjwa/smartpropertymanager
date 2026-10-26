<?php

namespace App\Payments\Gateways;

use App\Models\PaymentGatewayLog;
use App\Models\PaymentTransaction;
use App\Payments\Contracts\PaymentGateway;
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
 */
class YoPaymentsGateway implements PaymentGateway
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
     * second is tried if the first can't be reached.
     */
    protected function send(array $fields, ?PaymentTransaction $transaction = null): SimpleXMLElement
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
                $this->logExchange($transaction, $action, $url, $xml, $started, error: $e->getMessage());
                $last = $e;

                continue;
            }

            $this->logExchange($transaction, $action, $url, $xml, $started, $response->status(), $response->body());

            $parsed = @simplexml_load_string($response->body());
            if ($parsed === false || !isset($parsed->Response)) {
                Log::warning('Yo! Payments: unreadable response', ['url' => $url, 'status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);
                $last = new ConnectionException('Unreadable response from Yo! Payments (HTTP '.$response->status().').');

                continue;
            }

            return $parsed;
        }

        throw $last ?? new ConnectionException('No Yo! Payments URL configured for mode "'.$this->mode().'".');
    }

    /** One request/response pair in payment_gateway_logs, with credentials masked. */
    protected function logExchange(?PaymentTransaction $transaction, string $action, string $url, string $xml, float $started, ?int $status = null, ?string $body = null, ?string $error = null): void
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
        );
    }

    /** Never store the API password; keep just enough of the username to tell accounts apart. */
    protected function maskCredentials(string $xml): string
    {
        $xml = preg_replace('#<APIPassword>.*?</APIPassword>#s', '<APIPassword>********</APIPassword>', $xml);

        return preg_replace_callback('#<APIUsername>(.*?)</APIUsername>#s', function ($m) {
            $user = html_entity_decode($m[1], ENT_XML1);

            return '<APIUsername>'.htmlspecialchars(mb_substr($user, 0, 3).str_repeat('*', max(mb_strlen($user) - 3, 0)), ENT_XML1).'</APIUsername>';
        }, $xml);
    }

    protected function buildXml(array $fields): string
    {
        $fields = ['APIUsername' => $this->config['api_username'], 'APIPassword' => $this->config['api_password']] + $fields;

        $body = '';
        foreach ($fields as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $body .= "<{$name}>".htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8')."</{$name}>";
        }

        return '<?xml version="1.0" encoding="UTF-8"?><AutoCreate><Request>'.$body.'</Request></AutoCreate>';
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
