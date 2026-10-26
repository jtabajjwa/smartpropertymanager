<?php
$config = require __DIR__ . '/config.php';
require __DIR__ . '/lib/plans.php';

// Flash message from the contact form (only touch the session if one already exists).
$flash = null;
if (isset($_COOKIE[session_name()])) {
    session_start();
    $flash = $_SESSION['contact_flash'] ?? null;
    unset($_SESSION['contact_flash']);
}
$old = $flash['old'] ?? [];

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money(int|float $amount): string
{
    return number_format($amount, 0);
}

function icon(string $name): string
{
    $paths = [
        'building' => 'M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21',
        'users' => 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z',
        'document' => 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z',
        'repeat' => 'M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99',
        'banknotes' => 'M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z',
        'shield' => 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
        'chat' => 'M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z',
        'wrench' => 'M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z',
        'chart' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
        'lock' => 'M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z',
        'check' => 'M4.5 12.75l6 6 9-13.5',
        'arrow' => 'M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3',
        'mail' => 'M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75',
        'phone' => 'M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z',
        'map' => 'M15 10.5a3 3 0 11-6 0 3 3 0 016 0z M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z',
        'device' => 'M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3',
        'menu' => 'M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5',
        'close' => 'M6 18L18 6M6 6l12 12',
    ];

    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . $paths[$name] . '"/></svg>';
}

// The navy parts of the mark follow --logo-ink, so the header can flip it to white over the banner.
function logoMark(string $ink = 'var(--logo-ink, #1c2230)'): string
{
    return '<svg class="brand-mark" viewBox="0 0 120 120" fill="none" aria-hidden="true">'
        . '<path d="M15 55 L60 15 L105 55" style="stroke:' . $ink . '" stroke-width="11" stroke-linecap="square" stroke-linejoin="miter"/>'
        . '<path d="M35 72 L60 50 L85 72" stroke="#c1704a" stroke-width="10" stroke-linecap="square" stroke-linejoin="miter"/>'
        . '<rect x="54" y="83" width="12" height="17" rx="1" style="fill:' . $ink . '"/></svg>';
}

$appUrl   = rtrim($config['app_url'], '/');
$signup   = $appUrl . '/signup';
$login    = $appUrl . '/login';
// Prices come live from the Primora app (plans marked "Show on website"); see lib/plans.php.
$pricing     = primora_pricing($config);
$currency    = $pricing['currency'];
$tiers       = $pricing['tiers'];
$startUnits  = 10;
$startTier   = primora_tier_for($tiers, $startUnits);
$maxUnits = 60;
$year     = date('Y');

$features = [
    ['building', 'Properties, estates & units', 'Organise your portfolio by location, estate and unit. Occupancy updates itself the moment a lease starts or ends.'],
    ['users', 'Tenants & leases', 'Individuals or companies, ID documents and emergency contacts in one profile. Print lease agreements from your own templates.'],
    ['repeat', 'Automatic rent invoicing', 'Daily, weekly, monthly, quarterly or yearly. Primora raises every rent invoice on schedule and catches up if one was missed.'],
    ['banknotes', 'Payments & balances', 'Record a payment once and allocate it across invoices. Partial payments, balances and overdue amounts are always exact.'],
    ['shield', 'Security deposits', 'A clear ledger for every lease, with deposits, deductions, refunds and forfeits, so move-out is never a dispute.'],
    ['chat', 'SMS & email reminders', 'Tenants get invoice alerts, due-date and overdue reminders and payment receipts by SMS, and branded PDF invoices by email.'],
    ['wrench', 'Expenses & vendors', 'Log expenses against an estate or unit. Keep a vendor directory and track repairs with job cards from booking to done.'],
    ['chart', 'Dashboard & reports', 'Occupancy, collections, revenue against expenses and your top earning estates at a glance. Export anything to CSV.'],
    ['lock', 'Team access & audit trail', 'Invite your staff with the right level of access. Every change is recorded, and your data is visible only to your organisation.'],
];

