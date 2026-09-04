# Creamy Bite — Project Structure

Plain PHP + MySQL (no framework), served by Apache. PHP 8.3 on MAMP locally.

## Layout

```
orders/
├── index.php                  Home page (site entry point — stays at the root)
├── cart_handler.php           AJAX + form endpoints, called by the pages
├── checkout_handler.php
├── promo_handler.php
├── stripe_intent.php
│
├── pages/                     Every other public page
│   ├── order.php              Menu / ordering
│   ├── checkout.php           Checkout
│   ├── gallery.php  about.php Content pages
│   ├── order_confirmation.php Post-order page
│   ├── trade_*.php            B2B portal (login/register/profile/invoice/logout)
│   └── shop.php  admin.php  login.php  trade_history.php
│                              Retired URLs kept as redirects — do not delete
│                              (all pages are served at /name, without .php)
│
├── includes/                  Server-side only, never a URL (HTTP-blocked)
│   ├── config.php             Site constants + local/live environment switch
│   ├── secrets.php            Credentials — never commit real values
│   ├── session.php            Starts the session — require instead of session_start()
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

- **Public pages are served at clean URLs.** `pages/order.php` is reached at
  `/order` — no folder, no extension. `.htaccess` rewrites the clean address
  onto the file internally, and 301-redirects both older forms (`/order.php`
  and `/pages/order.php`) onto it, so every address that was ever live
  converges on one canonical URL.
- **`index.php` and the handlers stay at the root, extension and all.**
  `index.php` is the site entry point and is served at `/`; the handlers are
  endpoints scripts call, not pages anyone reads, so they keep `.php` and are
  deliberately excluded from the rewrite.
- **Never write an internal link relative.** Use `cbUrl('order')` for a page,
  `cbUrl('cart_handler.php')` for an endpoint, and `cbAsset('../assets/...')`
  for a stylesheet, script or image. All three return a path from the site
  root, built on `SITE_BASE` so a subfolder install still works.

  This is not a style preference. A relative link resolves against the ADDRESS
  the browser is showing, and that address no longer matches the folder the
  file lives in: `/order` and `/pages/order.php` run the same script, so
  `href="checkout.php"` written on that page means two different things
  depending on which address the customer arrived at, and `../assets/x.css`
  means a URL above the site root. Both fail silently — an unstyled page or a
  dead link, with nothing in the logs.

  `cbAsset()` resolves against the running script's own folder, which is what
  keeps `admin/` working: `cbAsset('assets/css/admin.css')` on an admin page
  means `/admin/assets/...`, not the storefront's.
- **`includes/` is unreachable over HTTP** (`includes/.htaccess` denies all).
  `require`/`include` are filesystem reads and are unaffected.
- **Paths are written as `__DIR__ . '/...'`**, so a file works regardless of
  which page included it.
- **Every admin file that can change data must `require_once __DIR__ . '/_guard.php'`**
  (`/../_guard.php` from `handlers/`, `migrations/`).
- **Never call `session_start()` directly — require `includes/session.php`.**
  It sets the cookie to HttpOnly, SameSite=Lax and (on HTTPS only) Secure
  before starting the session. Those flags have to be set on every entry point,
  because whichever page a visitor reaches first is the one that issues the
  cookie — a single `session_start()` anywhere would hand out an unprotected
  one. `secure` follows the actual protocol, so http://localhost still works.
- **Anything that changes data is a POST, never a link.** A GET has to be safe
  to fetch speculatively; browsers, extensions and crawlers follow links on
  their own, and a token in the query string does not change that. Use a form
  with `csrfField()`, and `data-confirm` on the form for the confirmation
  dialog (see `assets/js/modal.js`).

## Updating a database

Log in to the admin panel, then open `/admin/migrations/update_db.php`.
It adds any missing tables/columns and is safe to run repeatedly — anything
already present is reported as "already exists" and skipped.

## Local setup

MAMP: Apache on `:8888`, MySQL on `:8889`, database `creamybite`.
Credentials are in `includes/secrets.php`; `includes/config.php` auto-detects
local vs live from the hostname.
