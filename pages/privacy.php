<?php
// ============================================================
//  Creamy Bite – Privacy Policy
//
//  Describes what the site ACTUALLY stores, checked against the schema:
//  orders, trade_users, inquiries, page_views. Claiming less than is stored
//  would be worse than saying nothing — which is why page_views is listed
//  here the moment includes/traffic.php began writing to it, rather than
//  being treated as too minor to mention because it is only an IP address.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/config.php';

$email = htmlspecialchars(SHOP_EMAIL);
$phone = htmlspecialchars(SHOP_PHONE);
$shop  = htmlspecialchars(SHOP_NAME);
// Needed by the links into the cookie policy below. Without it the heredoc
// interpolates an undefined variable and the links resolve to "/pages/…",
// which is only correct when the shop is the document root — it is not on
// MAMP, where the project sits in a subfolder.
$base  = SITE_BASE;

$policyTitle = 'Privacy Policy';
$policyIntro = 'What we collect, why we hold it, and what you can ask us to do with it.';
$policyBody  = <<<HTML
<h2>Who we are</h2>
<p>
    {$shop}, Unit E5, Phoenix Business Centre, Harrow HA1 2SP. For anything on
    this page, contact <a href="mailto:{$email}">{$email}</a> or
    <a href="tel:{$phone}">{$phone}</a>.
</p>

<h2>What we collect</h2>
<p><strong>When you place an order:</strong></p>
<ul>
    <li>Your name, email address and phone number</li>
    <li>Your delivery address and postcode</li>
    <li>What you ordered and what you paid</li>
    <li>Any notes or delivery instructions you give us</li>
</ul>
<p><strong>If you open a trade account:</strong></p>
<ul>
    <li>Your business name and contact name</li>
    <li>Business address, postcode and phone number</li>
    <li>Your VAT number, if you give us one</li>
</ul>
<p><strong>If you send us a message:</strong> your name, email, phone and the message itself.</p>
<p><strong>Whenever a page is opened,</strong> our own server records the time, the
page address, the site you followed a link from, your IP address, and what your
browser reports about itself. This is a log on our server, not a cookie and not
an analytics service — see <a href="{$base}/pages/cookies.php#visit-log">our own
visit record</a> for what it does and does not do.</p>

<h2>Card details</h2>
<p>
    We never see or store your card number. Card payments are handled entirely by
    <strong>Stripe</strong>, who are PCI-DSS certified. Your card details go
    straight from your browser to Stripe and never touch our server. We only
    receive confirmation that a payment succeeded.
</p>

<h2>Your postcode</h2>
<p>
    When you enter a postcode at checkout we send it to
    <strong>postcodes.io</strong> to work out how far away you are. That is the
    only thing sent, and it is not stored by us beyond the order itself.
</p>

<h2>Why we hold it</h2>
<ul>
    <li><strong>To fulfil your order</strong> — we cannot deliver without an
        address, or contact you about it without a phone number.</li>
    <li><strong>Because the law requires it</strong> — HMRC requires sales
        records to be kept for six years.</li>
    <li><strong>To run trade accounts</strong> — pricing, invoicing and
        approving applications.</li>
    <li><strong>To keep the website working and safe</strong> — the visit log
        tells us how many people are reaching the shop, which pages are worth
        keeping, and when something is hammering the site. This one rests on
        our legitimate interest rather than your order.</li>
</ul>
<p>
    We do not sell your data, and we do not send marketing email unless you
    have asked for it.
</p>

<h2>How long we keep it</h2>
<ul>
    <li><strong>Orders and invoices</strong> — six years, as required for tax.</li>
    <li><strong>Trade accounts</strong> — while the account is open, then six
        years for the invoicing record.</li>
    <li><strong>Enquiries</strong> — until dealt with, then cleared periodically.</li>
    <li><strong>Visit records</strong> — 90 days, then deleted automatically.</li>
</ul>

<h2>Your rights</h2>
<p>Under UK GDPR you can ask us to:</p>
<ul>
    <li>Show you what we hold about you</li>
    <li>Correct anything that is wrong</li>
    <li>Delete it, where we are not legally required to keep it</li>
    <li>Provide it in a portable form</li>
    <li>Stop using it for a particular purpose</li>
</ul>
<p>
    Email <a href="mailto:{$email}">{$email}</a> and we will respond within one
    month. There is no charge.
</p>
<p>
    Please note the six-year tax retention: we cannot delete a completed order
    even if you ask, because we are required to keep it. We can delete a trade
    account and the contact details attached to it.
</p>

<h2>Cookies</h2>
<p>
    This site uses one cookie: a session cookie that remembers what is in your
    basket and keeps you logged in to a trade account. It disappears when you
    close your browser. We do not use advertising or tracking cookies, and no
    analytics company is given access to this site. Our
    <a href="{$base}/pages/cookies.php">Cookie Policy</a> lists every one in
    full, along with the server-side visit record described above.
</p>

<h2>Complaints</h2>
<p>
    If you are unhappy with how we have handled your data, please tell us first.
    You also have the right to complain to the Information Commissioner's Office
    at <a href="https://ico.org.uk" target="_blank" rel="noopener">ico.org.uk</a>.
</p>
HTML;

require __DIR__ . '/../includes/policy_page.php';
