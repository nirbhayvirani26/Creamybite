<?php
/**
 * Production runs — create, update, delete, and push output to stock.
 */

$GLOBALS['ADMIN_GUARD_JSON'] = true;
require_once __DIR__ . '/../_guard.php';
header('Content-Type: application/json');
csrfCheckJson();
require_once __DIR__ . '/../_permissions.php';
adminRequire('production');

require_once __DIR__ . '/../../includes/production.php';

function cbprOut(array $payload, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

if (!cbProdReady($pdo)) {
    cbprOut([
        'success' => false,
        'message' => 'Production is not set up on this server yet. Run the database update once, then come back.',
    ], 503);
}

/** A whole number, never negative. Blank counts as 0. */
function cbprInt(string $key, int $max = 1000000): int
{
    $v = $_POST[$key] ?? 0;
    if (!is_scalar($v)) { return 0; }
    $n = (int)$v;
    return max(0, min($max, $n));
}

/** A date, or null. Refuses anything that is not YYYY-MM-DD. */
function cbprDate(string $key): ?string
{
    $v = $_POST[$key] ?? '';
    if (!is_scalar($v)) { return null; }
    $v = trim((string)$v);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
}

/** A time, or null. */
function cbprTime(string $key): ?string
{
    $v = $_POST[$key] ?? '';
    if (!is_scalar($v)) { return null; }
    $v = trim((string)$v);
    return preg_match('/^\d{2}:\d{2}$/', $v) ? $v . ':00' : null;
}

/** A temperature, or null. Ice cream work runs from about -40 to +100. */
function cbprTemp(string $key): ?float
{
    $v = $_POST[$key] ?? '';
    if (!is_scalar($v) || trim((string)$v) === '') { return null; }
    if (!is_numeric($v)) { return null; }
    $n = (float)$v;
    return ($n >= -60 && $n <= 150) ? round($n, 1) : null;
}

function cbprText(string $key, int $max = 4000): ?string
{
    $v = $_POST[$key] ?? '';
    if (!is_scalar($v)) { return null; }
    $v = trim((string)$v);
    return $v === '' ? null : mb_substr($v, 0, $max);
}

$action = trim((string)($_POST['action'] ?? ''));
$user   = (string)($_SESSION['admin_username'] ?? ($_SESSION['staff_name'] ?? 'admin'));

// ── What should the external batch number be? ────────────
// Asked by the form whenever the flavour or the date changes. Read-only: it
// suggests, it never reserves, so two people filling the form at once both see
// the same suggestion and the unique key decides who keeps it.
if ($action === 'suggest_batch') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $date      = cbprDate('produced_on') ?? date('Y-m-d');
    if ($productId <= 0) { cbprOut(['success' => false, 'message' => 'Choose a product first.'], 422); }

    $st = $pdo->prepare("SELECT name FROM products WHERE id = :id");
    $st->execute(['id' => $productId]);
    $name = (string)$st->fetchColumn();
    if ($name === '') { cbprOut(['success' => false, 'message' => 'That product is not here.'], 404); }

    cbprOut(['success' => true, 'external_batch' => cbProdNextExternalBatch($pdo, $name, $date)]);
}

