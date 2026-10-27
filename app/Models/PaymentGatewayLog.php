<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One exchange with a payment gateway (see the migration for details).
 */
class PaymentGatewayLog extends Model
{
    public const OUTBOUND = 'outbound';
    public const INBOUND = 'inbound';

    public const UPDATED_AT = null;

    protected $fillable = [
        'payment_transaction_id', 'liquidation_id', 'gateway', 'action', 'direction', 'url', 'request_body',
        'response_status', 'response_body', 'error', 'duration_ms', 'note',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }

    public function liquidation(): BelongsTo
    {
        return $this->belongsTo(Liquidation::class);
    }

    public function isSuccessfulHttp(): bool
    {
        return $this->response_status !== null && $this->response_status >= 200 && $this->response_status < 300 && !$this->error;
    }

    public function prettyRequest(): ?string
    {
        return self::pretty($this->request_body);
    }

    public function prettyResponse(): ?string
    {
        return self::pretty($this->response_body);
    }

    /** Indents XML / JSON / form bodies for reading; anything else is returned as-is. */
    public static function pretty(?string $body): ?string
    {
        if ($body === null || trim($body) === '') {
            return $body;
        }

        $trimmed = trim($body);

        if (str_starts_with($trimmed, '<')) {
            $dom = new \DOMDocument('1.0', 'UTF-8');
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput = true;
            if (@$dom->loadXML($trimmed)) {
                return trim($dom->saveXML());
            }

            return $body;
        }

        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            $decoded = json_decode($trimmed, true);

            return $decoded === null ? $body : json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // Form-encoded callback (key=value&…): one field per line.
        if (str_contains($trimmed, '=') && !str_contains($trimmed, "\n")) {
            parse_str($trimmed, $fields);
            if ($fields) {
                return collect($fields)->map(fn ($v, $k) => $k.' = '.(is_array($v) ? json_encode($v) : $v))->implode("\n");
            }
        }

        return $body;
    }
}
