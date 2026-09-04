<?php
// ============================================================
//  Creamy Bite – Cookie Policy
//
//  Written from what this site actually does, not from a template. Audited
//  before writing: there is no Google Analytics, no Meta pixel, no Hotjar,
//  no advertising tag and no setcookie() call anywhere in the codebase.
//  What remains is the PHP session cookie, whatever Stripe sets on its own
//  payment frames, and the Google Maps embed on the contact section.
//
//  That distinction is the whole point. A copied cookie policy would list
//  analytics and advertising cookies this site has never set, which is both
//  untrue and a reason a regulator would look harder. It also explains why
//  there is no consent banner: strictly necessary cookies do not need
//  consent under PECR, and until an analytics tag is added, none of these
//  are anything else.
//
//  SINCE THEN, one thing has been added and it is described below: the shop
//  keeps its own server-side visit log (includes/traffic.php). It is not a
//  cookie and it is not a third party — nothing is stored in or read from
//  the visitor's browser, which is what PECR regulation 6 actually governs,
//  so it does not turn a consent banner on. It IS personal data under UK
//  GDPR because it holds IP addresses, which is why it is disclosed here and
//  in the privacy policy rather than left unmentioned.
//
//  If a THIRD-PARTY analytics or remarketing tag is ever added, that is a
//  different thing again: this page needs updating AND a consent banner
//  becomes a legal requirement — called out below so it is not discovered
//  later.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/config.php';

$shop  = htmlspecialchars(SHOP_NAME);
$email = htmlspecialchars(SHOP_EMAIL);
$phone = htmlspecialchars(SHOP_PHONE);
$base  = SITE_BASE;

$policyTitle = 'Cookie Policy';
$policyIntro = 'The small number of cookies this site uses, and why each one is here.';
$policyBody  = <<<HTML
<h2>The short version</h2>
<p>
    {$shop} does not track you across the web. We run <strong>no third-party
    analytics, no advertising cookies and no social media pixels</strong>. The
    only cookies this site sets are the ones needed to keep your basket working
    and to take a payment securely.
</p>
<p>
    We do keep a simple record of which pages are opened on our own server —
    no cookie, no outside company involved. It is described in
    <a href="#visit-log">our own visit record</a> below.
</p>
<p>
    That is why you are not being shown a cookie consent banner. Under the
    Privacy and Electronic Communications Regulations, cookies that are strictly
    necessary for a service you asked for do not require consent — and at
    present, every cookie here is one of those.
</p>

<h2>What a cookie is</h2>
<p>
    A small text file a website stores in your browser so it can recognise your
    visit from one page to the next. Without one, a shopping basket would empty
    itself every time you clicked a link.
</p>

<h2>Cookies we set</h2>
<table class="cbpol-table">
    <thead>
        <tr>
            <th>Cookie</th>
            <th>Purpose</th>
            <th>How long it lasts</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><code>PHPSESSID</code></td>
            <td>
                Keeps your session together — your basket contents, your promo
                code, and whether you are signed in to a trade account. The site
                cannot function without it.
            </td>
            <td>Until you close your browser</td>
        </tr>
    </tbody>
</table>
<p>
    That is the entire list. We do not store your name, address or card details
    in a cookie — order details go in our database once you place an order, and
    card details never reach our server at all.
</p>

<h2>Cookies set by others</h2>
<p>
    Two third parties can set their own cookies on pages where their content
    appears. We do not control these and cannot read them.
</p>
<table class="cbpol-table">
    <thead>
        <tr>
            <th>Who</th>
            <th>Where it appears</th>
            <th>Why</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><strong>Stripe</strong></td>
            <td>The checkout page, when you choose to pay by card</td>
            <td>
                Processes the payment and detects fraud. Stripe is how your card
                details stay off our systems entirely. See the
                <a href="https://stripe.com/gb/privacy" target="_blank" rel="noopener noreferrer">Stripe privacy policy</a>.
            </td>
        </tr>
        <tr>
            <td><strong>Google Maps</strong></td>
            <td>The map showing our Harrow location</td>
            <td>
                Loads the embedded map. Google may set cookies when the map
                appears. See the
                <a href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer">Google privacy policy</a>.
            </td>
        </tr>
    </tbody>
</table>

<h2 id="visit-log">Our own visit record</h2>
<p>
    Our server keeps a short log of page requests so we can see how many people
    are reaching the shop and which pages are useful. For each page opened it
    records the time, the page address, the site you followed a link from (the
    site only — never the full link), your IP address, and what your browser
    reports about itself.
</p>
<p>
    <strong>This is not a cookie and it is not analytics software.</strong>
    Nothing is stored in or read from your browser, no third party is involved,
    and none of it leaves our server. It cannot follow you to other websites,
    and it is not linked to any advertising profile. That is also why it does
    not require a consent banner: consent rules for cookies apply to storing
    things on your device, and this stores nothing there.
</p>
<p>
    An IP address is still personal information under UK GDPR, so we treat it
    as such: we keep these records for <strong>90 days</strong> and then delete
    them automatically. We rely on our legitimate interest in understanding and
    protecting our own website. If you would rather we did not hold yours, email
    <a href="mailto:{$email}">{$email}</a> and we will remove it.
</p>

<h2>Turning cookies off</h2>
<p>
    Every browser lets you block or delete cookies — usually under Settings,
    then Privacy. You should know what happens if you block ours: the basket
    stops remembering what you added, trade sign-in stops working, and checkout
    cannot complete. Nothing breaks permanently, but you will not be able to
    place an order.
</p>
<p>
    Blocking only third-party cookies is gentler. The site keeps working;
    the embedded map may not load.
</p>

<h2>If this changes</h2>
<p>
    If we ever add third-party analytics or advertising, this page will be
    updated before those cookies are switched on, and you will be asked for
    consent first. We are not going to start measuring you quietly — which is
    why our own visit record is written down above rather than left out.
</p>

<h2>Related</h2>
<p>
    Our <a href="{$base}/privacy">Privacy Policy</a> covers the
    information we hold about you and what we do with it. Questions about either
    are welcome — <a href="mailto:{$email}">{$email}</a> or
    <a href="tel:{$phone}">{$phone}</a>.
</p>
HTML;

require __DIR__ . '/../includes/policy_page.php';
