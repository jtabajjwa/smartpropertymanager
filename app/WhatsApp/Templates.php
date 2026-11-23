<?php

namespace App\WhatsApp;

use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Http;

/**
 * The WhatsApp message templates Primora sends tenant alerts with. WhatsApp
 * only lets a business start a conversation with a template Meta has
 * approved, so each alert here is submitted once (php artisan
 * whatsapp:templates --submit) and only used once Meta approves it. Until
 * then that alert goes by SMS.
 *
 * Every template ends with a "Stop WhatsApp alerts" button; most also have a
 * button that opens the matching bot menu item (its payload is the bot's id).
 * Bodies never start or end with a variable (Meta rejects that).
 */
final class Templates
{
    public const LANGUAGE = 'en';

    public const STOP = 'alerts:stop';

    /** Where Meta's answer for each template is kept: name => APPROVED | PENDING | REJECTED | … */
    protected const SETTING = 'whatsapp.templates';

    /**
     * name => [body, example values, buttons (payload => text), attaches a PDF?]
     *
     * @return array<string, array{body: string, example: list<string>, buttons: array<string, string>, document: bool}>
     */
    public static function all(): array
    {
        $stop = [self::STOP => 'Stop WhatsApp alerts'];

        return [
            'primora_invoice_issued' => [
                'body' => "Hello {{1}}, {{2}} has issued invoice {{3}} for {{4}}.\n\nAmount: {{5}}\nAmount due: {{6}}\nDue date: {{7}}\n\nThe invoice is attached. Tap Pay now to pay by mobile money.",
                'example' => ['Sarah', 'Acacia Properties', 'INV-00042', 'Rent for June 2026', 'UGX 850,000', 'UGX 850,000', '5 Jun 2026'],
                'buttons' => ['pay' => 'Pay now'] + $stop,
                'document' => true,
            ],
            'primora_invoice_due_soon' => [
                'body' => 'Hello {{1}}, this is a reminder from {{2}} that invoice {{3}} of {{4}} is due on {{5}}. Thank you for paying on time.',
                'example' => ['Sarah', 'Acacia Properties', 'INV-00042', 'UGX 850,000', '5 Jun 2026'],
                'buttons' => ['pay' => 'Pay now'] + $stop,
                'document' => false,
            ],
            'primora_invoice_overdue' => [
                'body' => 'Hello {{1}}, invoice {{2}} of {{3}} from {{4}} was due on {{5}} and is now overdue. Please pay as soon as you can.',
                'example' => ['Sarah', 'INV-00042', 'UGX 850,000', 'Acacia Properties', '5 Jun 2026'],
                'buttons' => ['pay' => 'Pay now'] + $stop,
                'document' => false,
            ],
            'primora_payment_received' => [
                'body' => 'Hello {{1}}, {{2}} has received your payment of {{3}}. Receipt number: {{4}}. Your receipt is attached. Thank you!',
                'example' => ['Sarah', 'Acacia Properties', 'UGX 850,000', 'RCT-00107'],
                'buttons' => ['balance' => 'My balance'] + $stop,
                'document' => true,
            ],
            'primora_issue_update' => [
                'body' => "Hello {{1}}, there is an update on the issue you reported: {{2}}.\n\nStatus: {{3}}\nNote: {{4}}\n\nTap My issues to follow it.",
                'example' => ['Sarah', 'Kitchen sink is leaking', 'In progress', 'The plumber will come on Friday morning.'],
                'buttons' => ['issues' => 'My issues'] + $stop,
                'document' => false,
            ],
            'primora_deposit_deduction' => [
                'body' => 'Hello {{1}}, {{2}} has deducted {{3}} from your security deposit for {{4}}. Remaining deposit: {{5}}. Your tenant portal has the details.',
                'example' => ['Sarah', 'Acacia Properties', 'UGX 120,000', 'repainting the living room', 'UGX 730,000'],
                'buttons' => $stop,
                'document' => false,
            ],
            'primora_deposit_refund' => [
                'body' => 'Hello {{1}}, {{2}} has refunded {{3}} of your security deposit to you. Remaining deposit: {{4}}. Thank you for renting with them.',
                'example' => ['Sarah', 'Acacia Properties', 'UGX 730,000', 'UGX 0'],
                'buttons' => $stop,
                'document' => false,
            ],
            'primora_lease_expiring' => [
                'body' => 'Hello {{1}}, your lease for {{2}} with {{3}} ends on {{4}}. Please contact them about renewing it.',
                'example' => ['Sarah', 'Acacia Court · A4', 'Acacia Properties', '30 Jun 2026'],
                'buttons' => ['tenancy' => 'My tenancy'] + $stop,
                'document' => false,
            ],
        ];
    }

    public static function get(string $name): ?array
    {
        return self::all()[$name] ?? null;
    }

    /** Meta's last known answer for each template. */
    public static function statuses(): array
    {
        $saved = json_decode((string) Settings::get(self::SETTING, '[]'), true);

        return is_array($saved) ? $saved : [];
    }

