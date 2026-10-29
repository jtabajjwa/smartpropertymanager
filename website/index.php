<?php
$config = require __DIR__ . '/config.php';
require __DIR__ . '/lib/plans.php';
require __DIR__ . '/lib/helpers.php';

// A staging copy (config.local.php sets 'staging' => true) stays out of search engines.
if (!empty($config['staging'])) {
    header('X-Robots-Tag: noindex, nofollow');
}

// Flash message from the contact form (only touch the session if one already exists).
$flash = null;
if (isset($_COOKIE[session_name()])) {
    session_start();
    $flash = $_SESSION['contact_flash'] ?? null;
    unset($_SESSION['contact_flash']);
}
$old = $flash['old'] ?? [];

$appUrl   = rtrim($config['app_url'], '/');
$signup   = $appUrl . '/signup';
$login    = $appUrl . '/login';
// Prices come live from the Primora app (plans marked "Show on website"); see lib/plans.php.
$pricing     = primora_pricing($config);
$currency    = $pricing['currency'];
$tiers       = $pricing['tiers'];
$startUnits  = 10;
$startTier   = primora_tier_for($tiers, $startUnits);
// Two or more published plans with feature lists: show a plan switcher and a comparison.
$comparePlans = primora_comparable_plans($pricing);
$catalogue    = $pricing['catalogue'] ?? [];
// True when no published plan leaves a feature out (so "every feature on every plan" holds).
$onEveryPlan  = function (string $feature) use ($comparePlans): bool {
    foreach ($comparePlans as $plan) {
        if (!in_array($feature, $plan['features'], true)) {
            return false;
        }
    }
    return true;
};
$everyFeatureEverywhere = !$comparePlans || count(array_filter($catalogue, $onEveryPlan)) === count($catalogue);
$primaryIndex = 0;
foreach ($comparePlans as $i => $plan) {
    if (!empty($plan['primary'])) {
        $primaryIndex = $i;
    }
}
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
<?php if (!empty($config['staging'])): ?>
    <meta name="robots" content="noindex, nofollow">
<?php endif; ?>
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
    <link rel="stylesheet" href="assets/css/style.css?v=7">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<?php if (!empty($config['staging'])): ?>
<div style="background:#c1704a; color:#fff; text-align:center; font-size:13px; padding:6px 16px;">Staging site: plans and sign-in links point to the staging app.</div>
<?php endif; ?>

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
            <p class="section-lead"><?= $everyFeatureEverywhere ? 'Every feature on every plan. You only pay according to the number of units you manage.' : 'The essentials on every plan. Choose the plan that fits, and pay according to the number of units you manage.' ?></p>
        </div>

        <!-- pricing source: <?= e($pricing['source']) ?> -->
        <div class="price-card" id="price-card" data-tiers='<?= e(json_encode($tiers)) ?>' data-plans='<?= e(json_encode(array_map(fn ($p) => ['name' => $p['name'], 'tiers' => $p['tiers']], $comparePlans))) ?>' data-currency="<?= e($currency) ?>" data-max="<?= $maxUnits ?>">
            <div class="price-calc">
                <?php if ($comparePlans): ?>
                    <fieldset class="cycle plan-pick" style="grid-template-columns: repeat(<?= count($comparePlans) ?>, 1fr)">
                        <legend class="sr-only">Plan</legend>
                        <?php foreach ($comparePlans as $i => $plan): ?>
                            <label><input type="radio" name="plan" value="<?= $i ?>"<?= $i === $primaryIndex ? ' checked' : '' ?>><span><?= e($plan['name']) ?></span></label>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endif; ?>
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
                <h3><?= $everyFeatureEverywhere ? 'Everything included' : 'On every plan' ?></h3>
                <p><?= $everyFeatureEverywhere ? 'No add-ons, no feature tiers.' : 'See what each plan adds in the comparison below.' ?></p>
                <ul class="incl-list">
                    <li><?= icon('check') ?> Unlimited tenants, leases & invoices</li>
                    <li><?= icon('check') ?> Automatic recurring rent invoicing</li>
                    <li><?= icon('check') ?> SMS reminders & payment receipts</li>
                    <li><?= icon('check') ?> Branded PDF invoices by email</li>
                    <li><?= icon('check') ?> Security deposit ledger</li>
                    <li><?= icon('check') ?> <?= $onEveryPlan('Vendors & job cards') ? 'Expenses, vendors & job cards' : 'Expense tracking' ?></li>
                    <?php if ($onEveryPlan('Team members') && $onEveryPlan('Audit log')): ?>
                        <li><?= icon('check') ?> Team accounts & full audit log</li>
                    <?php endif; ?>
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

        <?php if ($comparePlans): ?>
            <div class="compare" id="compare">
                <h3 class="compare-title">Compare plans</h3>
                <div class="compare-scroll" tabindex="0" role="region" aria-label="Plan comparison">
                    <table class="compare-table">
                        <thead>
                            <tr>
                                <th scope="col"><span class="sr-only">Feature</span></th>
                                <?php foreach ($comparePlans as $plan): ?>
                                    <th scope="col">
                                        <span class="plan-name"><?= e($plan['name']) ?></span>
                                        <span class="plan-from">From <?= e($currency) ?> <b><?= money($plan['tiers'][0][2]) ?></b> / month</span>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <th scope="row"><?= $everyFeatureEverywhere ? 'Everything listed above' : 'Everything listed under “On every plan”' ?></th>
                                <?php foreach ($comparePlans as $plan): ?>
                                    <td class="yes"><?= icon('check') ?><span class="sr-only">Included</span></td>
                                <?php endforeach; ?>
                            </tr>
                            <?php foreach ($catalogue as $feature): ?>
                                <tr>
                                    <th scope="row"><?= e($feature) ?></th>
                                    <?php foreach ($comparePlans as $plan): ?>
                                        <?php if (in_array($feature, $plan['features'], true)): ?>
                                            <td class="yes"><?= icon('check') ?><span class="sr-only">Included</span></td>
                                        <?php else: ?>
                                            <td class="no"><span aria-hidden="true">–</span><span class="sr-only">Not included</span></td>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="compare-note">“From” is the price for the fewest units, billed monthly. Use the calculator above for your number of units.</p>
            </div>
        <?php endif; ?>
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
                    <li><a href="privacy.php">Privacy policy</a></li>
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
            <span>© <?= $year ?> Primora. All rights reserved. · <a href="privacy.php">Privacy</a></span>
            <span>Made in Uganda</span>
        </div>
    </div>
</footer>

<script src="assets/js/main.js?v=5" defer></script>
</body>
</html>
