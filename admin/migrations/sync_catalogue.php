<?php
// ============================================================
//  Creamy Bite – Catalogue sync (runs on LIVE)
//  URL: /admin/migrations/sync_catalogue.php
//
//  Pushes the catalogue built on your Mac — categories, products and their
//  sizes — into this database. Everything else is left alone: orders,
//  customers, trade accounts, invoices and payments are never read or
//  written here. That is the whole point. Importing the entire database to
//  move a price change destroys every real order live has taken since.
//
//  Nothing is written until you press Apply, and nothing is ever deleted.
//  A product that exists here but not in the file is simply left as it is,
//  because "missing from the file" and "should be removed from the shop"
//  are not the same thing.
//
//  Products are matched by NAME. The two databases give the same product
//  different ids, so ids cannot be used to pair them up.
// ============================================================
require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

$dataFile = __DIR__ . '/catalogue_data.php';
$data     = is_file($dataFile) ? require $dataFile : null;

$apply   = ($_SERVER['REQUEST_METHOD'] === 'POST') && csrfValid();
$results = ['cat_new' => 0, 'prod_new' => 0, 'prod_upd' => 0, 'var_new' => 0, 'var_upd' => 0, 'skipped' => 0];
$plan    = [];
$errors  = [];

if ($data) {
    try {
        // ── Existing state, keyed the way we will match on ──────
        $haveCats = [];
        foreach ($pdo->query("SELECT name FROM categories") as $r) {
            $haveCats[mb_strtolower(trim($r['name']))] = true;
        }

        $haveProducts = [];
        foreach ($pdo->query("SELECT id, name, price, wholesale_price, category, available FROM products") as $r) {
            $haveProducts[mb_strtolower(trim($r['name']))] = $r;
        }

        if ($apply) {
            $pdo->beginTransaction();
        }

        // ── Categories ──────────────────────────────────────────
        $catIns = $pdo->prepare("INSERT INTO categories (name, sort_order) VALUES (:n, :s)");
        foreach ($data['categories'] as $c) {
            $key = mb_strtolower(trim($c['name']));
            if (isset($haveCats[$key])) {
                continue;
            }
            $plan[] = ['what' => 'Category', 'name' => $c['name'], 'action' => 'add', 'detail' => ''];
            $results['cat_new']++;
            if ($apply) {
                $catIns->execute(['n' => $c['name'], 's' => (int)$c['sort_order']]);
            }
        }

        // ── Products ────────────────────────────────────────────
        $prodIns = $pdo->prepare(
            "INSERT INTO products (name, description, price, wholesale_price, category, emoji, image,
                                   badge, available, nuts_allergy, trade_only)
             VALUES (:name, :description, :price, :wholesale_price, :category, :emoji, :image,
                     :badge, :available, :nuts_allergy, :trade_only)"
        );
        // Stock columns are deliberately absent from the UPDATE: stock is a
        // property of THIS shop's shelves, not of the catalogue, and copying
        // a development machine's stock levels over live counts would be
        // worse than useless.
        $prodUpd = $pdo->prepare(
            "UPDATE products SET description = :description, price = :price,
                    wholesale_price = :wholesale_price, category = :category, emoji = :emoji,
                    badge = :badge, available = :available, nuts_allergy = :nuts_allergy,
                    trade_only = :trade_only
              WHERE id = :id"
        );

        foreach ($data['products'] as $p) {
            $key = mb_strtolower(trim($p['name']));
            $row = [
                'description'     => (string)$p['description'],
                'price'           => (float)$p['price'],
                'wholesale_price' => (float)$p['wholesale_price'],
                'category'        => (string)$p['category'],
                'emoji'           => (string)$p['emoji'],
                'badge'           => (string)$p['badge'],
                'available'       => (int)$p['available'],
                'nuts_allergy'    => (int)$p['nuts_allergy'],
                'trade_only'      => (int)$p['trade_only'],
            ];

            if (!isset($haveProducts[$key])) {
                $plan[] = ['what' => 'Product', 'name' => $p['name'], 'action' => 'add',
                           'detail' => '£' . number_format((float)$p['price'], 2)
                                     . ' / trade £' . number_format((float)$p['wholesale_price'], 2)];
                $results['prod_new']++;
                if ($apply) {
                    // The image filename comes across, but the file itself does
                    // not — images are uploaded through the admin panel.
                    $prodIns->execute($row + ['name' => $p['name'], 'image' => (string)$p['image']]);
                }
            } else {
                $cur     = $haveProducts[$key];
                $changed = [];
                if (abs((float)$cur['price'] - (float)$p['price']) > 0.001) {
                    $changed[] = 'retail £' . number_format((float)$cur['price'], 2) . ' → £' . number_format((float)$p['price'], 2);
                }
                if (abs((float)$cur['wholesale_price'] - (float)$p['wholesale_price']) > 0.001) {
                    $changed[] = 'trade £' . number_format((float)$cur['wholesale_price'], 2) . ' → £' . number_format((float)$p['wholesale_price'], 2);
                }
                if (trim((string)$cur['category']) !== trim((string)$p['category'])) {
                    $changed[] = 'category ' . $cur['category'] . ' → ' . $p['category'];
                }
                if ((int)$cur['available'] !== (int)$p['available']) {
                    $changed[] = (int)$p['available'] ? 'back on sale' : 'hidden from the shop';
                }

                if ($changed) {
                    $plan[] = ['what' => 'Product', 'name' => $p['name'], 'action' => 'update',
                               'detail' => implode(', ', $changed)];
                    $results['prod_upd']++;
                    if ($apply) {
                        $prodUpd->execute($row + ['id' => (int)$cur['id']]);
                    }
                } else {
                    $results['skipped']++;
                }
            }
        }

        // ── Sizes ───────────────────────────────────────────────
        // Re-read the product ids: rows inserted a moment ago need theirs.
        $idByName = [];
        foreach ($pdo->query("SELECT id, name FROM products") as $r) {
            $idByName[mb_strtolower(trim($r['name']))] = (int)$r['id'];
        }

        $haveVars = [];
        try {
            foreach ($pdo->query("SELECT id, product_id, name, price, wholesale_price FROM product_variants") as $r) {
                $haveVars[$r['product_id'] . '|' . mb_strtolower(trim($r['name']))] = $r;
            }
        } catch (PDOException $e) {
            $errors[] = 'product_variants is missing — run update_db.php first.';
        }

        $varIns = $pdo->prepare(
            "INSERT INTO product_variants (product_id, name, price, wholesale_price, available, sort_order)
             VALUES (:pid, :name, :price, :wp, :available, :sort)"
        );
        $varUpd = $pdo->prepare(
            "UPDATE product_variants SET price = :price, wholesale_price = :wp,
                    available = :available, sort_order = :sort WHERE id = :id"
        );

        foreach ($data['variants'] as $v) {
            $pid = $idByName[mb_strtolower(trim($v['product_name']))] ?? 0;
            if ($pid <= 0) {
                continue;   // its product is not here; nothing sensible to attach it to
            }
            $key = $pid . '|' . mb_strtolower(trim($v['name']));

            if (!isset($haveVars[$key])) {
                $plan[] = ['what' => 'Size', 'name' => $v['product_name'] . ' — ' . $v['name'], 'action' => 'add',
                           'detail' => '£' . number_format((float)$v['price'], 2)
                                     . ' / trade £' . number_format((float)$v['wholesale_price'], 2)];
                $results['var_new']++;
                if ($apply) {
                    $varIns->execute([
                        'pid' => $pid, 'name' => $v['name'], 'price' => (float)$v['price'],
                        'wp' => (float)$v['wholesale_price'], 'available' => (int)$v['available'],
                        'sort' => (int)$v['sort_order'],
                    ]);
                }
            } else {
                $cur     = $haveVars[$key];
                $changed = [];
                if (abs((float)$cur['price'] - (float)$v['price']) > 0.001) {
                    $changed[] = 'retail £' . number_format((float)$cur['price'], 2) . ' → £' . number_format((float)$v['price'], 2);
                }
                if (abs((float)$cur['wholesale_price'] - (float)$v['wholesale_price']) > 0.001) {
                    $changed[] = 'trade £' . number_format((float)$cur['wholesale_price'], 2) . ' → £' . number_format((float)$v['wholesale_price'], 2);
                }
                if ($changed) {
                    $plan[] = ['what' => 'Size', 'name' => $v['product_name'] . ' — ' . $v['name'],
                               'action' => 'update', 'detail' => implode(', ', $changed)];
                    $results['var_upd']++;
                    if ($apply) {
                        $varUpd->execute([
                            'price' => (float)$v['price'], 'wp' => (float)$v['wholesale_price'],
                            'available' => (int)$v['available'], 'sort' => (int)$v['sort_order'],
                            'id' => (int)$cur['id'],
                        ]);
                    }
                } else {
                    $results['skipped']++;
                }
            }
        }

        if ($apply) {
            $pdo->commit();
        }
    } catch (PDOException $e) {
        if ($apply && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = $e->getMessage();
    }
}