switch ($action) {

    // ── Record a run ─────────────────────────────────────────
    case 'create':
    case 'update': {
        $id        = (int)($_POST['id'] ?? 0);
        $productId = (int)($_POST['product_id'] ?? 0);
        $variantId = (int)($_POST['variant_id'] ?? 0);
        $status    = trim((string)($_POST['status'] ?? 'in_progress'));
        $producedOn = cbprDate('produced_on') ?? date('Y-m-d');

        if (!isset(cbProdStatuses()[$status])) { $status = 'in_progress'; }

        // The product comes from the catalogue, so the name is looked up here
        // rather than trusted from the form — and stored alongside the id so a
        // later rename cannot rewrite what this batch says it was.
        $productName = '';
        $variantName = null;
        if ($productId > 0) {
            $st = $pdo->prepare("SELECT name FROM products WHERE id = :id");
            $st->execute(['id' => $productId]);
            $productName = (string)$st->fetchColumn();
        }
        if ($productName === '') {
            cbprOut(['success' => false, 'message' => 'Choose which product this run is for.'], 422);
        }
        if ($variantId > 0) {
            $st = $pdo->prepare("SELECT name FROM product_variants WHERE id = :id AND product_id = :p");
            $st->execute(['id' => $variantId, 'p' => $productId]);
            $variantName = $st->fetchColumn() ?: null;
            if ($variantName === null) { $variantId = 0; }   // size not on this product
        }

        // The external batch number. Suggested by the form, but typed over
        // freely — a code already printed on a tub outranks anything generated
        // here, and refusing it would mean the record disagreeing with the
        // physical stock.
        $external = strtoupper(trim((string)($_POST['external_batch'] ?? '')));
        if ($external !== '') {
            if (!preg_match('/^[A-Z0-9][A-Z0-9\-\/]{2,39}$/', $external)) {
                cbprOut(['success' => false, 'message' => 'A batch number can use letters, numbers, dashes and slashes only, and needs at least three characters.'], 422);
            }
            // Enforced here as well as by the unique key, so the owner gets a
            // sentence rather than a database error.
            $clash = $pdo->prepare("SELECT batch_code FROM production_runs WHERE external_batch = :e AND id <> :id");
            $clash->execute(['e' => $external, 'id' => $id]);
            if ($other = $clash->fetchColumn()) {
                cbprOut(['success' => false, 'message' => 'Batch number ' . $external . ' is already used by run ' . $other . '. Two runs cannot share one number — a recall could not tell them apart.'], 422);
            }
        }

        $planned = cbprInt('planned_qty');
        $output  = cbprInt('output_qty');
        $reject  = cbprInt('reject_qty');

        // Output plus rejects above what was planned is not impossible — an
        // over-run happens — but it is worth saying out loud rather than
        // recording in silence.
        $warning = null;
        if ($planned > 0 && ($output + $reject) > $planned * 2) {
            cbprOut([
                'success' => false,
                'message' => 'That output is more than double what was planned. Check the figures — if it is right, raise the planned quantity to match.',
            ], 422);
        }
        if ($status === 'completed' && $output <= 0) {
            cbprOut(['success' => false, 'message' => 'A completed run needs an output figure. Use "Scrapped" if nothing was usable.'], 422);
        }

        $fields = [
            'external_batch'    => $external !== '' ? $external : null,
            'product_id'        => $productId ?: null,
            'variant_id'        => $variantId ?: null,
            'product_name'      => mb_substr($productName, 0, 180),
            'variant_name'      => $variantName ? mb_substr((string)$variantName, 0, 100) : null,
            'produced_on'       => $producedOn,
            'started_at'        => cbprTime('started_at'),
            'finished_at'       => cbprTime('finished_at'),
            'planned_qty'       => $planned,
            'output_qty'        => $output,
            'reject_qty'        => $reject,
            'unit_label'        => mb_substr(trim((string)($_POST['unit_label'] ?? 'tubs')) ?: 'tubs', 0, 40),
            'best_before'       => cbprDate('best_before'),
            'mix_temp_c'        => cbprTemp('mix_temp_c'),
            'pasteurise_temp_c' => cbprTemp('pasteurise_temp_c'),
            'pasteurise_mins'   => cbprInt('pasteurise_mins', 600) ?: null,
            'operator'          => cbprText('operator', 120),
            'status'            => $status,
            'materials_used'    => cbprText('materials_used'),
            'changes_made'      => cbprText('changes_made'),
            'problems'          => cbprText('problems'),
            'notes'             => cbprText('notes'),
        ];

        try {
            if ($action === 'create') {
                $fields['batch_code'] = cbProdNextBatchCode($pdo, $producedOn);
                $fields['created_by'] = mb_substr($user, 0, 120);
                $cols = array_keys($fields);
                $sql  = "INSERT INTO production_runs (`" . implode('`,`', $cols) . "`, created_at)
                         VALUES (:" . implode(', :', $cols) . ", NOW())";
                $pdo->prepare($sql)->execute($fields);
                cbprOut([
                    'success'    => true,
                    'message'    => 'Batch ' . $fields['batch_code'] . ' recorded.',
                    'batch_code' => $fields['batch_code'],
                    'id'         => (int)$pdo->lastInsertId(),
                ]);
            }

            if ($id <= 0) { cbprOut(['success' => false, 'message' => 'Which run?'], 422); }
            $sets = [];
            foreach (array_keys($fields) as $c) { $sets[] = "`$c` = :$c"; }
            $fields['id'] = $id;
            $pdo->prepare(
                "UPDATE production_runs SET " . implode(', ', $sets) . ", updated_at = NOW() WHERE id = :id"
            )->execute($fields);
            cbprOut(['success' => true, 'message' => 'Saved.']);

        } catch (Throwable $e) {
            error_log('Production save failed: ' . $e->getMessage());
            cbprOut(['success' => false, 'message' => 'Could not save that run.'], 500);
        }
    }

    // ── Put the output into stock ────────────────────────────
    case 'add_to_stock': {
        $res = cbProdAddToStock($pdo, (int)($_POST['id'] ?? 0));
        cbprOut(['success' => $res['ok'], 'message' => $res['message']], $res['ok'] ? 200 : 422);
    }

    // ── Delete ───────────────────────────────────────────────
    case 'delete': {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { cbprOut(['success' => false, 'message' => 'Which run?'], 422); }

        // A run whose output is already in stock cannot simply vanish — the
        // stock it added would stay, with nothing left to explain it.
        $st = $pdo->prepare("SELECT stock_added, batch_code FROM production_runs WHERE id = :id");
        $st->execute(['id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { cbprOut(['success' => false, 'message' => 'That run is already gone.'], 404); }
        if ((int)$row['stock_added'] === 1) {
            cbprOut([
                'success' => false,
                'message' => 'This run has been added to stock, so deleting it would leave stock nobody can account for. '
                           . 'Mark it Scrapped instead, and correct the stock on the Stock page.',
            ], 422);
        }

        $pdo->prepare("DELETE FROM production_runs WHERE id = :id")->execute(['id' => $id]);
        cbprOut(['success' => true, 'message' => 'Batch ' . $row['batch_code'] . ' deleted.']);
    }

    default:
        cbprOut(['success' => false, 'message' => 'Unknown action.'], 400);
}
