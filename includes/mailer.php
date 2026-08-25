<?php

// Port decides the encryption, rather than it being hard-coded.
//
// 465 is implicit SSL (SMTPS): the connection is encrypted from the first byte.
// 587 is STARTTLS: it opens in the clear and upgrades. Sending STARTTLS at a
// 465 listener does not degrade gracefully — it hangs and then fails, which is
// what happened when the mail host moved from Gmail on 587 to Hostinger on 465
// while this file still said STARTTLS in all four places.
if (!function_exists('cbMailEncryption')) {
    function cbMailEncryption(): string
    {
        return ((int)SMTP_PORT === 465)
            ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    }
}

// SMTP_* / SHOP_* constants come from config.php. Required explicitly so
// this file works no matter which entry point pulled it in.
require_once __DIR__ . '/config.php';

// ─────────────────────────────────────────────────────────────
// PHPMailer – safe load (manual folder OR vendor fallback)
// ─────────────────────────────────────────────────────────────
$_phpmailerLoaded = false;
if (file_exists(__DIR__ . '/../PHPMailer/src/PHPMailer.php')) {
    require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/../PHPMailer/src/SMTP.php';
    require_once __DIR__ . '/../PHPMailer/src/Exception.php';
    $_phpmailerLoaded = true;
} elseif (file_exists(__DIR__ . '/../vendor/composer/autoload_real.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    $_phpmailerLoaded = class_exists('PHPMailer\PHPMailer\PHPMailer');
}
if (!$_phpmailerLoaded) {
    error_log('PHPMailer not found — emails disabled. Upload the /PHPMailer/ folder to your server.');
}
define('PHPMAILER_AVAILABLE', $_phpmailerLoaded);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * The SMTP settings every send shares — and, crucially, a time limit.
 *
 * PHPMailer defaults Timeout to 300 seconds. Checkout saves the order, then
 * sends the admin alert and the customer confirmation one after the other,
 * so an unreachable or slow mail host meant the customer's browser sat on a
 * dead request for up to ten minutes after their order was already in the
 * database and their card already charged. In practice PHP's own
 * max_execution_time killed the script first — which is worse, because it
 * dies BEFORE the redirect to the confirmation page: the customer gets a
 * blank page or a 500, has no order number, assumes it failed, and orders
 * again. Blocked outbound SMTP is a common shared-hosting default, so this
 * is not a rare edge case.
 *
 * Ten seconds is far longer than a healthy send needs and short enough that
 * both emails together cannot exhaust a 30-second limit. A send that times
 * out is caught by the caller and logged; the order still completes and the
 * customer still reaches their confirmation, which is the right trade —
 * losing an email is recoverable, losing the confirmation is not.
 */
function cbMailTransport(PHPMailer $mail): void
{
    $mail->SMTPDebug  = 0;
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = cbMailEncryption();
    $mail->Port       = SMTP_PORT;
    $mail->Timeout    = 10;
    // Without this PHPMailer declares iso-8859-1 while sending UTF-8 bytes,
    // so every emoji and every £ arrives as mojibake.
    $mail->CharSet    = 'UTF-8';
}

// ============================================================
//  Creamy Bite – Mailer Helper
// ============================================================

function sendOrderEmail(array $order): bool
{
    if (!defined('PHPMAILER_AVAILABLE') || !PHPMAILER_AVAILABLE) return false;
    $adminEmail = ADMIN_EMAIL;
    $shopName   = SHOP_NAME;
    $siteUrl    = defined('SITE_URL') ? SITE_URL : '';

    $items = json_decode($order['items_json'], true) ?? [];

    // Payment status styling
    $ps = $order['payment_status'] ?? 'Unpaid';
    $pm = $order['payment_method'] ?? 'later';
    if ($ps === 'Paid') {
        $payIcon = '✅'; $payLabel = 'PAID ONLINE';
        $payDesc = 'Card / Apple Pay / Google Pay';
        $payBg = '#d1fae5'; $payBorder = '#10b981'; $payColor = '#065f46'; $payHdr = '#10b981';
    } elseif ($ps === 'Cash') {
        $payIcon = '💵'; $payLabel = 'CASH RECEIVED';
        $payDesc = 'Cash payment confirmed';
        $payBg = '#fef3c7'; $payBorder = '#f59e0b'; $payColor = '#78350f'; $payHdr = '#f59e0b';
    } else {
        $payIcon = '⏳'; $payLabel = ($pm === 'online') ? 'PAYMENT PENDING' : 'PAY LATER';
        $payDesc = ($pm === 'online') ? 'Online payment not confirmed yet' : 'Customer will pay on delivery';
        $payBg = '#fef9ec'; $payBorder = '#f59e0b'; $payColor = '#92400e'; $payHdr = '#f59e0b';
    }

    // Build item rows
    $itemRows    = '';
    $rawSubtotal = 0;
    foreach ($items as $item) {
        $lineTotal    = $item['price'] * $item['quantity'];
        $rawSubtotal += $lineTotal;
        $lineFmt      = number_format($lineTotal, 2);
        $priceFmt     = number_format($item['price'], 2);
        $variant      = !empty($item['variant_name'])
            ? "<div style='font-size:11px;color:#aaa;margin-top:2px;'>{$item['variant_name']}</div>" : '';
        $itemRows .= "
        <tr style='border-bottom:1px solid #f0e6ee;'>
            <td style='padding:12px 14px;font-size:24px;width:46px;'>{$item['emoji']}</td>
            <td style='padding:12px 8px;'>
                <div style='font-weight:700;color:#1a0a14;font-size:14px;'>{$item['name']}</div>
                {$variant}
                <div style='font-size:11px;color:#aaa;margin-top:3px;'>£{$priceFmt} each</div>
            </td>
            <td style='padding:12px 8px;text-align:center;'>
                <span style='background:#f0e6ee;color:#c44dff;font-weight:800;font-size:13px;padding:3px 11px;border-radius:20px;display:inline-block;'>{$item['quantity']}</span>
            </td>
            <td style='padding:12px 16px;text-align:right;font-weight:800;color:#ff6b9d;font-size:15px;'>£{$lineFmt}</td>
        </tr>";
    }

    $totalFmt = number_format((float)$order['total_price'], 2);
    $promoRow = '';
    if (!empty($order['promo_code']) && $order['discount_amount'] > 0) {
        $discFmt  = number_format((float)$order['discount_amount'], 2);
        $promoRow = "
        <tr style='border-bottom:1px solid #f0e6ee;'>
            <td colspan='3' style='padding:10px 14px;color:#666;font-size:13px;'>
                🎟️ Promo: <b>{$order['promo_code']}</b>
            </td>
            <td style='padding:10px 16px;text-align:right;color:#10b981;font-weight:800;'>−£{$discFmt}</td>
        </tr>";
    }

    $deliveryFmt = number_format((float)($order['delivery_charge'] ?? 0), 2);
    $deliveryRow = '';
    if ((float)($order['delivery_charge'] ?? 0) > 0) {
        $deliveryRow = "
        <tr style='border-bottom:1px solid #f0e6ee;'>
            <td colspan='3' style='padding:10px 14px;color:#f59e0b;font-size:13px;'>
                🚚 Delivery Charge
            </td>
            <td style='padding:10px 16px;text-align:right;color:#f59e0b;font-weight:800;'>+£{$deliveryFmt}</td>
        </tr>";
    }

    $date     = date('D d M Y • H:i', strtotime($order['created_at']));
    $code     = htmlspecialchars($order['order_code']);
    $name     = htmlspecialchars($order['customer_name']);
    $phone    = htmlspecialchars($order['phone']);
    $address  = nl2br(htmlspecialchars($order['address']));
    $isCollection = (strpos($order['address'], 'Collection') !== false);
    $addressLabel = $isCollection ? '📍 Collection Point' : '📍 Delivery Address';
    $notes    = !empty($order['notes']) ? nl2br(htmlspecialchars($order['notes'])) : '<em style="color:#bbb;">None</em>';
    $adminUrl = $siteUrl ? $siteUrl . '/admin/index.php?tab=orders' : '#';

    // Collection banner for admin email
    $collectionBanner = '';
    if ($isCollection) {
        $collectionBanner = "
  <!-- COLLECTION BANNER -->
  <tr><td style='padding:16px 24px 0;'>
    <div style='background:#fff7ed; border:2px solid #f59e0b; border-radius:12px; padding:18px 20px;'>
      <div style='font-size:11px; font-weight:700; color:#92400e; letter-spacing:1.5px; text-transform:uppercase; margin-bottom:6px;'>&#x1F3EA; Collection Order</div>
      <div style='font-size:15px; font-weight:800; color:#78350f; margin-bottom:4px;'>Customer will collect in person</div>
      <div style='font-size:13px; color:#92400e; line-height:1.7;'>
        &#x1F4CD; <strong>Creamy Bite</strong>, Unit E5 Phoenix Business Centre, HA1 2SP<br>
        &#x23F0; Collection Hours: <strong>11 AM &ndash; 8 PM</strong>
      </div>
      <div style='margin-top:12px;'>
        <a href='https://maps.app.goo.gl/hrMSnTRqFvorzF7HA?g_st=iw'
           style='display:inline-block; background:#f59e0b; color:#fff; font-size:12px; font-weight:700; padding:8px 18px; border-radius:20px; text-decoration:none; letter-spacing:0.5px;'>
          &#x1F5FA;&#xFE0F; Get Directions
        </a>
      </div>
    </div>
  </td></tr>";
    }

    $html = "<!DOCTYPE html>
<html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f4eff8;font-family:Arial,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f4eff8;padding:28px 0;'>
<tr><td align='center'>
<table width='600' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 8px 40px rgba(196,77,255,0.12);max-width:600px;'>

  <!-- HEADER -->
  <tr><td style='background:linear-gradient(135deg,#ff6b9d 0%,#c44dff 100%);padding:30px 28px;'>
    <table width='100%' cellpadding='0' cellspacing='0'><tr>
      <td>
        <div style='font-size:12px;color:rgba(255,255,255,0.7);letter-spacing:1px;text-transform:uppercase;margin-bottom:6px;'>🍦 {$shopName}</div>
        <div style='font-size:24px;font-weight:800;color:#fff;'>New Order Received!</div>
        <div style='font-size:12px;color:rgba(255,255,255,0.75);margin-top:5px;'>{$date}</div>
      </td>
      <td align='right' valign='middle'>
        <div style='background:rgba(255,255,255,0.15);border:2px solid rgba(255,255,255,0.35);border-radius:12px;padding:12px 16px;text-align:center;'>
          <div style='font-size:10px;color:rgba(255,255,255,0.7);letter-spacing:1.5px;margin-bottom:4px;'>ORDER REF</div>
          <div style='font-size:18px;font-weight:900;color:#fff;letter-spacing:2px;font-family:monospace;'>{$code}</div>
        </div>
      </td>
    </tr></table>
  </td></tr>

  <!-- PAYMENT STATUS BANNER -->
  <tr><td style='background:{$payBg};border-left:5px solid {$payBorder};padding:16px 24px;'>
    <table width='100%' cellpadding='0' cellspacing='0'><tr>
      <td>
        <div style='font-size:10px;color:{$payColor};letter-spacing:1.5px;font-weight:700;text-transform:uppercase;margin-bottom:3px;'>Payment Status</div>
        <div style='font-size:20px;font-weight:900;color:{$payColor};'>{$payIcon} {$payLabel}</div>
        <div style='font-size:12px;color:{$payColor};opacity:0.75;margin-top:2px;'>{$payDesc}</div>
      </td>
      <td align='right' valign='middle'>
        <div style='font-size:30px;font-weight:900;color:{$payHdr};font-family:monospace;'>£{$totalFmt}</div>
        <div style='font-size:11px;color:{$payColor};opacity:0.65;text-align:right;'>Order Total</div>
      </td>
    </tr></table>
  </td></tr>

  <!-- CUSTOMER CARD -->
  <tr><td style='padding:22px 24px 0;'>
    <div style='font-size:10px;font-weight:700;color:#bbb;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:10px;'>Customer Details</div>
    <table width='100%' cellpadding='0' cellspacing='0' style='background:#faf7fd;border-radius:10px;border:1px solid #f0e6ee;overflow:hidden;'>
      <tr>
        <td style='padding:12px 16px;border-bottom:1px solid #f0e6ee;'>
          <div style='font-size:10px;color:#bbb;'>👤 Name</div>
          <div style='font-size:14px;font-weight:700;color:#1a0a14;margin-top:2px;'>{$name}</div>
        </td>
        <td style='padding:12px 16px;border-bottom:1px solid #f0e6ee;border-left:1px solid #f0e6ee;'>
          <div style='font-size:10px;color:#bbb;'>📞 Phone</div>
          <div style='font-size:14px;font-weight:700;color:#c44dff;margin-top:2px;'>{$phone}</div>
        </td>
      </tr>
      <tr>
        <td colspan='2' style='padding:12px 16px;border-bottom:1px solid #f0e6ee;'>
          <div style='font-size:10px;color:#bbb;'>{$addressLabel}</div>
          <div style='font-size:13px;font-weight:600;color:#1a0a14;margin-top:3px;line-height:1.6;'>{$address}</div>
        </td>
      </tr>
      <tr>
        <td colspan='2' style='padding:12px 16px;'>
          <div style='font-size:10px;color:#bbb;'>📝 Notes</div>
          <div style='font-size:13px;color:#555;margin-top:3px;line-height:1.5;'>{$notes}</div>
        </td>
      </tr>
    </table>
  </td></tr>

  {$collectionBanner}

  <!-- ORDER ITEMS -->
  <tr><td style='padding:22px 24px 0;'>
    <div style='font-size:10px;font-weight:700;color:#bbb;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:10px;'>Order Items</div>
    <table width='100%' cellpadding='0' cellspacing='0' style='border:1px solid #f0e6ee;border-radius:10px;overflow:hidden;'>
      <tr style='background:#fff0f5;'>
        <td colspan='2' style='padding:10px 14px;font-size:10px;font-weight:700;color:#c44dff;text-transform:uppercase;letter-spacing:1px;'>Item</td>
        <td style='padding:10px 8px;font-size:10px;font-weight:700;color:#c44dff;text-transform:uppercase;letter-spacing:1px;text-align:center;'>Qty</td>
        <td style='padding:10px 16px;font-size:10px;font-weight:700;color:#c44dff;text-transform:uppercase;letter-spacing:1px;text-align:right;'>Price</td>
      </tr>
      {$itemRows}
      {$promoRow}
      {$deliveryRow}
      <tr style='background:linear-gradient(135deg,#fff0f5,#f8f0ff);'>
        <td colspan='3' style='padding:14px 16px;font-size:14px;font-weight:800;color:#1a0a14;'>💳 ORDER TOTAL</td>
        <td style='padding:14px 16px;text-align:right;font-size:22px;font-weight:900;color:#ff6b9d;font-family:monospace;'>£{$totalFmt}</td>
      </tr>
    </table>
  </td></tr>

  <!-- CTA -->
  <tr><td style='padding:28px;text-align:center;'>
    <a href='{$adminUrl}' style='display:inline-block;background:linear-gradient(135deg,#ff6b9d,#c44dff);color:#fff;font-size:14px;font-weight:700;padding:14px 36px;border-radius:50px;text-decoration:none;letter-spacing:0.5px;'>
      View Order in Admin Panel &rarr;
    </a>
    <div style='font-size:11px;color:#ccc;margin-top:10px;'>Click above to manage this order</div>
  </td></tr>

  <!-- FOOTER -->
  <tr><td style='padding:18px 24px;background:#faf7fd;border-top:1px solid #f0e6ee;text-align:center;'>
    <div style='font-size:11px;color:#bbb;'>Automated notification from <b style='color:#c44dff;'>{$shopName}</b> &bull; Ref: {$code}</div>
  </td></tr>

</table>
</td></tr></table>
</body></html>";

    $subject = "{$payIcon} New Order {$code} – £{$totalFmt} [{$ps}] – {$shopName}";

    $mail = new PHPMailer(true);
    try {
        cbMailTransport($mail);
        $mail->setFrom(SMTP_USER, $shopName);
        $mail->addAddress($adminEmail);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Admin order email error: " . $mail->ErrorInfo);
        return false;
    }
}


// ============================================================
//  Generic email sender – used by contact form on About Us
// ============================================================
function sendGenericEmail(string $toEmail, string $subject, string $htmlBody): bool
{
    if (!defined('PHPMAILER_AVAILABLE') || !PHPMAILER_AVAILABLE) return false;
    $shopName = SHOP_NAME;

    $mail = new PHPMailer(true);
    try {
        cbMailTransport($mail);

        $mail->setFrom(SMTP_USER, $shopName);
        $mail->addAddress($toEmail);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("GenericMailer Error: " . $mail->ErrorInfo);
        return false;
    }
}

// ============================================================
//  Customer Confirmation Email
//  Sends the customer a summary of their order + contact info
// ============================================================
function sendCustomerConfirmationEmail(array $order, string $customerEmail): bool
{
    if (!defined('PHPMAILER_AVAILABLE') || !PHPMAILER_AVAILABLE) return false;
    $shopName    = SHOP_NAME;
    $shopPhone   = defined('SHOP_PHONE') ? SHOP_PHONE : '';
    $shopContact = ADMIN_EMAIL;

    $items = json_decode($order['items_json'], true) ?? [];

    // ── Build items rows ─────────────────────────────
    $itemRows = '';
    foreach ($items as $item) {
        $subtotal     = number_format($item['price'] * $item['quantity'], 2);
        $variantLabel = !empty($item['variant_name']) ? " <span style='color:#b8a0b0; font-size:11px;'>({$item['variant_name']})</span>" : '';
        $itemRows .= "
        <tr>
            <td style='padding:10px 14px; border-bottom:1px solid #f0e6ee;'>{$item['emoji']} " . htmlspecialchars($item['name']) . "{$variantLabel}</td>
            <td style='padding:10px 14px; border-bottom:1px solid #f0e6ee; text-align:center;'>&times; {$item['quantity']}</td>
            <td style='padding:10px 14px; border-bottom:1px solid #f0e6ee; text-align:right; color:#ff4d6d; font-weight:700;'>&pound;{$subtotal}</td>
        </tr>";
    }


    $total    = number_format($order['total_price'], 2);
    $date     = date('d M Y, H:i', strtotime($order['created_at']));
    $code     = htmlspecialchars($order['order_code']);
    $custName = htmlspecialchars($order['customer_name']);
    $address  = nl2br(htmlspecialchars($order['address']));
    $notes    = !empty($order['notes']) ? nl2br(htmlspecialchars($order['notes'])) : '—';

    $isCollection = (strpos($order['address'], 'Collection') !== false);
    $greetingText = $isCollection
        ? "Thank you so much for your order! We've received it and we're already getting things ready for you. You can collect your order from our warehouse during our collection hours."
        : "Thank you so much for your order! We've received it and we're already getting things ready for you. We'll be in touch shortly to confirm delivery details.";
        
    $addressLabel = $isCollection ? '📍 Collection Address' : '📍 Delivery Address';

    // Delivery charge row for customer email
    $custDeliveryRow = '';
    if ((float)($order['delivery_charge'] ?? 0) > 0) {
        $del = number_format($order['delivery_charge'], 2);
        $custDeliveryRow = "
        <tr><td colspan='2' style='padding:8px 14px; border-bottom:1px solid #f0e6ee; font-size:13px; color:#f59e0b;'>
            🚚 Delivery Charge
        </td><td style='padding:8px 14px; border-bottom:1px solid #f0e6ee; text-align:right; color:#f59e0b; font-weight:700;'>+&pound;{$del}</td></tr>";
    }

    // Promo row for customer email
    $custPromoRow = '';
    if (!empty($order['promo_code']) && $order['discount_amount'] > 0) {
        $disc = number_format($order['discount_amount'], 2);
        $subtotalAmt = number_format(array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $items)), 2);
        $custPromoRow = "
        <tr><td colspan='2' style='padding:8px 14px; border-bottom:1px solid #f0e6ee; color:#9c6b7e; font-size:13px;'>
            Subtotal
        </td><td style='padding:8px 14px; border-bottom:1px solid #f0e6ee; text-align:right; color:#9c6b7e;'>&pound;{$subtotalAmt}</td></tr>
        <tr><td colspan='2' style='padding:8px 14px; border-bottom:1px solid #f0e6ee; font-size:13px; color:#10b981;'>
            🎟️ Promo: <strong>{$order['promo_code']}</strong>
        </td><td style='padding:8px 14px; border-bottom:1px solid #f0e6ee; text-align:right; color:#10b981; font-weight:700;'>&minus;&pound;{$disc}</td></tr>";
    }

    // Payment status
    $ps2 = $order['payment_status'] ?? 'Unpaid';
    $pm2 = $order['payment_method'] ?? 'later';
    if ($ps2 === 'Paid') {
        $custPayBg    = '#d1fae5';
        $custPayBord  = '#10b981';
        $custPayColor = '#065f46';
        $custPayIcon  = '✅';
        $custPayTitle = 'Payment Confirmed';
        $custPayDesc  = 'Your payment was processed successfully via card/digital wallet.';
    } elseif ($ps2 === 'Cash') {
        $custPayBg    = '#fef3c7';
        $custPayBord  = '#f59e0b';
        $custPayColor = '#78350f';
        $custPayIcon  = '💵';
        $custPayTitle = 'Cash Payment Received';
        $custPayDesc  = 'Thank you — your cash payment has been recorded.';
    } else {
        $custPayBg    = '#fffbeb';
        $custPayBord  = '#f59e0b';
        $custPayColor = '#92400e';
        $custPayIcon  = '⏳';
        $custPayTitle = 'Payment Due';
        $custPayDesc  = $isCollection
            ? 'Your order total is £' . $total . '. Please pay when you collect your order.'
            : 'Your order total is £' . $total . '. Please pay on delivery or contact us to arrange payment.';
    }

    // Collection info banner for customer email
    $custCollectionBanner = '';
    if ($isCollection) {
        $custCollectionBanner = "
        <!-- Collection Info -->
        <tr>
            <td style='padding:8px 32px 0;'>
                <div style='background:#fff7ed; border:2px solid #f59e0b; border-radius:12px; padding:20px; text-align:center;'>
                    <div style='font-size:28px; margin-bottom:8px;'>&#x1F3EA;</div>
                    <div style='font-size:16px; font-weight:800; color:#78350f; margin-bottom:6px;'>Collect Your Order</div>
                    <div style='font-size:13px; color:#92400e; line-height:1.8; margin-bottom:14px;'>
                        <strong>Creamy Bite</strong><br>
                        Unit E5 Phoenix Business Centre<br>
                        HA1 2SP<br><br>
                        &#x23F0; <strong>Collection Hours: 11 AM &ndash; 8 PM</strong><br>
                        <span style='font-size:12px; opacity:0.8;'>Please bring your order reference: <strong>{$code}</strong></span>
                    </div>
                    <a href='https://maps.app.goo.gl/hrMSnTRqFvorzF7HA?g_st=iw'
                       style='display:inline-block; background:#f59e0b; color:#ffffff; font-size:13px; font-weight:700; padding:11px 26px; border-radius:30px; text-decoration:none; letter-spacing:0.5px;'>
                       &#x1F5FA;&#xFE0F;&nbsp; Get Directions on Google Maps
                    </a>
                </div>
            </td>
        </tr>";
    }

    $html = "
    <!DOCTYPE html>
    <html><head><meta charset='UTF-8'></head>
    <body style='margin:0; padding:0; background:#f9f0f5; font-family: Arial, Helvetica, sans-serif;'>

    <table width='100%' cellpadding='0' cellspacing='0' style='background:#f9f0f5; padding:32px 0;'>
    <tr><td align='center'>
    <table width='600' cellpadding='0' cellspacing='0' style='max-width:600px; width:100%; background:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 4px 24px rgba(0,0,0,0.08);'>

        <!-- Header -->
        <tr>
            <td style='background: linear-gradient(135deg, #ff4d6d, #c44dff); padding:32px; text-align:center;'>
                <div style='font-size:48px; margin-bottom:10px;'>🍦</div>
                <h1 style='color:white; margin:0; font-size:26px; letter-spacing:-0.5px;'>{$shopName}</h1>
                <p style='color:rgba(255,255,255,0.85); margin:6px 0 0; font-size:14px;'>Order Confirmed!</p>
            </td>
        </tr>

        <!-- Greeting -->
        <tr>
            <td style='padding:28px 32px 12px;'>
                <h2 style='font-size:20px; color:#1a0a14; margin:0 0 8px;'>Hi {$custName}! 🎉</h2>
                <p style='font-size:14px; color:#6b4c5e; margin:0; line-height:1.7;'>
                    {$greetingText}
                </p>
            </td>
        </tr>

        <!-- Order Code -->
        <tr>
            <td style='padding:16px 32px;'>
                <div style='background:#fdf0f5; border:2px dashed #ff4d6d; border-radius:12px; padding:16px; text-align:center;'>
                    <div style='font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.1em; color:#9c6b7e; margin-bottom:6px;'>Your Order Reference</div>
                    <div style='font-size:26px; font-weight:900; color:#ff4d6d; letter-spacing:2px; font-family:monospace;'>{$code}</div>
                    <div style='font-size:11px; color:#9c6b7e; margin-top:4px;'>Placed on {$date}</div>
                </div>
            </td>
        </tr>

        <!-- Items Table -->
        <tr>
            <td style='padding:8px 32px 0;'>
                <h3 style='font-size:14px; font-weight:700; color:#1a0a14; text-transform:uppercase; letter-spacing:0.08em; margin:0 0 10px;'>Items Ordered</h3>
                <table width='100%' cellpadding='0' cellspacing='0' style='border-collapse:collapse; border:1px solid #f0e6ee; border-radius:10px; overflow:hidden;'>
                    <thead>
                        <tr style='background:#fdf0f5;'>
                            <th style='padding:10px 14px; text-align:left; font-size:12px; color:#9c6b7e; font-weight:700; text-transform:uppercase;'>Item</th>
                            <th style='padding:10px 14px; text-align:center; font-size:12px; color:#9c6b7e; font-weight:700; text-transform:uppercase;'>Qty</th>
                            <th style='padding:10px 14px; text-align:right; font-size:12px; color:#9c6b7e; font-weight:700; text-transform:uppercase;'>Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        {$itemRows}
                        {$custPromoRow}
                        {$custDeliveryRow}
                    </tbody>
                </table>
            </td>
        </tr>

        <!-- Total -->
        <tr>
            <td style='padding:16px 32px 8px;'>
                <table width='100%' cellpadding='0' cellspacing='0'>
                    <tr>
                        <td style='font-size:16px; font-weight:700; color:#1a0a14;'>Total</td>
                        <td style='text-align:right; font-size:24px; font-weight:900; color:#ff4d6d;'>&pound;{$total}</td>
                    </tr>
                </table>
            </td>
        </tr>

        <!-- Payment Status -->
        <tr>
            <td style='padding:4px 32px 16px;'>
                <div style='background:{$custPayBg}; border:1.5px solid {$custPayBord}; border-radius:10px; padding:16px 20px; text-align:center;'>
                    <div style='font-size:17px; font-weight:800; color:{$custPayColor}; margin-bottom:6px;'>{$custPayIcon} {$custPayTitle}</div>
                    <div style='font-size:13px; color:{$custPayColor}; line-height:1.6; opacity:0.85;'>{$custPayDesc}</div>
                </div>
            </td>
        </tr>

        <!-- Delivery Info -->
        <tr>
            <td style='padding:8px 32px 24px;'>
                <table width='100%' cellpadding='0' cellspacing='0' style='background:#f9f0f5; border-radius:10px; overflow:hidden;'>
                    <tr>
                        <td style='padding:14px 18px; font-size:13px; color:#6b4c5e;'>
                            <strong style='color:#1a0a14;'>{$addressLabel}</strong><br>
                            <span style='line-height:1.7;'>{$address}</span>
                        </td>
                    </tr>
                    <tr>
                        <td style='padding:0 18px 14px; font-size:13px; color:#6b4c5e;'>
                            <strong style='color:#1a0a14;'>📝 Notes</strong><br>
                            {$notes}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>

        {$custCollectionBanner}

        <!-- Contact Info -->
        <tr>
            <td style='padding:0 32px 32px;'>
                <div style='background:linear-gradient(135deg,#fff7f0,#fff0f5); border:1px solid #f5c6d0; border-radius:12px; padding:20px; text-align:center;'>
                    <div style='font-size:13px; font-weight:700; color:#1a0a14; margin-bottom:8px;'>Need help? Contact us!</div>
                    <div style='font-size:15px; font-weight:700; color:#ff4d6d; margin-bottom:4px;'>📞 {$shopPhone}</div>
                    <div style='font-size:12px; color:#9c6b7e;'>📧 {$shopContact}</div>
                    <div style='font-size:11px; color:#9c6b7e; margin-top:8px;'>We're here Monday – Saturday, 10am – 8pm</div>
                </div>
            </td>
        </tr>

        <!-- Footer -->
        <tr>
            <td style='background:#1a0a14; padding:20px 32px; text-align:center;'>
                <p style='color:rgba(255,255,255,0.4); font-size:11px; margin:0;'>
                    &copy; " . date('Y') . " {$shopName} &mdash; Made with ❤️
                </p>
            </td>
        </tr>

    </table>
    </td></tr>
    </table>
    </body></html>
    ";

    $subject = "🍦 Your {$shopName} Order is Confirmed! [{$code}]";

    $mail = new PHPMailer(true);
    try {
        cbMailTransport($mail);

        $mail->setFrom(SMTP_USER, $shopName);
        $mail->addAddress($customerEmail, $order['customer_name']);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("CustomerMailer Error: " . $mail->ErrorInfo);
        return false;
    }
}