$faqs = [
    ['Is there a free trial?', 'Yes. Every new account starts on a free trial, and you don’t need a card to sign up. Create your account, add your properties and see how Primora fits the way you work.'],
    ['Does Primora collect rent on my behalf?', 'No. Your tenants keep paying you the way they do today, whether by mobile money, bank or cash. You record each payment in Primora, and it updates invoices, balances and receipts for you.'],
    ['Will my tenants be notified automatically?', 'Yes. Tenants receive SMS alerts when an invoice is issued, reminders before and after the due date, payment confirmations and notice before their lease expires. Invoices can also be emailed as branded PDFs.'],
    ['Can my staff or property manager use it too?', 'Yes. Add as many team members as you need. Administrators control who has access, and the audit log shows who changed what and when.'],
    ['Is my data private and secure?', 'Each organisation’s data is kept completely separate, so no other landlord can ever see your properties, tenants or finances. All access is password-protected and every change is logged.'],
    ['What happens when my portfolio grows?', 'Your plan is based on how many units you manage. As you add units, you move up to the matching tier. There’s no migration and nothing to reinstall.'],
    ['Can I get my data out?', 'Always. Leases, invoices, payments, expenses and security deposits can be exported to CSV at any time.'],
];

$unitOptions = ['1 – 6 units', '7 – 20 units', '21 – 50 units', 'More than 50 units'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Primora — Property management software for landlords</title>
    <meta name="description" content="Primora automates rent invoicing, payment tracking, security deposits and SMS reminders for landlords and property managers in Uganda. Start your free trial.">
    <meta name="theme-color" content="#1c2230">
    <link rel="canonical" href="<?= e(rtrim($config['site_url'], '/')) ?>/">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Primora">
    <meta property="og:title" content="Primora — Property management software for landlords">
    <meta property="og:description" content="Rent invoicing, payments, deposits and tenant reminders, handled. Built for landlords in Uganda.">
    <meta property="og:url" content="<?= e(rtrim($config['site_url'], '/')) ?>/">

    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
    <link rel="preload" href="assets/fonts/inter-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="assets/fonts/inter-latin-700-normal.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&display=swap">
    <link rel="stylesheet" href="assets/css/style.css?v=3">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>

<!-- ============ Header ============ -->
<header class="site-header is-dark" id="top">
    <div class="container nav">
        <a href="#top" class="brand" aria-label="Primora home">
            <?= logoMark() ?>
            <span class="brand-text">
                <span class="brand-name">Primora</span>
                <span class="brand-tag">PROPTECH</span>
            </span>
        </a>

        <nav class="nav-links" aria-label="Main">
            <a href="#features">Features</a>
            <a href="#how-it-works">How it works</a>
            <a href="#pricing">Pricing</a>
            <a href="#faq">FAQ</a>
            <a href="#contact">Contact</a>
        </nav>

        <div class="nav-actions">
            <a class="nav-signin" href="<?= e($login) ?>">Sign in</a>
            <a class="btn btn-primary btn-sm" href="<?= e($signup) ?>">Start free trial</a>
        </div>

        <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="mobile-menu" aria-label="Open menu">
            <span class="icon-open"><?= icon('menu') ?></span>
            <span class="icon-close"><?= icon('close') ?></span>
        </button>
    </div>

    <div class="mobile-menu" id="mobile-menu">
        <div class="container">
            <a class="mobile-link" href="#features">Features</a>
            <a class="mobile-link" href="#how-it-works">How it works</a>
            <a class="mobile-link" href="#pricing">Pricing</a>
            <a class="mobile-link" href="#faq">FAQ</a>
            <a class="mobile-link" href="#contact">Contact</a>
            <div class="mobile-actions">
                <a class="btn btn-ghost" href="<?= e($login) ?>">Sign in</a>
                <a class="btn btn-primary" href="<?= e($signup) ?>">Start free trial</a>
            </div>
        </div>
    </div>
</header>

<main id="main">

<!-- ============ Banner ============ -->
<section class="banner">
    <div class="container banner-copy">
        <span class="hero-badge"><span>New</span> Branded PDF invoices, emailed automatically</span>
        <h1>Every property, lease and shilling, <em>in one place.</em></h1>
        <p class="hero-lead">Primora handles rent invoicing, payment tracking, security deposits and tenant reminders for you. Spend less time chasing rent and more time growing your portfolio.</p>
        <div class="hero-ctas">
            <a class="btn btn-primary" href="<?= e($signup) ?>">Start your free trial <?= icon('arrow') ?></a>
            <a class="btn btn-outline-light" href="#how-it-works">See how it works</a>
        </div>
        <ul class="hero-note">
            <li><?= icon('check') ?> No card required</li>
            <li><?= icon('check') ?> Set up in an afternoon</li>
            <li><?= icon('check') ?> Built for UGX & mobile money</li>
        </ul>
    </div>
    <img class="skyline" src="assets/img/skyline.svg" alt="" width="1600" height="380">
</section>

<!-- ============ Product showcase ============ -->
<section class="showcase" aria-label="Product preview">
    <div class="container">
        <div class="mock-wrap" aria-hidden="true">
            <div class="mock">
                <div class="mock-bar"><i></i><i></i><i></i><span class="mock-url">primora · dashboard</span></div>
                <div class="mock-body">
                    <div class="mock-side">
                        <div class="ms-brand"><?= logoMark() ?> Primora</div>
                        <div class="ms-item active"><b></b>Dashboard</div>
                        <div class="ms-label">Property</div>
                        <div class="ms-item"><b></b>Estates</div>
                        <div class="ms-item"><b></b>Units</div>
                        <div class="ms-label">Tenancy</div>
                        <div class="ms-item"><b></b>Tenants</div>
                        <div class="ms-item"><b></b>Leases</div>
                        <div class="ms-label">Finance</div>
                        <div class="ms-item"><b></b>Invoices</div>
                        <div class="ms-item"><b></b>Payments</div>
                    </div>
                    <div class="mock-main">
                        <div class="mock-title">Good morning</div>
                        <div class="mock-sub">Here’s how your portfolio is doing this month</div>
                        <div class="mock-stats">
                            <div class="mock-stat"><div class="k">Collected</div><div class="v"><small>UGX</small>18.4M</div><div class="d">▲ 12% this month</div></div>
                            <div class="mock-stat"><div class="k">Active leases</div><div class="v">47</div><div class="d">3 expiring soon</div></div>
                            <div class="mock-stat"><div class="k">Overdue</div><div class="v"><small>UGX</small>1.2M</div><div class="d warn">4 invoices</div></div>
                        </div>
                        <div class="mock-panels">
                            <div class="mock-panel">
                                <h4>Revenue vs expenses <span>6 mo</span></h4>
                                <div class="chart">
                                    <div class="col"><i style="height:52%"></i><i style="height:24%"></i><em>May</em></div>
                                    <div class="col"><i style="height:60%"></i><i style="height:30%"></i><em>Jun</em></div>
                                    <div class="col"><i style="height:56%"></i><i style="height:22%"></i><em>Jul</em></div>
                                    <div class="col"><i style="height:72%"></i><i style="height:34%"></i><em>Aug</em></div>
                                    <div class="col"><i style="height:78%"></i><i style="height:26%"></i><em>Sep</em></div>
                                    <div class="col"><i style="height:90%"></i><i style="height:30%"></i><em>Oct</em></div>
                                </div>
                                <div class="legend"><span>Income</span><span>Expenses</span></div>
                            </div>
                            <div class="mock-panel">
                                <h4>Recent invoices <span>View all</span></h4>
                                <ul class="inv">
                                    <li><span class="n">Sarah N.</span><span class="a">850,000</span><span class="m">INV-00128 · Kololo B4</span><span class="pill paid">Paid</span></li>
                                    <li><span class="n">Okello Trading</span><span class="a">2,400,000</span><span class="m">INV-00127 · Ntinda S2</span><span class="pill partial">Partial</span></li>
                                    <li><span class="n">David M.</span><span class="a">600,000</span><span class="m">INV-00126 · Kira A1</span><span class="pill overdue">Overdue</span></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="float-card float-occ">
                <div class="ring" style="--p:94"><span>94%</span></div>
                <div class="t"><strong>Occupancy</strong>44 of 47 units let</div>
            </div>

            <div class="float-card float-sms">
                <div class="fs-head"><b><?= icon('chat') ?></b><strong>SMS sent to tenant</strong> · just now</div>
                <p>Dear Sarah, payment of UGX 850,000 received. Thank you!</p>
            </div>
        </div>
    </div>
</section>

<!-- ============ Strip ============ -->
<div class="strip">
    <div class="container">
        <ul>
            <li><span class="ic"><?= icon('repeat') ?></span><span><strong>Automatic rent invoices</strong><small>On every lease’s own schedule</small></span></li>
            <li><span class="ic"><?= icon('device') ?></span><span><strong>SMS on any phone</strong><small>No app needed for tenants</small></span></li>
            <li><span class="ic"><?= icon('shield') ?></span><span><strong>Deposits accounted for</strong><small>A full ledger for every lease</small></span></li>
            <li><span class="ic"><?= icon('lock') ?></span><span><strong>Your data stays yours</strong><small>Private to your organisation</small></span></li>
        </ul>
    </div>
</div>

<!-- ============ Features ============ -->
<section class="section" id="features">
    <div class="container">
        <div class="section-head center">
            <span class="eyebrow">Features</span>
            <h2 class="section-title">Everything you need to run your rentals, <em>and nothing you don’t</em></h2>
            <p class="section-lead">From the first viewing to the final deposit refund, Primora keeps every part of your portfolio organised and every shilling accounted for.</p>
        </div>

        <div class="features-grid">
            <?php foreach ($features as [$ic, $title, $text]): ?>
                <article class="feature">
                    <span class="ic"><?= icon($ic) ?></span>
                    <h3><?= e($title) ?></h3>
                    <p><?= e($text) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============ How it works ============ -->
<section class="section how" id="how-it-works">
    <div class="container">
        <div class="section-head">
            <span class="eyebrow">How it works</span>
            <h2 class="section-title">Up and running in <em>three steps</em></h2>
            <p class="section-lead">Set it up once. From then on, Primora runs the routine work of managing rentals every day.</p>
        </div>

        <div class="steps">
            <div class="step">
                <span class="num">1</span>
                <h3>Add your properties</h3>
                <p>Create your locations, estates and units in minutes and see your whole portfolio in one view.</p>
                <ul>
                    <li><?= icon('check') ?> Group units by estate and location</li>
                    <li><?= icon('check') ?> Mark units vacant, occupied or in maintenance</li>
                </ul>
            </div>
            <div class="step">
                <span class="num">2</span>
                <h3>Register tenants & leases</h3>
                <p>Capture tenant details, set the rent, billing cycle and deposit, and print the agreement.</p>
                <ul>
                    <li><?= icon('check') ?> Deposit and first rent invoices are created for you</li>
                    <li><?= icon('check') ?> Lease agreements from your own templates</li>
                </ul>
            </div>
            <div class="step">
                <span class="num">3</span>
                <h3>Let Primora do the rest</h3>
                <p>Invoices go out on time, reminders follow up and you simply record payments as they come in.</p>
                <ul>
                    <li><?= icon('check') ?> Automatic recurring invoices & SMS reminders</li>
                    <li><?= icon('check') ?> Live balances, arrears and reports</li>
                </ul>
            </div>
        </div>
    </div>
</section>

<!-- ============ Built for Uganda ============ -->
<section class="section">
    <div class="container split">
        <div>
            <span class="eyebrow">Built for how you work</span>
            <h2 class="section-title">Made for landlords in Uganda, <em>not adapted for them</em></h2>
            <p class="section-lead">Primora speaks your currency, records the payment methods your tenants actually use and reaches them on the phones they already have.</p>
            <ul class="checklist">
                <li><span class="ic"><?= icon('check') ?></span><div><strong>Shillings throughout</strong><span>Invoices, receipts, reports and reminders all in UGX.</span></div></li>
                <li><span class="ic"><?= icon('check') ?></span><div><strong>Every way your tenants pay</strong><span>Record MTN MoMo, Airtel Money, FlexiPay, bank transfers and cash.</span></div></li>
                <li><span class="ic"><?= icon('check') ?></span><div><strong>Tenants and walk-in customers</strong><span>Bill long-term tenants and one-off customers from the same system.</span></div></li>
                <li><span class="ic"><?= icon('check') ?></span><div><strong>Fair, unit-based pricing</strong><span>Pay for the size of your portfolio, not for features you won’t use.</span></div></li>
            </ul>
        </div>

        <div class="methods" aria-hidden="true">
            <h4>Payments recorded this week</h4>
            <div class="method-list">
                <div class="method"><span class="logo lg-mtn">MoMo</span><span class="label">MTN Mobile Money</span><span class="amt">3,250,000</span></div>
                <div class="method"><span class="logo lg-airtel">Airtel</span><span class="label">Airtel Money</span><span class="amt">1,400,000</span></div>
                <div class="method"><span class="logo lg-bank"><?= icon('building') ?></span><span class="label">Bank transfer</span><span class="amt">4,800,000</span></div>
                <div class="method"><span class="logo lg-cash">UGX</span><span class="label">Cash</span><span class="amt">900,000</span></div>
            </div>
            <div class="total"><span>Total received</span><strong>UGX 10,350,000</strong></div>
            <p class="foot">Each payment is matched to its invoices automatically, and tenants receive an SMS receipt.</p>
        </div>
    </div>
</section>

<!-- ============ Pricing ============ -->
<section class="section pricing" id="pricing">
    <div class="container">
        <div class="section-head center">
            <span class="eyebrow">Pricing</span>
            <h2 class="section-title">Simple pricing that <em>grows with your portfolio</em></h2>
            <p class="section-lead">Every feature on every plan. You only pay according to the number of units you manage.</p>
        </div>

        <!-- pricing source: <?= e($pricing['source']) ?> -->
        <div class="price-card" id="price-card" data-tiers='<?= e(json_encode($tiers)) ?>' data-currency="<?= e($currency) ?>" data-max="<?= $maxUnits ?>">
            <div class="price-calc">
                <fieldset class="cycle">
                    <legend class="sr-only">Billing cycle</legend>
                    <?php foreach (['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'annual' => 'Annual'] as $value => $label): ?>
                        <label><input type="radio" name="cycle" value="<?= $value ?>"<?= $value === 'monthly' ? ' checked' : '' ?>><span><?= $label ?></span></label>
                    <?php endforeach; ?>
                </fieldset>

                <label for="units" class="units-label">How many units do you manage?</label>
                <div class="units-readout"><output id="units-out" for="units"><?= $startUnits ?></output><span>units</span></div>
                <input class="range" type="range" id="units" min="1" max="<?= $maxUnits ?>" value="<?= $startUnits ?>" aria-describedby="price-amount">
                <div class="range-scale"><span>1</span><span>20</span><span>40</span><span><?= $maxUnits ?>+</span></div>

                <div class="price-out" aria-live="polite">
                    <div class="tier" id="price-tier"><?= $startTier[1] === null ? $startTier[0] . '+ units' : $startTier[0] . ' – ' . $startTier[1] . ' units' ?></div>
                    <div class="amount" id="price-amount"><small><?= e($currency) ?></small><strong id="price-value"><?= money($startTier[2]) ?></strong><span id="price-period">/ month</span></div>
                    <div class="annual" id="price-note">Billed monthly</div>
                </div>

                <a class="btn btn-primary btn-block" href="<?= e($signup) ?>">Start your free trial</a>
                <p class="fine">No card required · Cancel anytime</p>
            </div>

            <div class="price-incl">
                <h3>Everything included</h3>
                <p>No add-ons, no feature tiers.</p>
                <ul class="incl-list">
                    <li><?= icon('check') ?> Unlimited tenants, leases & invoices</li>
                    <li><?= icon('check') ?> Automatic recurring rent invoicing</li>
                    <li><?= icon('check') ?> SMS reminders & payment receipts</li>
                    <li><?= icon('check') ?> Branded PDF invoices by email</li>
                    <li><?= icon('check') ?> Security deposit ledger</li>
                    <li><?= icon('check') ?> Expenses, vendors & job cards</li>
                    <li><?= icon('check') ?> Team accounts & full audit log</li>
                    <li><?= icon('check') ?> Dashboard, reports & CSV exports</li>
                </ul>

                <div class="tier-table">
                    <h4>All tiers · <span id="tier-period">per month</span></h4>
                    <ul id="tier-list">
                        <?php foreach ($tiers as $i => [$min, $max, $monthly, $quarterly, $annual]): ?>
                            <li data-tier="<?= $i ?>"><span><?= $max === null ? $min . '+ units' : $min . ' – ' . $max . ' units' ?></span><b data-monthly="<?= money($monthly) ?>" data-quarterly="<?= money($quarterly) ?>" data-annual="<?= money($annual) ?>"><?= money($monthly) ?></b></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============ FAQ ============ -->
<section class="section" id="faq">
    <div class="container faq-grid">
        <div class="faq-aside">
            <span class="eyebrow">FAQ</span>
            <h2 class="section-title">Questions, <em>answered</em></h2>
            <p class="section-lead">Can’t find what you’re looking for? Our team is happy to walk you through Primora.</p>
            <a class="btn btn-ghost" href="#contact">Talk to us</a>
        </div>
        <div class="faq-list">
            <?php foreach ($faqs as $i => [$q, $a]): ?>
                <details<?= $i === 0 ? ' open' : '' ?>>
                    <summary><?= e($q) ?><span class="pm" aria-hidden="true"></span></summary>
                    <p><?= e($a) ?></p>
                </details>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============ CTA ============ -->
<section class="cta">
    <div class="container">
        <div class="cta-box">
            <div>
                <h2>Stop chasing rent. <em>Start managing your portfolio.</em></h2>
                <p>Swap spreadsheets, receipt books and phone reminders for one system that keeps up with you.</p>
            </div>
            <div class="cta-actions">
                <a class="btn btn-primary" href="<?= e($signup) ?>">Start free trial</a>
                <a class="btn btn-outline-light" href="#contact">Book a demo</a>
            </div>
        </div>
    </div>
</section>

<!-- ============ Contact ============ -->
<section class="section contact" id="contact">
    <div class="container contact-grid">
        <div>
            <span class="eyebrow">Contact</span>
            <h2 class="section-title">Let’s talk about <em>your properties</em></h2>
            <p class="section-lead">Want a guided demo, help moving your records over or answers to a specific question? Send us a message and we’ll get back to you shortly.</p>
            <ul class="contact-points">
                <?php if ($config['contact_email']): ?>
                    <li><span class="ic"><?= icon('mail') ?></span><div><small>Email</small><a href="mailto:<?= e($config['contact_email']) ?>"><?= e($config['contact_email']) ?></a></div></li>
                <?php endif; ?>
                <?php if ($config['contact_phone']): ?>
                    <li><span class="ic"><?= icon('phone') ?></span><div><small>Phone</small><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $config['contact_phone'])) ?>"><?= e($config['contact_phone']) ?></a></div></li>
                <?php endif; ?>
                <?php if ($config['whatsapp']): ?>
                    <li><span class="ic"><?= icon('chat') ?></span><div><small>WhatsApp</small><a href="https://wa.me/<?= e($config['whatsapp']) ?>" target="_blank" rel="noopener">Chat with us</a></div></li>
                <?php endif; ?>
                <?php if ($config['address']): ?>
                    <li><span class="ic"><?= icon('map') ?></span><div><small>Office</small><strong><?= e($config['address']) ?></strong></div></li>
                <?php endif; ?>
            </ul>
        </div>

        <form class="form" action="contact.php" method="post" novalidate>
            <?php if ($flash && $flash['status'] === 'sent'): ?>
                <div class="alert alert-success" role="status"><?= icon('check') ?><span>Thank you, your message has been sent. We’ll be in touch shortly.</span></div>
            <?php elseif ($flash && $flash['status'] === 'error'): ?>
                <div class="alert alert-error" role="alert"><?= icon('close') ?><span><?= e($flash['message']) ?></span></div>
            <?php endif; ?>

            <div class="form-row">
                <div class="field">
                    <label for="name">Full name</label>
                    <input id="name" name="name" type="text" required maxlength="100" autocomplete="name" value="<?= e($old['name'] ?? '') ?>">
                </div>
                <div class="field">
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" required maxlength="150" autocomplete="email" value="<?= e($old['email'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="field">
                    <label for="phone">Phone <span class="opt">(optional)</span></label>
                    <input id="phone" name="phone" type="tel" maxlength="30" autocomplete="tel" placeholder="+256" value="<?= e($old['phone'] ?? '') ?>">
                </div>
                <div class="field">
                    <label for="units-select">Portfolio size <span class="opt">(optional)</span></label>
                    <select id="units-select" name="units">
                        <option value="">Select…</option>
                        <?php foreach ($unitOptions as $opt): ?>
                            <option<?= ($old['units'] ?? '') === $opt ? ' selected' : '' ?>><?= e($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label for="message">How can we help?</label>
                <textarea id="message" name="message" required maxlength="3000"><?= e($old['message'] ?? '') ?></textarea>
            </div>

            <div class="hp" aria-hidden="true">
                <label for="company_website">Leave this empty</label>
                <input id="company_website" name="company_website" type="text" tabindex="-1" autocomplete="off">
            </div>
            <input type="hidden" name="ts" value="<?= time() ?>">

            <button class="btn btn-primary btn-block" type="submit">Send message</button>
            <p class="fine">We’ll only use your details to reply to your enquiry.</p>
        </form>
    </div>
</section>

</main>

<!-- ============ Footer ============ -->
<footer class="site-footer">
    <div class="container">
        <div class="footer-grid">
            <div>
                <a href="#top" class="brand" aria-label="Primora home">
                    <?= logoMark('#ffffff') ?>
                    <span class="brand-text">
                        <span class="brand-name">Primora</span>
                        <span class="brand-tag">PROPTECH</span>
                    </span>
                </a>
                <p class="footer-about">Property management software that handles invoicing, payments, deposits and tenant communication, so landlords can focus on growth.</p>
            </div>
            <div>
                <h4>Product</h4>
                <ul class="footer-links">
                    <li><a href="#features">Features</a></li>
                    <li><a href="#how-it-works">How it works</a></li>
                    <li><a href="#pricing">Pricing</a></li>
                </ul>
            </div>
            <div>
                <h4>Company</h4>
                <ul class="footer-links">
                    <li><a href="#faq">FAQ</a></li>
                    <li><a href="#contact">Contact</a></li>
                </ul>
            </div>
            <div>
                <h4>Account</h4>
                <ul class="footer-links">
                    <li><a href="<?= e($login) ?>">Sign in</a></li>
                    <li><a href="<?= e($signup) ?>">Start free trial</a></li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            <span>© <?= $year ?> Primora. All rights reserved.</span>
            <span>Made in Uganda</span>
        </div>
    </div>
</footer>

<script src="assets/js/main.js?v=4" defer></script>
</body>
</html>
