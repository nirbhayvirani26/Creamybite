<?php
// Redirect old admin.php to admin folder.
//
// Largely moot now: /admin.php redirects to /admin, and /admin is a REAL
// DIRECTORY, so the rewrite's !-d guard leaves it alone and Apache serves
// admin/index.php directly — this file is no longer on the path. It is kept
// because the redirect stubs are kept (see the README), and it loads config
// so that it still works rather than fataling if anything ever reaches it.
require_once __DIR__ . '/../includes/config.php';

header('Location: ' . cbUrl('admin/index.php'));
exit;