// ============================================================
//  Send Payment Receipt Email to Customer (triggered by admin)
// ============================================================
function sendPaymentReceiptEmail(array $order): bool {
    if (empty($order['customer_email'])) return false;
    if (!defined('PHPMAILER_AVAILABLE') || !PHPMAILER_AVAILABLE) return false;

    $shopName  = SHOP_NAME;
    $shopEmail = ADMIN_EMAIL;
    $shopPhone = defined('SHOP_PHONE') ? SHOP_PHONE : '';
    $orderCode = htmlspecialchars($order['order_code']);
    $total     = number_format((float)$order['total_price'], 2);
    $ps        = $order['payment_status'] ?? 'Paid';
    $name      = htmlspecialchars($order['customer_name']);

    if ($ps === 'Paid') {
        $payIcon  = '✅';
        $payTitle = 'Payment Confirmed';
        $payMsg   = 'Your payment of <strong>£' . $total . '</strong> has been confirmed. Thank you!';
        $payBg    = '#d1fae5'; $payBorder = '#10b981'; $payColor = '#065f46';
    } elseif ($ps === 'Cash') {
        $payIcon  = '💵';
        $payTitle = 'Cash Payment Received';
        $payMsg   = 'We have received your cash payment of <strong>£' . $total . '</strong>. Thank you!';
        $payBg    = '#fef3c7'; $payBorder = '#f59e0b'; $payColor = '#78350f';
    } else {
        return false; // Don't send receipt for Unpaid
    }

    $subject = $payIcon . ' Payment Receipt – ' . $shopName . ' Order [' . $orderCode . ']';

    $body = '
<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>body{margin:0;padding:0;background:#f4f4f4;font-family:sans-serif;}a{color:inherit;}</style>
</head><body>
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:32px 0;">
<tr><td align="center">
<table width="580" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">
  <tr><td style="background:linear-gradient(135deg,#ff6b9d,#c44dff);padding:32px;text-align:center;">
    <div style="font-size:40px;margin-bottom:8px;">' . $payIcon . '</div>
    <h1 style="color:#fff;margin:0;font-size:24px;">' . $payTitle . '</h1>
    <p style="color:rgba(255,255,255,0.85);margin:8px 0 0;font-size:14px;">Order ' . $orderCode . '</p>
  </td></tr>
  <tr><td style="padding:32px;">
    <p style="font-size:16px;color:#333;">Hi <strong>' . $name . '</strong>,</p>
    <div style="background:' . $payBg . ';border:1.5px solid ' . $payBorder . ';border-radius:10px;padding:20px;margin:20px 0;text-align:center;">
      <p style="color:' . $payColor . ';margin:0;font-size:15px;">' . $payMsg . '</p>
    </div>
    <table width="100%" cellpadding="8" cellspacing="0" style="border-top:1px solid #eee;margin-top:20px;">
      <tr><td style="color:#888;font-size:13px;">Order Reference</td><td style="text-align:right;font-weight:700;color:#333;font-size:15px;letter-spacing:1px;">' . $orderCode . '</td></tr>
      <tr><td style="color:#888;font-size:13px;">Amount</td><td style="text-align:right;font-weight:700;color:#c44dff;font-size:18px;">£' . $total . '</td></tr>
    </table>
    <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
    <p style="font-size:13px;color:#666;text-align:center;">Questions? Contact us at <a href="mailto:' . $shopEmail . '" style="color:#c44dff;">' . $shopEmail . '</a>' . ($shopPhone ? ' or call ' . $shopPhone : '') . '</p>
    <p style="font-size:13px;color:#aaa;text-align:center;margin-top:8px;">Thank you for choosing ' . $shopName . '! 🍦</p>
  </td></tr>
</table>
</td></tr></table>
</body></html>';

    return sendGenericEmail($order['customer_email'], $subject, $body);
}

function sendDeliveryNoteEmail(array $order): bool {
    if (empty($order['customer_email'])) return false;
    if (!defined('PHPMAILER_AVAILABLE') || !PHPMAILER_AVAILABLE) return false;

    $shopName  = SHOP_NAME;
    $shopEmail = ADMIN_EMAIL;
    $shopPhone = defined('SHOP_PHONE') ? SHOP_PHONE : '';
    $siteUrl   = defined('SITE_URL') ? SITE_URL : '';
    $orderCode = htmlspecialchars($order['order_code']);
    $total     = number_format((float)$order['total_price'], 2);
    $ps        = $order['payment_status'] ?? 'Unpaid';
    $name      = htmlspecialchars($order['customer_name']);
    $address   = nl2br(htmlspecialchars($order['address']));
    $postcode  = htmlspecialchars($order['postcode'] ?? '');
    $notes     = !empty($order['notes']) ? nl2br(htmlspecialchars($order['notes'])) : 'None';
    $isTrade   = !empty($order['trade_business_name']) || strpos($order['notes'], 'TRADE B2B ORDER') !== false;
    $storeName = !empty($order['trade_business_name']) ? htmlspecialchars($order['trade_business_name']) : '';

    $items = json_decode($order['items_json'], true) ?? [];
    $itemRows = '';
    foreach ($items as $item) {
        $lineTotal = number_format((float)$item['price'] * (int)$item['quantity'], 2);
        $priceFmt  = number_format((float)$item['price'], 2);
        $variant   = !empty($item['variant_name']) ? ' (' . htmlspecialchars($item['variant_name']) . ')' : '';
        $itemRows .= "
        <tr style='border-bottom:1px solid #eee;'>
            <td style='padding:10px; font-size:14px; color:#111;'>{$item['emoji']} <strong>" . htmlspecialchars($item['name']) . "{$variant}</strong></td>
            <td style='padding:10px; text-align:center; font-weight:700; color:#5C1D24;'>{$item['quantity']}</td>
            <td style='padding:10px; text-align:right; color:#666;'>£{$priceFmt}</td>
            <td style='padding:10px; text-align:right; font-weight:700; color:#111;'>£{$lineTotal}</td>
        </tr>";
    }

    $payBadge = ($ps === 'Paid') 
        ? '<span style="background:#ecfdf5; color:#047857; padding:4px 12px; border-radius:12px; font-weight:700;">✅ PAID ONLINE</span>'
        : (($ps === 'Cash')
            ? '<span style="background:#fffbeb; color:#b45309; padding:4px 12px; border-radius:12px; font-weight:700;">💵 CASH ON DELIVERY</span>'
            : '<span style="background:#fef2f2; color:#991b1b; padding:4px 12px; border-radius:12px; font-weight:700;">⏳ UNPAID / INVOICE PENDING</span>');

    $subject = '📦 Order Delivered & Delivery Note – ' . $shopName . ' [' . $orderCode . ']';

    $body = '
<!DOCTYPE html><html><head><meta charset="UTF-8">
<style>body{margin:0;padding:0;background:#fff7ef;font-family:-apple-system,BlinkMacSystemFont,sans-serif;}a{color:#5C1D24;}</style>
</head><body>
<table width="100%" cellpadding="0" cellspacing="0" style="background:#fff7ef;padding:32px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 6px 28px rgba(0,0,0,0.08);border:1px solid #f0e6ee;">
  <tr><td style="background:linear-gradient(135deg,#5C1D24,#3d1318);padding:32px;text-align:center;">
    <div style="font-size:44px;margin-bottom:8px;">🍦</div>
    <h1 style="color:#fff;margin:0;font-size:24px;letter-spacing:-0.5px;">Order Delivered & Delivery Note</h1>
    <p style="color:#C78829;margin:6px 0 0;font-size:14px;font-weight:700;">Order Code: ' . $orderCode . '</p>
  </td></tr>
  <tr><td style="padding:32px;">
    ' . ($isTrade ? '<div style="background:#FFF0E5; border:1px solid rgba(199,136,41,0.3); padding:12px 16px; border-radius:8px; margin-bottom:20px; font-size:13px; color:#5C1D24;"><strong>🏪 B2B Trade Partner:</strong> ' . $storeName . '</div>' : '') . '
    <p style="font-size:15px;color:#111;margin-top:0;">Hi <strong>' . $name . '</strong>,</p>
    <p style="font-size:14px;color:#444;line-height:1.5;">Your Creamy Bite order has been successfully delivered! Below is your official Delivery Note and Invoice breakdown.</p>
    
    <table width="100%" cellpadding="12" cellspacing="0" style="background:#f9fafb;border-radius:10px;border:1px solid #eee;margin:20px 0;font-size:13px;">
      <tr>
        <td style="vertical-align:top;width:50%;">
          <strong style="color:#666;text-transform:uppercase;font-size:11px;letter-spacing:0.5px;">Delivery Address</strong><br>
          <div style="margin-top:4px;color:#111;line-height:1.4;">' . $address . '<br><strong style="color:#5C1D24;">' . $postcode . '</strong></div>
        </td>
        <td style="vertical-align:top;width:50%;">
          <strong style="color:#666;text-transform:uppercase;font-size:11px;letter-spacing:0.5px;">Payment Status</strong><br>
          <div style="margin-top:6px;">' . $payBadge . '</div>
        </td>
      </tr>
      <tr>
        <td colspan="2" style="border-top:1px solid #eee;padding-top:10px;">
          <strong style="color:#666;text-transform:uppercase;font-size:11px;letter-spacing:0.5px;">Delivery Instructions & Times</strong>
          <div style="margin-top:4px;color:#444;font-family:monospace;font-size:12px;">' . $notes . '</div>
        </td>
      </tr>
    </table>

    <h3 style="font-size:15px;color:#5C1D24;margin:24px 0 10px;">Items Delivered</h3>
    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-bottom:20px;">
      <thead>
        <tr style="background:#f3f4f6;color:#374151;font-size:12px;text-transform:uppercase;">
          <th style="padding:10px;text-align:left;">Item</th>
          <th style="padding:10px;text-align:center;">Qty</th>
          <th style="padding:10px;text-align:right;">Price</th>
          <th style="padding:10px;text-align:right;">Subtotal</th>
        </tr>
      </thead>
      <tbody>' . $itemRows . '</tbody>
    </table>

    <table width="100%" cellpadding="6" cellspacing="0" style="font-size:14px;border-top:2px solid #5C1D24;padding-top:12px;">
      <tr>
        <td style="font-weight:800;color:#5C1D24;font-size:16px;">TOTAL AMOUNT PAID / DUE:</td>
        <td style="text-align:right;font-weight:800;color:#5C1D24;font-size:18px;">£' . $total . '</td>
      </tr>
    </table>

    <div style="text-align:center; margin-top:28px;">
      <a href="' . $siteUrl . '/admin/delivery_note.php?code=' . urlencode($order['order_code']) . '" target="_blank" style="display:inline-block; background:#5C1D24; color:#ffffff; font-weight:700; padding:12px 24px; border-radius:8px; text-decoration:none; font-size:14px;">
        🖨️ View & Print Full Delivery Note
      </a>
    </div>

    <hr style="border:none;border-top:1px solid #eee;margin:28px 0;">
    <p style="font-size:13px;color:#666;text-align:center;margin:0;">Thank you for ordering with ' . $shopName . '! If you have any questions, reach us at <a href="mailto:' . $shopEmail . '">' . $shopEmail . '</a>.</p>
  </td></tr>
</table>
</td></tr></table>
</body></html>';

    return sendGenericEmail($order['customer_email'], $subject, $body);
}


/**
 * Send one HTML email through the shop's SMTP account.
 *
 * The three existing senders each build their own PHPMailer by hand, which is
 * why a change to the SMTP settings has to be made in three places. New
 * senders use this instead; the older ones are left alone rather than
 * refactored blind.
 *
 * $replyTo lets a customer reply to the shop even though the message is sent
 * through the SMTP account — without it, replies go to the mailbox the site
 * authenticates as, which nobody reads.
 */
function cbSendMail(string $to, string $subject, string $htmlBody, string $replyTo = ''): bool
{
    global $_phpmailerLoaded;
    if (empty($_phpmailerLoaded)) {
        error_log('cbSendMail: PHPMailer not available');
        return false;
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('cbSendMail: refusing to send to "' . $to . '"');
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        cbMailTransport($mail);
        $mail->setFrom(SMTP_USER, SHOP_NAME);
        $mail->addAddress($to);
        $mail->addReplyTo($replyTo !== '' ? $replyTo : ADMIN_EMAIL, SHOP_NAME);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '</tr>'], "\n", $htmlBody))));
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('cbSendMail failed to ' . $to . ': ' . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Email an invoice to the customer.
 *
 * The invoice is laid out INSIDE the email rather than attached. There is no
 * PDF library on this server, and a link on its own reads like a phishing
 * message — showing the figures in the body means the customer can check the
 * amount without clicking anything, and the link is there when they want a
 * copy to keep.
 *
 * Every rule is written inline: mail clients strip <style> blocks, so this is
 * the one place in the project where inline CSS is correct.
 */
function sendInvoiceEmail(array $inv, string $publicLink = ''): bool
{
    global $_phpmailerLoaded;
    if (empty($_phpmailerLoaded)) {
        error_log('sendInvoiceEmail: PHPMailer not available');
        return false;
    }

    $to = trim((string)($inv['to_email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $symbol   = ($inv['currency'] ?? 'GBP') === 'GBP' ? '£' : '';
    $number   = htmlspecialchars((string)$inv['invoice_number']);
    $name     = htmlspecialchars((string)$inv['to_name']);
    $balance  = (float)($inv['balance_due'] ?? 0);
    $due      = !empty($inv['due_date'])
        ? date('d/m/Y', strtotime((string)$inv['due_date']))
        : htmlspecialchars((string)($inv['due_terms'] ?? 'On Receipt'));

    $rows = '';
    foreach (($inv['items'] ?? []) as $it) {
        $rows .= '<tr>'
              . '<td style="padding:9px 10px;border-bottom:1px solid #eef0f3;font-size:13px;">'
              . htmlspecialchars((string)$it['description']) . '</td>'
              . '<td style="padding:9px 10px;border-bottom:1px solid #eef0f3;font-size:13px;text-align:right;white-space:nowrap;">'
              . $symbol . number_format((float)$it['rate'], 2) . '</td>'
              . '<td style="padding:9px 10px;border-bottom:1px solid #eef0f3;font-size:13px;text-align:right;">'
              . htmlspecialchars(formatInvoiceQty((float)$it['qty'], (string)$it['qty_unit'])) . '</td>'
              . '<td style="padding:9px 10px;border-bottom:1px solid #eef0f3;font-size:13px;text-align:right;white-space:nowrap;">'
              . $symbol . number_format((float)$it['amount'], 2) . '</td>'
              . '</tr>';
    }

    $linkBlock = '';
    if ($publicLink !== '') {
        $linkBlock = '<div style="text-align:center;margin:26px 0 6px;">'
                   . '<a href="' . htmlspecialchars($publicLink) . '" '
                   . 'style="display:inline-block;background:#5C1D24;color:#ffffff;font-weight:700;'
                   . 'padding:13px 26px;border-radius:8px;text-decoration:none;font-size:14px;">'
                   . 'View invoice &amp; save as PDF</a></div>'
                   . '<p style="text-align:center;font-size:12px;color:#9ca3af;margin:6px 0 0;">'
                   . 'Opens in your browser — choose “Save as PDF” to keep a copy.</p>';
    }

    $body = '<div style="font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;'
          . 'max-width:640px;margin:0 auto;padding:28px 22px;color:#1f2937;">'
          . '<h1 style="font-size:22px;margin:0 0 4px;color:#5C1D24;">Invoice ' . $number . '</h1>'
          . '<p style="font-size:14px;color:#6b7280;margin:0 0 22px;">From ' . htmlspecialchars(SHOP_NAME) . '</p>'
          . '<p style="font-size:15px;">Hello ' . $name . ',</p>'
          . '<p style="font-size:14px;line-height:1.7;">Your invoice is below. '
          . 'The balance due is <strong>' . $symbol . number_format($balance, 2) . '</strong>, '
          . 'payable by <strong>' . $due . '</strong>.</p>'
          . '<table style="width:100%;border-collapse:collapse;margin:20px 0 0;">'
          . '<thead><tr>'
          . '<th style="text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;padding:8px 10px;border-bottom:2px solid #5C1D24;">Description</th>'
          . '<th style="text-align:right;font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;padding:8px 10px;border-bottom:2px solid #5C1D24;">Rate</th>'
          . '<th style="text-align:right;font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;padding:8px 10px;border-bottom:2px solid #5C1D24;">Qty</th>'
          . '<th style="text-align:right;font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;padding:8px 10px;border-bottom:2px solid #5C1D24;">Amount</th>'
          . '</tr></thead><tbody>' . $rows . '</tbody></table>'
          . '<table style="width:100%;max-width:280px;margin:16px 0 0 auto;border-collapse:collapse;">'
          . '<tr><td style="padding:5px 0;font-size:13px;">Total</td>'
          . '<td style="padding:5px 0;font-size:13px;text-align:right;">' . $symbol . number_format((float)$inv['total'], 2) . '</td></tr>'
          . (((float)($inv['amount_paid'] ?? 0) > 0)
                ? '<tr><td style="padding:5px 0;font-size:13px;">Paid</td><td style="padding:5px 0;font-size:13px;text-align:right;">−' . $symbol . number_format((float)$inv['amount_paid'], 2) . '</td></tr>'
                : '')
          . '<tr><td style="padding:9px 0;font-size:16px;font-weight:800;color:#5C1D24;border-top:2px solid #5C1D24;">Balance Due</td>'
          . '<td style="padding:9px 0;font-size:16px;font-weight:800;color:#5C1D24;text-align:right;border-top:2px solid #5C1D24;">' . $symbol . number_format($balance, 2) . '</td></tr>'
          . '</table>'
          . $linkBlock
          . (trim((string)($inv['payment_instructions'] ?? '')) !== ''
                ? '<div style="margin-top:26px;padding-top:16px;border-top:1px solid #eef0f3;font-size:13px;color:#4b5563;line-height:1.7;">'
                  . '<strong>Payment instructions</strong><br>' . nl2br(htmlspecialchars((string)$inv['payment_instructions'])) . '</div>'
                : '')
          . '<p style="font-size:13px;color:#6b7280;margin-top:26px;">'
          . 'Any questions, reply to this email or call ' . htmlspecialchars(SHOP_PHONE) . '.<br>'
          . '— ' . htmlspecialchars(SHOP_NAME) . '</p></div>';

    return cbSendMail($to, 'Invoice ' . $inv['invoice_number'] . ' from ' . SHOP_NAME, $body);
}
