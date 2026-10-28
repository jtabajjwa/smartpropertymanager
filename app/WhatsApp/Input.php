<?php

namespace App\WhatsApp;

/** What a tenant sent: typed text, a button / menu choice (its id), or a photo. */
final class Input
{
    /** Words that bring up the menu. */
    public const GREETINGS = ['hi', 'hello', 'hey', 'menu', 'start', 'help', 'hallo', 'oli otya', '0'];

    /** Typed words that jump straight to a menu item. */
    public const KEYWORDS = [
        'balance' => 'balance', 'invoice' => 'invoices', 'invoices' => 'invoices', 'pay' => 'pay', 'pay rent' => 'pay',
        'payments' => 'payments', 'receipts' => 'payments', 'issue' => 'issue_new', 'report' => 'issue_new',
        'issues' => 'issues', 'tenancy' => 'tenancy', 'lease' => 'tenancy', 'logout' => 'logout', 'sign out' => 'logout',
    ];

    public function __construct(
        public string $type,
        public ?string $text = null,
        public ?string $id = null,
        public ?string $mediaId = null,
    ) {
    }

    public static function from(array $message): self
    {
        $type = (string) ($message['type'] ?? 'unknown');

        return match ($type) {
            'text' => new self('text', trim((string) ($message['text']['body'] ?? ''))),
            'interactive' => new self('interactive',
                $message['interactive']['button_reply']['title'] ?? $message['interactive']['list_reply']['title'] ?? null,
                $message['interactive']['button_reply']['id'] ?? $message['interactive']['list_reply']['id'] ?? null),
            // A tap on a quick-reply button of a template message.
            'button' => new self('interactive', $message['button']['text'] ?? null, $message['button']['payload'] ?? null),
            'image' => new self('image', $message['image']['caption'] ?? null, null, $message['image']['id'] ?? null),
            default => new self($type),
        };
    }

    public function isGreeting(): bool
    {
        return $this->type === 'text' && in_array(mb_strtolower(trim((string) $this->text, " \t\n!.")), self::GREETINGS, true);
    }

    /** A typed word for a menu item, e.g. "balance". */
    public function keyword(): ?string
    {
        return $this->type === 'text' ? (self::KEYWORDS[mb_strtolower(trim((string) $this->text, " \t\n!.?"))] ?? null) : null;
    }

    /** The menu item chosen, by button or by typing its keyword. */
    public function is(string $action): bool
    {
        return $this->id === $action || $this->keyword() === $action;
    }
}
