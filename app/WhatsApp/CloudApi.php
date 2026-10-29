<?php

namespace App\WhatsApp;

use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sends messages through Meta's WhatsApp Cloud API and logs each one.
 * Keeps within WhatsApp's limits (3 buttons, 10 list rows, short titles).
 * Without a token / phone number id (e.g. on a developer's machine) messages
 * are only logged, with status "logged".
 */
class CloudApi
{
    public const MAX_BUTTONS = 3;
    public const MAX_ROWS = 10;

    public function configured(): bool
    {
        return filled(config('services.whatsapp.token')) && filled(config('services.whatsapp.phone_number_id'));
    }

    public function text(string $to, string $body, ?int $tenantId = null): void
    {
        $this->send($to, 'text', ['text' => ['body' => Str::limit($body, 4000, '…'), 'preview_url' => false]], $body, $tenantId);
    }

    /** @param  array<string, string>  $buttons  id => title (at most 3) */
    public function buttons(string $to, string $body, array $buttons, ?int $tenantId = null): void
    {
        $reply = collect($buttons)->take(self::MAX_BUTTONS)
            ->map(fn ($title, $id) => ['type' => 'reply', 'reply' => ['id' => (string) $id, 'title' => Str::limit($title, 20, '')]])->values()->all();

        $this->send($to, 'interactive', ['interactive' => [
            'type' => 'button',
            'body' => ['text' => Str::limit($body, 1024, '…')],
            'action' => ['buttons' => $reply],
        ]], $body, $tenantId);
    }

    /**
     * A menu the tenant opens with $button.
     *
     * @param  array<string, array{0: string, 1?: string}>  $rows  id => [title, description]
     */
    public function list(string $to, string $body, string $button, array $rows, ?int $tenantId = null, ?string $header = null): void
    {
        $items = collect($rows)->take(self::MAX_ROWS)->map(fn ($row, $id) => array_filter([
            'id' => (string) $id,
            'title' => Str::limit($row[0], 24, ''),
            'description' => isset($row[1]) ? Str::limit($row[1], 72, '…') : null,
        ]))->values()->all();

        $this->send($to, 'interactive', ['interactive' => array_filter([
            'type' => 'list',
            'header' => $header ? ['type' => 'text', 'text' => Str::limit($header, 60, '')] : null,
            'body' => ['text' => Str::limit($body, 1024, '…')],
            'action' => ['button' => Str::limit($button, 20, ''), 'sections' => [['title' => Str::limit($button, 24, ''), 'rows' => $items]]],
        ])], $body, $tenantId);
    }

    /** Sends a file (e.g. an invoice PDF) made on the fly. */
    public function document(string $to, string $contents, string $filename, string $caption, ?int $tenantId = null): void
    {
        if (!$this->configured()) {
            $this->log($to, 'document', "{$filename}: {$caption}", null, 'logged', null, $tenantId);

            return;
        }

        $upload = Http::withToken(config('services.whatsapp.token'))
            ->attach('file', $contents, $filename, ['Content-Type' => 'application/pdf'])
            ->post($this->url('media'), ['messaging_product' => 'whatsapp', 'type' => 'application/pdf']);

        if (!$upload->successful() || !$upload->json('id')) {
            $this->log($to, 'document', "{$filename}: {$caption}", null, 'failed', $upload->body(), $tenantId);

            return;
        }

        $this->send($to, 'document', ['document' => ['id' => $upload->json('id'), 'filename' => $filename, 'caption' => $caption]], "{$filename}: {$caption}", $tenantId);
    }

    /** The bytes of a photo or file the tenant sent, or null. */
    public function downloadMedia(string $mediaId): ?array
    {
        if (!$this->configured()) {
            return null;
        }

        $meta = Http::withToken(config('services.whatsapp.token'))->get($this->graph($mediaId));
        if (!$meta->successful() || !$meta->json('url')) {
            return null;
        }

        $file = Http::withToken(config('services.whatsapp.token'))->get($meta->json('url'));

        return $file->successful() ? ['contents' => $file->body(), 'mime' => $meta->json('mime_type')] : null;
    }

    /** Shows the tenant's message as read (blue ticks). */
    public function markRead(string $wamid): void
    {
        if ($this->configured()) {
            Http::withToken(config('services.whatsapp.token'))->post($this->url('messages'), [
                'messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => $wamid,
            ]);
        }
    }

    protected function send(string $to, string $type, array $message, string $summary, ?int $tenantId): void
    {
        $payload = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to, 'type' => $type] + $message;

        if (!$this->configured()) {
            Log::info("WhatsApp (not configured) to {$to}: {$summary}");
            $this->log($to, $type, $summary, $payload, 'logged', null, $tenantId);

            return;
        }

        try {
            $response = Http::withToken(config('services.whatsapp.token'))->timeout(15)->post($this->url('messages'), $payload);
            $ok = $response->successful();
            $this->log($to, $type, $summary, $payload, $ok ? 'sent' : 'failed', $ok ? null : $response->body(), $tenantId, $response->json('messages.0.id'));
        } catch (\Throwable $e) {
            $this->log($to, $type, $summary, $payload, 'failed', $e->getMessage(), $tenantId);
        }
    }

    protected function log(string $to, string $type, string $body, ?array $payload, string $status, ?string $error, ?int $tenantId, ?string $wamid = null): void
    {
        WhatsAppMessage::create([
            'wa_id' => $to, 'direction' => 'out', 'wamid' => $wamid, 'type' => $type, 'body' => $body,
            'payload' => $payload, 'status' => $status, 'error' => $error ? Str::limit($error, 2000) : null, 'tenant_id' => $tenantId,
        ]);
    }

    protected function url(string $edge): string
    {
        return $this->graph(config('services.whatsapp.phone_number_id').'/'.$edge);
    }

    protected function graph(string $path): string
    {
        return 'https://graph.facebook.com/'.config('services.whatsapp.graph_version', 'v24.0').'/'.$path;
    }
}
