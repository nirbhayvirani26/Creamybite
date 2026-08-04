<?php
// ============================================================
//  Creamy Bite – Frequently Asked Questions
//
//  Every number on this page (minimum order, delivery radius, delivery
//  charge, VAT rate, order code prefix) is read from config.php rather than
//  typed out. An FAQ is the page a shop writes once and never revisits, so
//  the moment a figure is hardcoded here it starts quietly contradicting
//  checkout — and the customer believes the FAQ.
//
//  Answers use <details>/<summary>, which browsers render as a working
//  accordion with no JavaScript at all. That matters on a page people open
//  on a phone with a bad signal, and it means the answers are still readable
//  if a script fails to load.
//
//  Deliberately NOT answered here: whether a given flavour contains a
//  specific allergen. That question is answered from the product's own
//  allergen record, because a generic reassurance on an FAQ page is exactly
//  how someone with an allergy gets hurt.
// ============================================================
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../includes/config.php';

$min   = number_format(MIN_DELIVERY_ORDER, 2);
$vat   = (int)(TRADE_VAT_RATE * 100);
$freeM = rtrim(rtrim(number_format(FREE_DELIVERY_MILES, 1), '0'), '.');
$maxM  = rtrim(rtrim(number_format(DELIVERY_RADIUS_MILES, 1), '0'), '.');
$chg   = number_format(DELIVERY_CHARGE, 2);
$code  = ORDER_PREFIX;
$shop  = htmlspecialchars(SHOP_NAME);
$phone = htmlspecialchars(SHOP_PHONE);
$email = htmlspecialchars(SHOP_EMAIL);
$base  = SITE_BASE;

$policyTitle = 'Frequently Asked Questions';
$policyIntro = 'Ordering, delivery, collection, payment and trade accounts — answered.';
$policyBody  = <<<HTML
<p>
    If your question is not here, call <a href="tel:{$phone}">{$phone}</a> or email
    <a href="mailto:{$email}">{$email}</a>. A real person answers.
</p>

<h2>Ordering</h2>

<details class="cbfaq-item">
    <summary>Do I need an account to order?</summary>
    <p>
        No. Retail customers can order as a guest — just your name, phone number
        and address at checkout. Accounts exist for <strong>trade customers</strong>,
        who get wholesale pricing, saved details and invoices.
    </p>
</details>

<details class="cbfaq-item">
    <summary>Where do I find my order number?</summary>
    <p>
        It is on the confirmation screen and in your confirmation email, and it
        always begins with <strong>{$code}-</strong> followed by six digits.
        Quote it whenever you contact us and we can find your order immediately.
    </p>
</details>

<details class="cbfaq-item">
    <summary>Can I change or cancel my order after placing it?</summary>
    <p>
        Call us as soon as you can. If the order has not been made up yet we can
        change or cancel it and refund you in full. Once it has been packed and
        loaded we may not be able to — but talk to us rather than assuming.
        See <a href="{$base}/pages/returns.php">Returns &amp; Refunds</a>.
    </p>
</details>

<details class="cbfaq-item">
    <summary>Is there a minimum order?</summary>
    <p>
        For <strong>delivery</strong>, yes — &pound;{$min}. That covers the driver,
        not the ice cream. For <strong>collection</strong> there is no minimum at
        all, so a single tub is fine if you are coming to us.
    </p>
</details>

<h2>Delivery &amp; collection</h2>

<details class="cbfaq-item">
    <summary>Where do you deliver, and what does it cost?</summary>
    <ul>
        <li><strong>Within {$freeM} miles</strong> of HA1 2SP — free</li>
        <li><strong>{$freeM} to {$maxM} miles</strong> — &pound;{$chg}</li>
        <li><strong>Beyond {$maxM} miles</strong> — we cannot deliver, but you are
            very welcome to collect</li>
    </ul>
    <p>
        Enter your postcode at checkout and the exact distance and charge appear
        before you pay. Full detail on the
        <a href="{$base}/pages/shipping.php">Shipping &amp; Delivery</a> page.
    </p>
</details>

