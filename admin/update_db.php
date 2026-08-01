<?php
// ============================================================
//  Creamy Bite – update_db.php (moved)
//
//  The migration runner used to live at this path and now lives at
//  admin/migrations/update_db.php. This shim exists because the old URL was
//  handed out and bookmarked: without it, opening the old address on the live
//  server returns a 404, the migration silently never runs, and the database
//  is left behind the code — which is exactly how the live site ended up
//  without a product_variants table while every page assumed it existed.
//
//  Uploading this file also overwrites any stale pre-move copy still sitting
//  on a server that was deployed by FTP, so the old address can never run an
//  outdated migration either.
// ============================================================

header('Location: migrations/update_db.php', true, 301);
echo 'The database updater has moved to '
   . '<a href="migrations/update_db.php">admin/migrations/update_db.php</a>.';
