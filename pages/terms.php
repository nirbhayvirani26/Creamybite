<?php
// ============================================================
//  Creamy Bite – Terms & Conditions
//  Currency, minimum order and VAT are read from config.php so this page
//  can never contradict what checkout actually charges.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/config.php';

$min   = number_format(MIN_DELIVERY_ORDER, 2);
$vat   = (int)(TRADE_VAT_RATE * 100);
$freeM = rtrim(rtrim(number_format(FREE_DELIVERY_MILES, 1), '0'), '.');
$maxM  = rtrim(rtrim(number_format(DELIVERY_RADIUS_MILES, 1), '0'), '.');
$chg   = number_format(DELIVERY_CHARGE, 2);
$shop  = htmlspecialchars(SHOP_NAME);
$email = htmlspecialchars(SHOP_EMAIL);
$phone = htmlspecialchars(SHOP_PHONE);

$policyTitle = 'Terms & Conditions';
$policyIntro = 'The terms you agree to when you order from us.';
$policyBody  = <<<HTML
<h2>Who you are buying from</h2>
<p>
    {$shop}, Unit E5, Phoenix Business Centre, Harrow HA1 2SP.
    <a href="mailto:{$email}">{$email}</a> &middot;
    <a href="tel:{$phone}">{$phone}</a>
</p>

<h2>Prices and currency</h2>
<p>
    All prices on this site are in <strong>pounds sterling (GBP, &pound;)</strong>.
    Retail prices include VAT where it applies. We may change prices at any time,
    but never after you have placed an order — you pay the price shown when you
    ordered.
</p>

<h2>Placing an order</h2>
<p>
    Your order is an offer to buy. A contract is formed when we confirm it. We
    may decline an order if an item is out of stock, the address is outside our
    delivery area, or we cannot take payment — if we do, you are not charged, and
    if you have already paid we refund you in full.
</p>

<h2>Delivery</h2>
<ul>
    <li>We deliver within {$maxM} miles of HA1 2SP. Outside that, choose collection.</li>
    <li>Delivery orders have a minimum of <strong>&pound;{$min}</strong>. Collection has no minimum.</li>
    <li>Delivery is free within {$freeM} miles and &pound;{$chg} between {$freeM} and {$maxM} miles.</li>
    <li>We will agree a time with you. Frozen goods are not left unattended.</li>
</ul>
<p>
    Delivery times are our best estimate, not a guarantee. Where a delay is our
    fault and the products are unusable as a result, see
    <a href="returns.php">Returns &amp; Refunds</a>.
</p>

<h2>Payment</h2>
<p>
    You can pay by card at checkout, or on delivery or collection. Card payments
    are processed by Stripe; we never see your card number. Orders marked
    "pay later" must be paid on receipt.
</p>

<h2>Trade accounts</h2>
<ul>
    <li>Trade accounts are subject to approval and may be withdrawn.</li>
    <li>Trade prices are confidential and for your business only.</li>
    <li>VAT at {$vat}% is added where you have supplied a VAT number.</li>
    <li>Invoices are due on the terms shown on the invoice, three weeks by default.</li>
    <li>Trade orders are not charged a per-delivery fee.</li>
</ul>

<h2>Allergens</h2>
<p>
    Our products contain <strong>milk</strong>. Several contain <strong>nuts</strong>,
    and all are made in a kitchen where nuts are handled — so we cannot guarantee
    any product is free from traces. Items containing nuts are marked on the menu.
</p>
<p>
    If you have a food allergy, please call us before ordering rather than
    relying on the website alone.
</p>

<h2>Promo codes</h2>
<p>
    One code per order. Codes may carry a minimum spend or an expiry date, cannot
    be exchanged for cash, and may be withdrawn at any time. Promo codes do not
    apply to trade accounts, which already buy at wholesale prices.
</p>

<h2>Cancellation</h2>
<p>
    Because our products are perishable, the usual 14-day right to cancel does
    not apply. This does not affect your rights if something is wrong with the
    order — see <a href="returns.php">Returns &amp; Refunds</a>.
</p>

<h2>Liability</h2>
<p>
    We do not limit our liability for death or personal injury caused by our
    negligence, for fraud, or for anything else that cannot be limited by law.
    Otherwise our liability for an order is limited to what you paid for it.
</p>

<h2>Governing law</h2>
<p>
    These terms are governed by the law of England and Wales, and disputes fall
    to the courts of England and Wales.
</p>
HTML;

require __DIR__ . '/../includes/policy_page.php';
