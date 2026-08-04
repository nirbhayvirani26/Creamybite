<?php
// ============================================================
//  Creamy Bite – Shipping & Delivery
//  Figures come from config.php so this page cannot contradict checkout.
// ============================================================
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../includes/config.php';

$min  = number_format(MIN_DELIVERY_ORDER, 2);
$vat  = (int)(TRADE_VAT_RATE * 100);

// "6" rather than "6.0" — these read as prose, not as figures in a table.
$freeM = rtrim(rtrim(number_format(FREE_DELIVERY_MILES, 1), '0'), '.');
$maxM  = rtrim(rtrim(number_format(DELIVERY_RADIUS_MILES, 1), '0'), '.');
$chg   = number_format(DELIVERY_CHARGE, 2);

$policyTitle = 'Shipping & Delivery';
$policyIntro = 'Where we deliver, what it costs, and when to expect your order.';
$policyBody  = <<<HTML
<h2>Where we deliver</h2>
<p>
    We deliver within a <strong>{$maxM} mile radius</strong> of our Harrow warehouse
    (HA1 2SP). Enter your postcode at checkout and we will tell you the distance
    and the cost before you pay. If you are outside that radius the order cannot
    be placed for delivery — choose <strong>Warehouse Collection</strong>
    instead, or call us and we will see what we can arrange.
</p>

<h2>Delivery charges</h2>
<ul>
    <li><strong>Within {$freeM} miles</strong> — free</li>
    <li><strong>{$freeM} to {$maxM} miles</strong> — &pound;{$chg}</li>
    <li><strong>Over {$maxM} miles</strong> — we cannot deliver</li>
    <li><strong>Collection</strong> — free, with no minimum order</li>
</ul>

<h2>Minimum order</h2>
<p>
    Deliveries have a minimum order of <strong>&pound;{$min}</strong>. This covers
    the driver rather than the ice cream, which is why collection has no minimum
    at all. Your basket total is shown at checkout and tells you exactly how much
    more is needed.
</p>

<h2>Collection</h2>
<p>
    Unit E5, Phoenix Business Centre, Harrow HA1 2SP.<br>
    Collection times are <strong>11am to 8pm</strong>. Bring your order number.
</p>

<h2>When your order arrives</h2>
<p>
    We are an ice cream shop, so timing matters. We will contact you to confirm a
    delivery slot after you order — we do not leave frozen products on a doorstep.
    Someone needs to be there to take the delivery.
</p>
<p>
    If nobody is available when the driver arrives, we will bring the order back
    to the warehouse and call you to rearrange. We cannot leave it with a
    neighbour or in a safe place; it will not survive the wait.
</p>

<h2>Trade and wholesale</h2>
<p>
    Approved trade accounts are delivered to their registered address and are not
    charged per drop. VAT at {$vat}% is added for trade accounts that have given
    us a VAT number. Trade partners can also set opening hours and drop-off
    instructions at checkout so the driver knows where to go.
</p>

<h2>If something goes wrong</h2>
<p>
    Call us on the number below as soon as you can. Frozen products are
    time-sensitive and the sooner we know, the more we can do about it.
</p>
HTML;

require __DIR__ . '/../includes/policy_page.php';
