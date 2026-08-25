<?php
// ============================================================
//  Creamy Bite – Returns & Refunds
//
//  Perishable food is genuinely exempt from the usual 14-day right to
//  cancel, so this page says so plainly rather than copying a generic
//  returns policy that would promise something the shop cannot honour.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/config.php';

$phone = htmlspecialchars(SHOP_PHONE);
$email = htmlspecialchars(SHOP_EMAIL);

$policyTitle = 'Returns & Refunds';
$policyIntro = 'What happens if something is wrong with your order.';
$policyBody  = <<<HTML
<h2>Ice cream is different</h2>
<p>
    Under the Consumer Contracts Regulations, perishable food is exempt from the
    usual 14-day right to cancel. Once ice cream has left our freezer we cannot
    resell it, so we are not able to accept returns of the product itself.
</p>
<p>
    That is not the end of the matter. If something is wrong with your order we
    will put it right — the exemption is about returning tubs, not about you
    being stuck with a bad order.
</p>

<h2>We will refund or replace if</h2>
<ul>
    <li>An item arrived melted, damaged or in poor condition</li>
    <li>You received the wrong flavour or size</li>
    <li>Something was missing from your order</li>
    <li>We could not deliver and you no longer want the order</li>
    <li>You were charged the wrong amount</li>
</ul>

<h2>Tell us within 24 hours</h2>
<p>
    Call <a href="tel:{$phone}">{$phone}</a> or email
    <a href="mailto:{$email}">{$email}</a> with your order number
    (it starts with CB-) and, if you can, a photograph. A photo of a melted tub
    settles the question immediately and saves us both a conversation.
</p>
<p>
    We ask for 24 hours because frozen products cannot be assessed days later.
    If something has gone wrong we would rather hear straight away.
</p>

<h2>Cancelling before delivery</h2>
<p>
    If your order has not yet been prepared, call us and we will cancel it and
    refund you in full. Once an order has been made up and loaded for delivery we
    may not be able to cancel it, but talk to us — we would rather find a
    solution than have you out of pocket.
</p>

<h2>How refunds are paid</h2>
<ul>
    <li><strong>Paid by card</strong> — refunded to the same card, usually
        within 5 to 10 working days depending on your bank.</li>
    <li><strong>Pay on delivery or collection</strong> — if you have not paid
        yet, there is simply nothing to collect.</li>
    <li><strong>Trade accounts</strong> — credited against your account or
        applied to your next invoice, whichever you prefer.</li>
</ul>
<p>
    We refund what you actually paid, including the delivery charge when the
    fault was ours.
</p>

<h2>Your legal rights</h2>
<p>
    Nothing here affects your statutory rights under the Consumer Rights Act
    2015. Goods must be of satisfactory quality, fit for purpose and as
    described. If they are not, you are entitled to a remedy regardless of what
    any policy page says.
</p>
HTML;

require __DIR__ . '/../includes/policy_page.php';
