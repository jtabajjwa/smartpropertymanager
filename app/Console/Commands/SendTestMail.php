<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;

/**
 * Sends a plain test email with the configured mailer, showing the settings
 * used and the mail server's error if it fails. For checking SMTP on a server.
 */
class SendTestMail extends Command
{
    protected $signature = 'mail:test {to : Address to send the test email to}';

    protected $description = 'Send a test email using the configured mailer';

    public function handle(): int
    {
        $to = (string) $this->argument('to');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error("'{$to}' isn't an email address.");

            return self::FAILURE;
        }

        $mailer = config('mail.default');
        $smtp = config('mail.mailers.smtp');
        $this->line("Mailer: {$mailer}");
        if ($mailer === 'smtp') {
            $this->line('Server: '.($smtp['scheme'] ?? 'smtp').'://'.$smtp['host'].':'.$smtp['port'].' as '.($smtp['username'] ?: '(no username)'));
        }
        $this->line('From:   '.config('mail.from.address').' ('.config('mail.from.name').')');

        if (in_array($mailer, ['log', 'array'], true)) {
            $this->warn("MAIL_MAILER is '{$mailer}', so nothing leaves the server. Set MAIL_MAILER=smtp and the SMTP details in .env.");
        }

        try {
            Mail::raw(
                'This is a test email from '.config('app.name').' ('.config('app.env').', '.config('app.url').') sent at '.now()->toDateTimeString().' UTC. If you can read this, email is working.',
                fn (Message $message) => $message->to($to)->subject(config('app.name').' test email ('.config('app.env').')')
            );
        } catch (\Throwable $e) {
            $this->error('Sending failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Sent to {$to}. Check the inbox (and the spam folder).");

        return self::SUCCESS;
    }
}