$totalChanges = $results['cat_new'] + $results['prod_new'] + $results['prod_upd']
              + $results['var_new'] + $results['var_upd'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catalogue Sync</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/setup.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="admin-wrapper su-page-warm">
<div class="su-wrap">
    <div class="glass-panel su-card">
        <h1 class="su-h1">📦 Catalogue Sync</h1>
        <p class="su-lead">
            Copies categories, products and sizes from your Mac into this shop.
            Orders, customers and invoices are never touched.
        </p>

        <p class="su-env <?= IS_LOCAL ? 'su-env-local' : 'su-env-live' ?>">
            <?= IS_LOCAL ? '💻 LOCAL database' : '🌍 LIVE database' ?>
            &mdash; <?= htmlspecialchars(DB_NAME) ?>
        </p>

        <?php if (!$data): ?>
        <div class="su-failbox">
            <h2 class="su-failbox-h">No catalogue file found</h2>
            <p class="cbtr-note">
                <code>catalogue_data.php</code> is missing from this folder. Build it on
                your Mac and upload it next to this page.
            </p>
        </div>

        <?php elseif ($errors): ?>
        <div class="su-failbox">
            <h2 class="su-failbox-h">Nothing was changed</h2>
            <ul class="su-failbox-list">
                <?php foreach ($errors as $e): ?>
                <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <?php elseif ($apply): ?>
        <p class="su-result su-ok">
            ✅ Done — <?= $results['prod_new'] ?> product(s) added,
            <?= $results['prod_upd'] ?> updated,
            <?= $results['var_new'] ?> size(s) added,
            <?= $results['var_upd'] ?> size(s) updated,
            <?= $results['cat_new'] ?> category(ies) added.
        </p>
        <p class="cbtr-note">
            Product images are not copied — upload those through Products in the
            admin panel.
        </p>

        <?php elseif ($totalChanges === 0): ?>
        <p class="su-result su-ok">✅ This shop's catalogue already matches. Nothing to do.</p>

        <?php else: ?>
        <p class="su-lead">
            <strong><?= $totalChanges ?></strong> change(s) ready.
            <?= $results['skipped'] ?> item(s) already match and will be left alone.
            Built from your Mac on <?= htmlspecialchars($data['built_at']) ?>.
        </p>
        <table class="su-table">
            <?php foreach ($plan as $p): ?>
            <tr class="su-row">
                <td class="su-cell-name"><?= htmlspecialchars($p['what']) ?></td>
                <td class="su-cell-mono"><?= htmlspecialchars($p['name']) ?></td>
                <td class="su-cell-state <?= $p['action'] === 'add' ? 'su-ok' : '' ?>">
                    <?= $p['action'] === 'add' ? 'will be added' : 'will change' ?>
                    <?= $p['detail'] !== '' ? '<br><small>' . htmlspecialchars($p['detail']) . '</small>' : '' ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <form method="POST">
            <?= csrfField() ?>
            <button type="submit" class="btn-primary su-btn-back">
                Apply these <?= $totalChanges ?> change(s)
            </button>
        </form>
        <?php endif; ?>

        <a href="../index.php?tab=products" class="btn-secondary su-btn-back">
            <i class="fa-solid fa-arrow-left"></i> Back to Products
        </a>
    </div>
</div>
</body>
</html>
