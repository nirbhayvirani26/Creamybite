<?php
// ============================================================
//  Creamy Bite – shop.php (retired)
//
//  This page was left over from a different project: its nav linked to
//  "Cyber Gear" and "Wearables" categories that do not exist here, and it
//  read products.tagline and products.rating, which are not columns on this
//  table — 39 PHP diagnostics per request, several of them printing absolute
//  server paths to the visitor.
//
//  Nothing on the site linked to it. It now redirects to the real menu so
//  any old bookmark or search-engine result still lands somewhere useful.
//  The original file is not needed; recover it from version control or the
//  session backup if it is ever wanted.
// ============================================================
header('Location: order.php', true, 301);
exit;
