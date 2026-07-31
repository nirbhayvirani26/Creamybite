# Creamy Bite — Project Structure

Plain PHP + MySQL (no framework), served by Apache. PHP 8.3 on MAMP locally.

## Layout

```
orders/
├── index.php                  Home page
├── order.php                  Menu / ordering
├── checkout.php               Checkout
├── gallery.php  about.php     Content pages
├── order_confirmation.php     Post-order page
├── trade_*.php                B2B trade portal (login/register/profile/invoice/logout)
├── cart_handler.php           AJAX + form endpoints, called by the pages above
├── checkout_handler.php
├── promo_handler.php
├── stripe_intent.php
├── shop.php  admin.php  login.php  trade_history.php
│                              Retired URLs kept as redirects — do not delete
│
├── includes/                  Server-side only, never a URL (HTTP-blocked)
│   ├── config.php             Site constants + local/live environment switch
│   ├── secrets.php            Credentials — never commit real values
│   ├── db.php                 PDO connection
│   ├── csrf.php               CSRF helpers
│   ├── mailer.php             Order/contact email
│   ├── invoice.php            Invoice domain logic
│   ├── pricing.php  stock.php Business rules
│   ├── trade_cart.php         Persistent trade basket
│   └── trade_nav_button.php   Shared header partial
│
├── assets/
│   ├── css/                   style, components, responsive, animations, modal
│   ├── js/                    animations.js, modal.js
│   ├── fonts/                 Ezra family
│   └── images/                logo, story images, products/, gallery/
│
├── admin/
│   ├── index.php              Dashboard (all tabs)
│   ├── login.php  logout.php
│   ├── _guard.php             Auth gate — require at the top of every admin file
│   ├── _csrf_js.php
│   ├── product_form.php  invoice_edit.php  invoice_view.php
│   ├── delivery_note.php  reports.php  revenue_report.php
│   ├── handlers/              POST/AJAX endpoints for the admin UI
│   ├── migrations/
│   │   ├── update_db.php      ← run this to bring a database up to date
│   │   └── archive/           Superseded one-off scripts, kept for history
│   └── assets/css/            admin.css, setup.css
│
├── database/schema.sql        Base schema for a fresh install
├── vendor/                    Composer (Stripe SDK)
└── PHPMailer/                 Vendored mail library
```

## Conventions

- **Public pages live at the web root.** They *are* the site's URLs
  (`/order.php`, `/checkout.php`), so moving them into a subfolder would change
  every live URL and break existing links. Anything that is *not* a URL lives in
  `includes/` instead.
- **`includes/` is unreachable over HTTP** (`includes/.htaccess` denies all).
  `require`/`include` are filesystem reads and are unaffected.
- **Paths are written as `__DIR__ . '/...'`**, so a file works regardless of
  which page included it.
- **Every admin file that can change data must `require_once __DIR__ . '/_guard.php'`**
  (`/../_guard.php` from `handlers/`, `migrations/`).

## Updating a database

Log in to the admin panel, then open `/admin/migrations/update_db.php`.
It adds any missing tables/columns and is safe to run repeatedly — anything
already present is reported as "already exists" and skipped.

## Local setup

MAMP: Apache on `:8888`, MySQL on `:8889`, database `creamybite`.
Credentials are in `includes/secrets.php`; `includes/config.php` auto-detects
local vs live from the hostname.
