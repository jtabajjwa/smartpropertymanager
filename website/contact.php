<?php
/*
 * Contact form handler — sends the enquiry with PHP mail(), which works on
 * Namecheap shared hosting. Configure mail_to / mail_from in config.php.
 */

$config = require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ./#contact');
    exit;
}

session_start();

function back(string $status, string $message = '', array $old = []): void
{
    $_SESSION['contact_flash'] = ['status' => $status, 'message' => $message, 'old' => $old];
    header('Location: ./#contact', true, 303);
    exit;
}

function clean(string $key, int $max): string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    return mb_substr($value, 0, $max);
}

$name    = clean('name', 100);
$email   = clean('email', 150);
$phone   = clean('phone', 30);
$units   = clean('units', 40);
$message = clean('message', 3000);
$old     = compact('name', 'email', 'phone', 'units', 'message');

// Spam traps: hidden honeypot field, and forms submitted faster than a human could type.
$ts = (int) ($_POST['ts'] ?? 0);
if (($_POST['company_website'] ?? '') !== '' || $ts === 0 || time() - $ts < 3) {
    back('sent'); // pretend success so bots learn nothing
}

// One message per minute per visitor.
if (isset($_SESSION['contact_last']) && time() - $_SESSION['contact_last'] < 60) {
    back('error', 'Please wait a minute before sending another message.', $old);
}

if ($name === '' || $email === '' || $message === '') {
    back('error', 'Please fill in your name, email and message.', $old);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    back('error', 'Please enter a valid email address.', $old);
}

if (empty($config['mail_to'])) {
    back('error', 'Our contact form isn’t set up yet. Please reach us by phone or email instead.', $old);
}

// Strip line breaks from anything that ends up in a mail header.
$headerSafe = fn (string $v) => str_replace(["\r", "\n"], ' ', $v);

$from = $config['mail_from'] ?: $config['mail_to'];

$headers = [
    'From: Primora Website <' . $headerSafe($from) . '>',
    'Reply-To: ' . $headerSafe($name) . ' <' . $headerSafe($email) . '>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
    'X-Mailer: PHP/' . PHP_VERSION,
];

$subject = mb_encode_mimeheader((!empty($config['staging']) ? '[Staging] ' : '') . 'New website enquiry from ' . $headerSafe($name), 'UTF-8');

$body = "New enquiry from the Primora website\n"
    . str_repeat('-', 40) . "\n"
    . "Name:            {$name}\n"
    . "Email:           {$email}\n"
    . 'Phone:           ' . ($phone ?: '—') . "\n"
    . 'Portfolio size:  ' . ($units ?: '—') . "\n"
    . str_repeat('-', 40) . "\n\n"
    . $message . "\n\n"
    . str_repeat('-', 40) . "\n"
    . 'Sent ' . date('d M Y, H:i') . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown IP') . "\n";

$sent = @mail($config['mail_to'], $subject, $body, implode("\r\n", $headers), '-f' . $from);

if (!$sent) {
    back('error', 'Sorry, your message could not be sent right now. Please try again shortly.', $old);
}

$_SESSION['contact_last'] = time();
back('sent');
