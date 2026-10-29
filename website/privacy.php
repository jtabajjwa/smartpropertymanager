<?php
$config = require __DIR__ . '/config.php';
require __DIR__ . '/lib/helpers.php';

if (!empty($config['staging'])) {
    header('X-Robots-Tag: noindex, nofollow');
}

$company = $config['company_name'] ?: 'Primora';
$email   = $config['privacy_email'] ?: $config['mail_to'];
$address = $config['address'];
$updated = '28 October 2026';
$year    = date('Y');
$login   = rtrim($config['app_url'], '/') . '/login';

$sections = [
    'who'       => 'Who we are',
    'roles'     => 'Landlords’ data and tenants’ data',
    'collect'   => 'What we collect',
    'use'       => 'How we use it',
    'share'     => 'Who we share it with',
    'whatsapp'  => 'Messages: SMS, email and WhatsApp',
    'keep'      => 'How long we keep it',
    'security'  => 'How we protect it',
    'transfers' => 'Where it is stored',
    'rights'    => 'Your rights',
    'delete'    => 'Deleting your data',
    'cookies'   => 'Cookies',
    'children'  => 'Children',
    'changes'   => 'Changes to this policy',
    'contact'   => 'Contact us',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privacy policy — Primora</title>
    <meta name="description" content="How Primora collects, uses and protects the personal data of landlords, their staff and their tenants.">
    <meta name="theme-color" content="#1c2230">
<?php if (!empty($config['staging'])): ?>
    <meta name="robots" content="noindex, nofollow">
<?php endif; ?>
    <link rel="canonical" href="<?= e(rtrim($config['site_url'], '/')) ?>/privacy.php">
    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
    <link rel="preload" href="assets/fonts/inter-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="assets/css/style.css?v=7">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>

<header class="site-header is-plain">
    <div class="container nav">
        <a href="./" class="brand" aria-label="Primora home">
            <?= logoMark() ?>
            <span class="brand-text">
                <span class="brand-name">Primora</span>
                <span class="brand-tag">PROPTECH</span>
            </span>
        </a>
        <a class="legal-back" href="./">← Back to home</a>
    </div>
</header>

<main id="main" class="legal">
    <div class="container">
        <article class="legal-body">
            <h1>Privacy policy</h1>
            <p class="legal-meta">Last updated <?= e($updated) ?></p>

            <p class="legal-lead">Primora is property management software for landlords and property managers. This policy explains what personal data we handle when you use our website, the Primora app, the tenant portal and our WhatsApp service, why we handle it, and the choices and rights you have. We follow Uganda’s Data Protection and Privacy Act, 2019.</p>

            <nav class="legal-toc" aria-label="Contents">
                <h2>Contents</h2>
                <ol>
                    <?php foreach ($sections as $id => $title): ?>
                        <li><a href="#<?= e($id) ?>"><?= e($title) ?></a></li>
                    <?php endforeach; ?>
                </ol>
            </nav>

            <h2 id="who">1. Who we are</h2>
            <p>Primora is operated by <?= e($company) ?>, <?= e($address) ?> (“Primora”, “we”, “us”). You can reach us about anything in this policy at <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a>.</p>

            <h2 id="roles">2. Landlords’ data and tenants’ data</h2>
            <p>Primora is used by landlords and property managers (“landlords”) to run their properties. That gives us two different roles:</p>
            <ul class="bullets">
                <li><strong>Landlords’ own accounts.</strong> For a landlord’s account, billing and use of Primora, and for visitors to our website, we decide how the data is used, so we are responsible for it.</li>
                <li><strong>Tenants’ data.</strong> Information a landlord records about their tenants (names, contact details, leases, invoices, payments and so on) belongs to that landlord. We store and process it on the landlord’s behalf and only to provide Primora to them. The landlord is responsible for having a lawful reason to record it and for telling their tenants.</li>
            </ul>
            <p>If you are a tenant and have a question about the information your landlord keeps, you can ask your landlord directly or contact us, and we will help.</p>

            <h2 id="collect">3. What we collect</h2>
            <h3>From landlords and their staff</h3>
            <ul class="bullets">
                <li>Account details: name, email address, phone number, organisation name and the details of team members you invite.</li>
                <li>Billing details: your subscription plan, payments to Primora and the mobile money number you pay from.</li>
                <li>Payout details: the mobile money numbers you ask us to pay rent collections out to.</li>
                <li>Everything you record in Primora: properties, units, tenants, leases, invoices, payments, expenses, vendors, job cards, deposits, accounting records and documents you upload.</li>
            </ul>
            <h3>About tenants (recorded by their landlord, or by the tenant in the portal)</h3>
            <ul class="bullets">
                <li>Name, phone number, email address, and for companies the company’s details.</li>
                <li>Identity documents and emergency contacts, if the landlord records them.</li>
                <li>Lease details, invoices, payments, balances and security deposits.</li>
                <li>Issues and maintenance requests the tenant reports, including any photos or messages attached.</li>
                <li>Rent payments made through the tenant portal or WhatsApp: the mobile money number, amount and payment reference.</li>
            </ul>
            <h3>Collected automatically</h3>
            <ul class="bullets">
                <li>Sign-in records, one-time sign-in codes (kept only until they expire), IP address, browser and device type.</li>
                <li>An audit log of changes made in a landlord’s account (who changed what, and when).</li>
                <li>Records of the SMS, email and WhatsApp messages we send and receive (see section 6).</li>
            </ul>
            <h3>From our website</h3>
            <p>If you use the contact form we receive your name, email address, phone number, portfolio size and message. We use them only to reply to you. The website does not use advertising or analytics trackers.</p>

            <h2 id="use">4. How we use it</h2>
            <ul class="bullets">
                <li>To provide Primora: keep landlords’ records, raise invoices, record and reconcile payments, and produce reports.</li>
                <li>To send the notifications landlords set up, such as invoices, receipts and rent reminders to tenants.</li>
                <li>To let tenants sign in to the portal or WhatsApp service, see their invoices and balances, pay rent and report issues.</li>
                <li>To process mobile money payments and pay rent collections out to landlords.</li>
                <li>To bill landlords for their subscription and send account and service messages.</li>
                <li>To keep Primora secure, prevent fraud and abuse, and fix problems.</li>
                <li>To meet our legal obligations, for example tax and accounting rules.</li>
            </ul>
            <p>We rely on the contract with the landlord, the legitimate interests of running a secure service, and our legal obligations. Where the law requires consent, we ask for it. <strong>We do not sell personal data, and we do not use tenants’ data for marketing.</strong></p>

            <h2 id="share">5. Who we share it with</h2>
            <p>We share personal data only as needed to run Primora, with service providers who handle it on our instructions:</p>
            <ul class="bullets">
                <li><strong>Hosting:</strong> the providers of the servers that run the Primora app and this website.</li>
                <li><strong>Mobile money:</strong> Yo! Uganda Limited, which processes rent, subscription and payout transactions with the mobile networks.</li>
                <li><strong>SMS and email:</strong> the providers that deliver our text messages and emails.</li>
                <li><strong>WhatsApp:</strong> Meta Platforms, which runs WhatsApp (see section 6).</li>
            </ul>
            <p>Within Primora, a tenant’s details are visible only to their own landlord and the landlord’s team. Each landlord’s data is kept separate, and no landlord can see another landlord’s properties or tenants.</p>
            <p>We may also disclose information if the law requires it, for example to comply with a court order, or to protect people’s safety or our legal rights.</p>

            <h2 id="whatsapp">6. Messages: SMS, email and WhatsApp</h2>
            <p>Landlords can have Primora send tenants invoices, receipts and reminders by SMS and email. Tenants can also use Primora’s WhatsApp number to check their balance, receive invoices, pay rent and report issues.</p>
            <ul class="bullets">
                <li>When you message Primora on WhatsApp, we receive your WhatsApp number, your WhatsApp profile name and your messages. We use your number to find your tenancy, and we keep a record of the conversation so we can answer you and resolve problems.</li>
                <li>If we don’t recognise your number, we ask for your unit ID and send a one-time code to the phone number or email address your landlord has on record, to confirm it’s you.</li>
                <li>WhatsApp is provided by Meta. Its own <a href="https://www.whatsapp.com/legal/privacy-policy" target="_blank" rel="noopener">privacy policy</a> also applies to your use of WhatsApp.</li>
                <li>You can stop using the WhatsApp service at any time by simply not messaging us, and you can ask us to delete your WhatsApp conversation history (see section 11).</li>
            </ul>

            <h2 id="keep">7. How long we keep it</h2>
            <ul class="bullets">
                <li>Landlord accounts and their records: for as long as the account is open. When an account is closed we delete or anonymise its data within 90 days, except for records we must keep by law.</li>
                <li>Financial records (invoices, payments, payouts and subscription billing): for as long as tax and accounting law requires, generally at least five years.</li>
                <li>WhatsApp, SMS and email message records: while the landlord’s account is open, or until you ask us to delete your WhatsApp conversation history.</li>
                <li>One-time sign-in codes: until they expire, usually within minutes.</li>
                <li>Website enquiries: as long as needed to respond and follow up.</li>
            </ul>
            <p>Deleted data can remain in our backups for a limited period until those backups are replaced.</p>

            <h2 id="security">8. How we protect it</h2>
            <ul class="bullets">
                <li>All connections to Primora are encrypted (HTTPS).</li>
                <li>Passwords are stored in hashed form, and tenants sign in with one-time codes rather than passwords.</li>
                <li>Each landlord’s data is separated from every other landlord’s, and landlords control which team members can see what.</li>
                <li>Changes are recorded in an audit log, and payouts need approval by a second person.</li>
                <li>Data is backed up daily, and access to our servers is restricted to authorised staff.</li>
            </ul>
            <p>No system is perfectly secure. If a breach affects your personal data, we will notify you and the authorities as the law requires.</p>

            <h2 id="transfers">9. Where it is stored</h2>
            <p>Our servers and some of our service providers may be located outside Uganda. Where data is stored or processed abroad, we make sure it is protected to a standard at least equivalent to Ugandan law.</p>

            <h2 id="rights">10. Your rights</h2>
            <p>Under the Data Protection and Privacy Act, 2019, you have the right to:</p>
            <ul class="bullets">
                <li>ask whether we hold personal data about you and get a copy of it;</li>
                <li>have inaccurate data corrected;</li>
                <li>have your data deleted where we no longer have a lawful reason to keep it;</li>
                <li>object to or restrict how we use your data;</li>
                <li>complain to the Personal Data Protection Office (part of NITA-U) if you are unhappy with how we handle your data.</li>
            </ul>
            <p>To use any of these rights, email <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a>. We may need to confirm your identity first. We aim to respond within 30 days. For tenants, if the request is about information your landlord keeps, we will work with your landlord to answer it.</p>

            <h2 id="delete">11. Deleting your data</h2>
            <div class="callout">To ask us to delete your data, email <a href="mailto:<?= e($email) ?>?subject=Data%20deletion%20request"><?= e($email) ?></a> with the subject “Data deletion request”. Tell us whether you are a landlord or a tenant, and include the phone number or email address we know you by.</div>
            <ul class="bullets">
                <li><strong>Landlords:</strong> send the request from the email address on your Primora account. We will close the account and delete or anonymise its data within 90 days. You can export your records to CSV before you ask.</li>
                <li><strong>Tenants:</strong> your landlord can remove your details from their account. You can also ask us directly; because your records belong to your landlord, we will pass the request to them and confirm the outcome to you.</li>
                <li><strong>WhatsApp conversations:</strong> send the request from the WhatsApp number you used, or by email with that number. We will delete your WhatsApp conversation history within 30 days.</li>
            </ul>
            <p>We will confirm when your data has been deleted. Some records, such as invoices and payment records, may have to be kept for the period the law requires (see section 7), and we will tell you if that applies.</p>

            <h2 id="cookies">12. Cookies</h2>
            <p>The Primora app uses cookies only to keep you signed in and to protect forms against misuse. These are essential and are removed when you sign out or they expire. Our website uses a cookie only if you send the contact form, to show you whether it was sent. We don’t use advertising or tracking cookies. Our website loads one font from Google Fonts, which means Google receives your IP address when you visit.</p>

            <h2 id="children">13. Children</h2>
            <p>Primora is meant for adults managing or renting property. We do not knowingly collect data from children under 18 except as part of a tenant’s record kept by their landlord, such as an emergency contact.</p>

            <h2 id="changes">14. Changes to this policy</h2>
            <p>We may update this policy as Primora changes. We will change the date at the top, and tell landlords about significant changes by email or in the app.</p>

            <h2 id="contact">15. Contact us</h2>
            <p><?= e($company) ?><br><?= e($address) ?><br>Email: <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></p>
        </article>
    </div>
</main>

<footer class="site-footer" style="padding:28px 0;">
    <div class="container">
        <div class="footer-bottom" style="margin-top:0; padding-top:0; border-top:0;">
            <span>© <?= $year ?> Primora. All rights reserved. · <a href="./">Home</a> · <a href="<?= e($login) ?>">Sign in</a></span>
            <span>Made in Uganda</span>
        </div>
    </div>
</footer>
</body>
</html>
