<?php
// ============================================================
//  Creamy Bite – Catalogue sync BUILDER  (run on your Mac only)
//
//  Reads the catalogue out of the local database and writes
//  sync_catalogue.php, which you upload and run on live.
//
//  Only the catalogue travels: categories, products and their sizes.
//  Orders, customers, trade accounts and invoices are never touched, so
//  running the result on live cannot lose a real sale — which is exactly
//  what importing the whole database has been doing.
//
//  Usage on your Mac:
//      /Applications/MAMP/bin/php/php8.5.2/bin/php admin/migrations/build_catalogue_sync.php
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This builder runs from the command line on your own machine, not over the web.');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

if (!IS_LOCAL) {
    exit("Refusing to run: this must be pointed at the LOCAL database.\n");
}

$categories = [];
try {
    $categories = $pdo->query("SELECT name, sort_order FROM categories ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    fwrite(STDERR, "categories unavailable: " . $e->getMessage() . "\n");
}

$products = $pdo->query(
    "SELECT name, description, price, wholesale_price, category, emoji, image,
            badge, available, nuts_allergy, trade_only
       FROM products ORDER BY name"
)->fetchAll(PDO::FETCH_ASSOC);

// Sizes are carried under their product's NAME, not its id: the two databases
// assign different ids to the same product, so ids cannot be matched across
// them. Names are what actually identify a product to the shop.
$variants = [];
$vStmt = $pdo->prepare(
    "SELECT v.name, v.price, v.wholesale_price, v.available, v.sort_order, p.name AS product_name
       FROM product_variants v JOIN products p ON p.id = v.product_id
      ORDER BY p.name, v.sort_order, v.id"
);
$vStmt->execute();
foreach ($vStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $variants[] = $row;
}

$payload = [
    'built_at'   => date('Y-m-d H:i:s'),
    'categories' => $categories,
    'products'   => $products,
    'variants'   => $variants,
];

$php = "<?php\n"
     . "// ============================================================\n"
     . "//  Creamy Bite – Catalogue sync  (GENERATED — do not hand-edit)\n"
     . "//\n"
     . "//  Built from the local catalogue on {$payload['built_at']}.\n"
     . "//  Upload alongside update_db.php and open:\n"
     . "//      /admin/migrations/sync_catalogue.php\n"
     . "//\n"
     . "//  It shows you exactly what it will change and does nothing until\n"
     . "//  you press Apply. It only ever writes categories, products and\n"
     . "//  sizes — never orders, customers, trade accounts or invoices —\n"
     . "//  and it never deletes anything.\n"
     . "// ============================================================\n\n"
     . "return " . var_export($payload, true) . ";\n";

$out = __DIR__ . '/catalogue_data.php';
file_put_contents($out, $php);

printf("Wrote %s\n  %d categories, %d products, %d sizes\n",
    $out, count($categories), count($products), count($variants));