<details class="cbfaq-item">
    <summary>Why can't you deliver to me?</summary>
    <p>
        Ice cream has a very short window outside a freezer. Past about
        {$maxM} miles we cannot promise it arrives in the condition it left in,
        and we would rather decline the order than send you something soft.
        Collection is available to everyone regardless of distance.
    </p>
</details>

<details class="cbfaq-item">
    <summary>Where do I collect from?</summary>
    <p>
        Unit E5, Phoenix Business Centre, Harrow HA1 2SP. Choose
        <strong>Warehouse Collection</strong> at checkout and we will confirm a
        time. Please do not travel until you have that confirmation — we make
        orders up to order.
    </p>
</details>

<details class="cbfaq-item">
    <summary>Will my ice cream melt on the way?</summary>
    <p>
        We deliver frozen and we do not leave frozen goods unattended, so
        someone needs to be there to take the order in. Put it in your freezer
        straight away. If something does arrive soft, tell us within 24 hours and
        we will put it right.
    </p>
</details>

<h2>Payment</h2>

<details class="cbfaq-item">
    <summary>How can I pay?</summary>
    <p>
        By card online, or choose <strong>Pay Later</strong> and settle on
        delivery or collection. Card payments are handled by Stripe — the card
        details go straight to them and never touch our server.
    </p>
</details>

<details class="cbfaq-item">
    <summary>When am I charged?</summary>
    <p>
        If you pay by card, at the moment you place the order. If you choose Pay
        Later, when you receive it. Trade accounts are invoiced.
    </p>
</details>

<details class="cbfaq-item">
    <summary>Do your prices include VAT?</summary>
    <p>
        Retail prices on the site are the price you pay. Trade customers with a
        VAT number on their account are charged {$vat}% VAT, shown as a separate
        line on the invoice.
    </p>
</details>

<h2>Allergens &amp; storage</h2>

<details class="cbfaq-item">
    <summary>Which flavours contain nuts or other allergens?</summary>
    <p>
        Allergen information is listed per product, because it genuinely differs
        flavour by flavour and a general answer here would be worse than none.
        See the <a href="{$base}/pages/allergens.php">allergen &amp; nutrition
        information</a> for the current list.
    </p>
    <p>
        <strong>If you have a serious allergy, please call us on
        <a href="tel:{$phone}">{$phone}</a> before ordering.</strong> Everything
        we make is produced in one kitchen that handles nuts and milk, so we
        cannot guarantee any flavour is free from cross-contact.
    </p>
</details>

<details class="cbfaq-item">
    <summary>How should I store it, and how long does it keep?</summary>
    <p>
        Keep frozen at &minus;18&deg;C or colder and it will keep to the
        best-before date on the tub. Once it has thawed, do not refreeze it —
        eat it within a day and keep it in the fridge until you do.
    </p>
</details>

<h2>Trade accounts</h2>

<details class="cbfaq-item">
    <summary>How do I open a trade account?</summary>
    <p>
        Apply on the <a href="{$base}/pages/trade_register.php">trade
        registration</a> page. We will review it and get in touch. Once approved
        you will see wholesale pricing when you log in, and you can order in case
        quantities.
    </p>
</details>

<details class="cbfaq-item">
    <summary>What do I get with a trade account?</summary>
    <ul>
        <li>Wholesale pricing across the range</li>
        <li>Case quantities and a downloadable
            <a href="{$base}/pages/catalogue.php">product catalogue</a></li>
        <li>Proper VAT invoices, with your details saved</li>
        <li>Order history you can reorder from</li>
        <li>Collection from the warehouse at no charge</li>
    </ul>
</details>

<details class="cbfaq-item">
    <summary>Can I get a copy of an old invoice?</summary>
    <p>
        Yes — they are all in your account under
        <a href="{$base}/pages/trade_profile.php">your profile</a>, and you can
        open or print any of them. If one is missing, email
        <a href="mailto:{$email}">{$email}</a> and we will send it over.
    </p>
</details>
HTML;

require __DIR__ . '/../includes/policy_page.php';
