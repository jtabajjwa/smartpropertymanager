<?php
$config = require __DIR__ . '/config.php';
require __DIR__ . '/lib/helpers.php';

if (!empty($config['staging'])) {
    header('X-Robots-Tag: noindex, nofollow');
}

$company = $config['company_name'] ?: 'Primora';
$email   = $config['support_email'] ?: $config['mail_to'];
$phone   = $config['support_phone'] ?? '';
$address = $config['address'];
// Keep in step with LEGAL_TERMS_VERSION in the app (config/legal.php): changing
// the version asks every landlord to accept the new terms at their next sign-in.
$updated = '2 November 2026';
$year    = date('Y');
$login   = rtrim($config['app_url'], '/') . '/login';

$sections = [
    'about'        => 'About These Terms',
    'service'      => 'The Primora Service',
    'account'      => 'Your Account',
    'billing'      => 'Trial, Subscription and Billing',
    'collections'  => 'Rent Collection and Payouts',
    'messages'     => 'SMS Credits and Messages to Tenants',
    'data'         => 'Your Data and Your Tenants’ Data',
    'support'      => 'Support Access to Your Account',
    'use'          => 'Acceptable Use',
    'landlord'     => 'Your Responsibilities as a Landlord',
    'availability' => 'Availability and Changes',
    'ip'           => 'Intellectual Property',
    'termination'  => 'Suspension',
    'closing'      => 'Closing Your Account',
    'disclaimers'  => 'Disclaimers',
    'liability'    => 'Limitation of Liability',
    'indemnity'    => 'Indemnity',
    'third'        => 'Third-Party Services',
    'changes'      => 'Changes to These Terms',
    'law'          => 'Governing Law and Disputes',
    'general'      => 'General',
    'contact'      => 'Contact Us',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Terms of Service — Primora</title>
    <meta name="description" content="The terms that apply when landlords and property managers use Primora, including billing, rent collection, payouts and data protection.">
    <meta name="theme-color" content="#1c2230">
<?php if (!empty($config['staging'])): ?>
    <meta name="robots" content="noindex, nofollow">
<?php endif; ?>
    <link rel="canonical" href="<?= e(rtrim($config['site_url'], '/')) ?>/terms.php">
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
            <h1>Terms of Service</h1>
            <p class="legal-meta">Last updated <?= e($updated) ?></p>

            <p class="legal-lead">These terms are the agreement between you and <?= e($company) ?> for using Primora, our property management software for landlords and property managers. Please read them carefully. They include our <a href="privacy.php">Privacy Policy</a>, which explains how we handle personal data.</p>

            <nav class="legal-toc" aria-label="Contents">
                <h2>Contents</h2>
                <ol>
                    <?php foreach ($sections as $id => $title): ?>
                        <li><a href="#<?= e($id) ?>"><?= e($title) ?></a></li>
                    <?php endforeach; ?>
                </ol>
            </nav>

            <h2 id="about">1. About These Terms</h2>
            <ul class="bullets">
                <li>Primora is provided by <?= e($company) ?>, <?= e($address) ?> (“Primora”, “we”, “us”).</li>
                <li>“You” means the landlord, property manager or company that opens a Primora account. If you sign up for an organisation, you confirm that you are allowed to accept these terms for it, and “you” means that organisation.</li>
                <li>By ticking the box when you sign up, or by using Primora, you accept these terms and the Privacy Policy. Accepting them online is as binding as signing a paper agreement.</li>
                <li>If you don’t agree with these terms, please don’t use Primora.</li>
            </ul>

            <h2 id="service">2. The Primora Service</h2>
            <p>Primora helps you manage rental property. Depending on your plan, it lets you:</p>
            <ul class="bullets">
                <li>record estates, units, tenants, customers and leases;</li>
                <li>raise rent invoices automatically, and send invoices, receipts and reminders by SMS and email;</li>
                <li>record payments, deposits, expenses, vendors and job cards, and keep books and reports;</li>
                <li>give your tenants a portal and a WhatsApp service where they can see their balance, pay rent and report issues;</li>
                <li>collect rent by mobile money and have it paid out to you (see section 5).</li>
            </ul>
            <p>The features of each plan are described on our website and in the app.</p>

            <h2 id="account">3. Your Account</h2>
            <ul class="bullets">
                <li>You must be at least 18 and able to enter into a binding contract.</li>
                <li>Give us accurate details and keep them up to date, including the email address and phone number we use to reach you.</li>
                <li>Keep your password safe and don’t share your sign-in. Tell us straight away if you think someone else has used your account.</li>
                <li>You can invite team members and choose what they can do. You are responsible for what they do in your account, and for removing their access when they leave.</li>
            </ul>

            <h2 id="billing">4. Trial, Subscription and Billing</h2>
            <ul class="bullets">
                <li><strong>Free trial.</strong> New accounts start with a free trial. Its length is shown when you sign up and in the app. You don’t need to pay anything during the trial.</li>
                <li><strong>Plans and prices.</strong> After the trial, you choose a plan. Prices are shown on our website and in the app, and are payable in advance for each billing period. Prices are in Uganda shillings and include any taxes we are required to charge, unless we say otherwise.</li>
                <li><strong>When a period ends unpaid.</strong> Everything keeps working for a short grace period, with a reminder in the app. After that, your account becomes read-only: you can still see and export all your records, pay for a plan and receive payouts, but you can’t add or change records or send SMS. Your tenants can still use the portal and pay rent. Paying for a plan restores full access straight away. We never delete your records because a payment is late.</li>
                <li><strong>Price changes.</strong> We will give you at least 30 days’ notice of a price increase. It applies from your next billing period after the notice.</li>
                <li><strong>Refunds.</strong> Subscription fees are not refundable for unused parts of a billing period, except where the law requires it or we have failed to provide the service as these terms describe.</li>
            </ul>

            <h2 id="collections">5. Rent Collection and Payouts</h2>
            <p>Rent collection is optional. If you turn it on, these terms also apply:</p>
            <ul class="bullets">
                <li><strong>How it works.</strong> Tenants pay by mobile money through the tenant portal, WhatsApp or our USSD service. The payments are processed by Yo! Uganda Limited, a licensed payment service provider, and received into Primora’s collection account. We record each payment in your account automatically and hold the money on your behalf until it is paid out to you.</li>
                <li><strong>Collection fees.</strong> A fee applies to each collected payment, in bands shown in the app. By default you bear the fee and it is deducted from what we pay out to you. You can choose instead to pass the fee to your tenants, who then pay it on top of the amount due.</li>
                <li><strong>Payouts.</strong> You request payouts in the app, to the mobile money numbers or bank accounts you nominate. Payouts need approval by a second person, and are made after we have confirmed the payments with the payment provider. Any charges for sending the payout are deducted from the amount paid out.</li>
                <li><strong>Checking your details.</strong> Check payout numbers and account details carefully. We can’t recover money paid to details you gave us, though we will help where we can.</li>
                <li><strong>Holding or reversing payments.</strong> We may delay a payout, or hold money, while we look into a suspected error, fraud, a disputed or reversed payment, or where the law, a court or a regulator requires it. If a payment we recorded for you is reversed or turns out not to have been received, we may deduct it from what we pay you.</li>
                <li><strong>What Primora isn’t.</strong> Primora is not a bank, and we don’t pay interest on money held for you. Collected rent belongs to you, not to Primora, and is paid out to you less the fees and charges described here.</li>
            </ul>

            <h2 id="messages">6. SMS Credits and Messages to Tenants</h2>
            <ul class="bullets">
                <li>SMS messages are paid for with prepaid SMS credits. New accounts get some free starter credits. Credits are used per message part sent, and are given back for messages that fail to send.</li>
                <li>Credits can’t be exchanged for cash or moved to another account, except where the law requires it.</li>
                <li>You are responsible for the content of messages you send and for having your tenants’ permission, or another lawful reason, to contact them. Primora is for messages about tenancies, such as invoices, receipts and reminders. Don’t use it for marketing or unsolicited messages.</li>
                <li>Our WhatsApp service is provided through Meta’s WhatsApp Business Platform, and WhatsApp’s own terms also apply to its use.</li>
            </ul>

            <h2 id="data">7. Your Data and Your Tenants’ Data</h2>
            <p>Our <a href="privacy.php">Privacy Policy</a> explains what personal data we handle and how. It forms part of these terms. In addition:</p>
            <ul class="bullets">
                <li><strong>Your records are yours.</strong> You own the information you put into Primora. You give us permission to store, process and display it only so that we can provide Primora to you and your tenants, keep it secure and meet our legal obligations.</li>
                <li><strong>Tenants’ personal data.</strong> For personal data about your tenants and other people you record, you are the data controller and Primora is your data processor under Uganda’s Data Protection and Privacy Act, 2019. You are responsible for having a lawful reason to collect it, for telling your tenants how it is used, and for only recording what you need.</li>
                <li><strong>What we promise as your processor.</strong> We will:
                    <ul class="bullets">
                        <li>process tenants’ data only to provide Primora to you, or as the law requires;</li>
                        <li>make sure people who work on Primora keep it confidential;</li>
                        <li>protect it with appropriate security measures (described in the Privacy Policy);</li>
                        <li>use only the service providers listed in the Privacy Policy, on terms that protect the data, and tell you about any new type of provider;</li>
                        <li>tell you without undue delay if we become aware of a breach affecting your data;</li>
                        <li>help you answer requests from tenants who want to use their data protection rights;</li>
                        <li>when your account closes, delete or return the data as set out in section 14.</li>
                    </ul>
                </li>
                <li><strong>Anonymous statistics.</strong> We may use information that doesn’t identify you, your tenants or your properties to measure and improve Primora. We never sell personal data.</li>
            </ul>

            <h2 id="support">8. Support Access to Your Account</h2>
            <p>Authorised Primora staff can open your account in a supervised “staff mode” to help you when you ask for support, to investigate a problem or a payment, to keep Primora secure, or where the law requires it. Staff access is limited to what each person’s role needs, and their actions in your account are recorded in your audit log.</p>

            <h2 id="use">9. Acceptable Use</h2>
            <p>You agree not to:</p>
            <ul class="bullets">
                <li>break the law using Primora, including tenancy, data protection, tax, consumer protection and anti-money laundering laws;</li>
                <li>record information you have no right to record, or that is false or misleading;</li>
                <li>harass or threaten tenants, or use Primora to send spam;</li>
                <li>use Primora to receive money that isn’t genuinely owed to you for property you manage;</li>
                <li>try to get into accounts or data that aren’t yours, test or break our security, or disrupt the service;</li>
                <li>copy, resell or reverse-engineer Primora, or use automated tools to extract data from it, except through exports and features we provide;</li>
                <li>upload viruses or other harmful code.</li>
            </ul>

            <h2 id="landlord">10. Your Responsibilities as a Landlord</h2>
            <ul class="bullets">
                <li>You are responsible for your tenancies and your dealings with tenants, including meeting the Landlord and Tenant Act, 2022 and other laws that apply to you.</li>
                <li>Check the invoices, receipts, statements and reports Primora produces. Primora calculates them from what you record and the settings you choose.</li>
                <li>The lease templates and tax templates in Primora are a starting point, not legal or tax advice. Have them checked by a professional where needed.</li>
                <li>You remain responsible for your own taxes, such as rental income tax.</li>
            </ul>

            <h2 id="availability">11. Availability and Changes</h2>
            <ul class="bullets">
                <li>We work to keep Primora available and reliable, and back up your data daily. We can’t promise it will always be uninterrupted or error-free, for example during maintenance or when mobile networks, payment providers or hosting providers have problems.</li>
                <li>We improve Primora all the time and may change, add or remove features. If we remove a significant feature you pay for, we will tell you in advance.</li>
            </ul>

            <h2 id="ip">12. Intellectual Property</h2>
            <p>Primora, including its software, design, name and logo, belongs to us or our licensors. These terms give you the right to use Primora while your account is open; they don’t transfer any ownership to you. If you send us feedback or ideas, we may use them without any obligation to you.</p>

            <h2 id="termination">13. Suspension</h2>
            <p>We may suspend your account, or part of it, if you seriously or repeatedly break these terms, if we reasonably suspect fraud or misuse, to protect your tenants, other users or Primora, or where the law requires it. Where we can, we will tell you first and give you a chance to put things right. Unpaid subscriptions are handled as described in section 4, not by suspension.</p>

            <h2 id="closing">14. Closing Your Account</h2>
            <ul class="bullets">
                <li>You can close your account at any time by emailing <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a> from the email address on your account.</li>
                <li>We may close your account with 30 days’ notice, or straight away if you seriously break these terms.</li>
                <li>Before your account closes, you can export your records. We pay out any rent we still hold for you, less fees and charges, once the payments have been confirmed.</li>
                <li>We then delete or anonymise your account’s data within 90 days, except for records we must keep by law, as described in the Privacy Policy.</li>
                <li>Sections of these terms that by their nature should continue after closing (such as payment obligations, limits of liability and governing law) continue to apply.</li>
            </ul>

            <h2 id="disclaimers">15. Disclaimers</h2>
            <p>Apart from what these terms say, Primora is provided “as is”. To the extent the law allows, we don’t give other promises, for example that Primora will meet every need of your business. Nothing in these terms removes rights you have under laws that can’t be excluded.</p>

            <h2 id="liability">16. Limitation of Liability</h2>
            <ul class="bullets">
                <li>We are not liable for indirect or consequential losses, such as lost profits, lost rent, lost business or lost opportunities.</li>
                <li>We are not liable for losses caused by things outside our reasonable control, such as outages of mobile networks, payment providers, hosting or the internet, or by information you or your team entered incorrectly.</li>
                <li>Our total liability to you for all claims in any 12-month period is limited to the subscription fees you paid us in that period.</li>
                <li>None of this limits our duty to pay out rent we have collected and hold for you, or our liability for fraud, or for anything else the law doesn’t allow us to limit.</li>
            </ul>

            <h2 id="indemnity">17. Indemnity</h2>
            <p>You agree to cover our reasonable losses and costs from claims by your tenants or other third parties that arise because you broke these terms or the law, for example by recording personal data without a lawful reason or sending messages you weren’t allowed to send.</p>

            <h2 id="third">18. Third-Party Services</h2>
            <p>Primora works with services run by other companies, including Yo! Uganda Limited and the mobile networks for payments, SMS and email providers, Meta for WhatsApp, and map services for estate locations. Their own terms apply to their services, and we are not responsible for how they run them.</p>

            <h2 id="changes">19. Changes to These Terms</h2>
            <p>We may update these terms as Primora and the law change. We will change the date at the top. For changes that matter, we will give you at least 30 days’ notice by email or in the app, and ask you to accept the new terms when you next sign in. If you don’t agree to the new terms, you can close your account before they take effect.</p>

            <h2 id="law">20. Governing Law and Disputes</h2>
            <p>These terms are governed by the laws of the Republic of Uganda. If you have a complaint, please contact us first: most problems can be solved quickly that way. If we can’t resolve a dispute within 30 days, either of us may refer it to mediation in Kampala, and if that doesn’t resolve it, to the courts of Uganda.</p>

            <h2 id="general">21. General</h2>
            <ul class="bullets">
                <li>These terms and the Privacy Policy are the whole agreement between you and us about Primora, together with any plan details shown when you subscribe.</li>
                <li>If a court finds part of these terms invalid, the rest still applies.</li>
                <li>If we don’t enforce a right straight away, we can still enforce it later.</li>
                <li>You can’t transfer your account or these terms to someone else without our written agreement. We may transfer them to a company that takes over Primora, and will tell you if we do.</li>
                <li>We send notices to the email address on your account or show them in the app. You can send notices to <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a>.</li>
                <li>Neither of us is responsible for delays caused by events beyond reasonable control, such as natural disasters, network failures or government action.</li>
            </ul>

            <h2 id="contact">22. Contact Us</h2>
            <p><?= e($company) ?><br><?= e($address) ?><br>Email: <a href="mailto:<?= e($email) ?>"><?= e($email) ?></a><?php if ($phone): ?><br>Phone: <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $phone)) ?>"><?= e($phone) ?></a><?php endif; ?></p>
        </article>
    </div>
</main>

<footer class="site-footer" style="padding:28px 0;">
    <div class="container">
        <div class="footer-bottom" style="margin-top:0; padding-top:0; border-top:0;">
            <span>© <?= $year ?> Primora. All rights reserved. · <a href="./">Home</a> · <a href="privacy.php">Privacy</a> · <a href="<?= e($login) ?>">Sign in</a></span>
            <span>Made in Uganda</span>
        </div>
    </div>
</footer>
</body>
</html>