    public static function approved(string $name): bool
    {
        return (self::statuses()[$name] ?? null) === 'APPROVED';
    }

    public static function setStatus(string $name, string $status): void
    {
        if (!isset(self::all()[$name])) {
            return;
        }
        Settings::set(self::SETTING, json_encode([$name => strtoupper($status)] + self::statuses()));
    }

    /** Fills in a template's text, e.g. for the alerts log. */
    public static function render(string $name, array $params): string
    {
        $body = self::get($name)['body'] ?? '';

        return preg_replace_callback('/\{\{(\d+)\}\}/', fn ($m) => (string) ($params[(int) $m[1] - 1] ?? ''), $body);
    }

    // --- Talking to Meta (WhatsApp Business Account) ------------------------------------

    public static function accountConfigured(): bool
    {
        return filled(config('services.whatsapp.token')) && filled(config('services.whatsapp.business_account_id'));
    }

    /**
     * Reads every template's status from Meta and saves it.
     *
     * @return array<string, string> name => status, for ours that exist on Meta
     */
    public static function sync(): array
    {
        $found = [];
        $url = self::graph(config('services.whatsapp.business_account_id').'/message_templates');
        $query = ['fields' => 'name,status,language', 'limit' => 100];

        while ($url) {
            $response = Http::withToken(config('services.whatsapp.token'))->timeout(20)->get($url, $query)->throw();
            foreach ($response->json('data', []) as $t) {
                if (isset(self::all()[$t['name'] ?? '']) && ($t['language'] ?? '') === self::LANGUAGE) {
                    $found[$t['name']] = strtoupper((string) $t['status']);
                }
            }
            $url = $response->json('paging.next');
            $query = [];
        }

        // Ours that Meta doesn't have (deleted, or never submitted) are no longer usable.
        $statuses = [];
        foreach (array_keys(self::all()) as $name) {
            $statuses[$name] = $found[$name] ?? 'NOT_SUBMITTED';
        }
        Settings::set(self::SETTING, json_encode($statuses));

        return $statuses;
    }

    /**
     * Submits one template for Meta's review. Returns Meta's status (usually
     * PENDING) or throws with Meta's reason.
     */
    public static function submit(string $name): string
    {
        $t = self::get($name) ?? throw new \InvalidArgumentException("Unknown template {$name}.");

        $components = [];
        if ($t['document']) {
            $components[] = ['type' => 'HEADER', 'format' => 'DOCUMENT', 'example' => ['header_handle' => [self::sampleDocumentHandle()]]];
        }
        $components[] = ['type' => 'BODY', 'text' => $t['body'], 'example' => ['body_text' => [$t['example']]]];
        $components[] = ['type' => 'FOOTER', 'text' => 'Primora tenant alerts'];
        $components[] = ['type' => 'BUTTONS', 'buttons' => collect($t['buttons'])->map(fn ($text) => ['type' => 'QUICK_REPLY', 'text' => $text])->values()->all()];

        $response = Http::withToken(config('services.whatsapp.token'))->timeout(30)
            ->post(self::graph(config('services.whatsapp.business_account_id').'/message_templates'), [
                'name' => $name, 'language' => self::LANGUAGE, 'category' => 'UTILITY', 'components' => $components,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException($response->json('error.error_user_msg') ?? $response->json('error.message') ?? $response->body());
        }

        $status = strtoupper((string) ($response->json('status') ?? 'PENDING'));
        self::setStatus($name, $status);

        return $status;
    }

    /**
     * A sample PDF uploaded to Meta (Resumable Upload API), needed as the
     * example for a template whose header is a document.
     */
    protected static function sampleDocumentHandle(): string
    {
        $token = config('services.whatsapp.token');
        $appId = config('services.whatsapp.app_id')
            ?: Http::withToken($token)->get(self::graph('app'))->throw()->json('id')
            ?: throw new \RuntimeException('Set WHATSAPP_APP_ID (App settings → Basic → App ID).');

        $pdf = Pdf::loadHTML('<h2>Primora</h2><p>Sample invoice / receipt attached to tenant alerts.</p>')->output();

        $session = Http::withToken($token)->post(self::graph("{$appId}/uploads").'?'.http_build_query([
            'file_name' => 'primora-sample.pdf', 'file_length' => strlen($pdf), 'file_type' => 'application/pdf',
        ]))->throw()->json('id');

        return Http::withHeaders(['Authorization' => "OAuth {$token}", 'file_offset' => '0'])
            ->withBody($pdf, 'application/pdf')->post(self::graph($session))->throw()->json('h')
            ?? throw new \RuntimeException('Meta did not return an upload handle for the sample PDF.');
    }

    protected static function graph(string $path): string
    {
        return 'https://graph.facebook.com/'.config('services.whatsapp.graph_version', 'v24.0').'/'.$path;
    }
}
