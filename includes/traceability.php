<?php
/**
 * Traceability — which batch went to which customer.
 *
 * WHY THIS EXISTS
 *   Article 18 of assimilated Regulation (EC) No 178/2002 requires a food
 *   business to be able to say who it supplied a product to. production_runs
 *   records what was made; orders records who bought what. Neither alone can
 *   answer the one question a recall turns on: "batch AD26081801 is bad — who
 *   has it?" order_batches is the link that makes that answerable.
 *
 * ONE LINE, MORE THAN ONE BATCH
 *   A case of twelve can be made up from the end of Monday's run and the start
 *   of Tuesday's. So an order line may carry several batch rows, each with its
 *   own quantity, and a line is only fully traced when those quantities add up
 *   to what was sold. Assuming one batch per line would put customers on a
 *   recall list who never received the batch, and leave off ones who did.
 *
 * WHICH LINE IS WHICH
 *   Order items are held as JSON, not as rows, so there is no line id to point
 *   at. cart_key ("19:11" = product 19, size 11) is what identifies a line, and
 *   it is already what the basket used. Orders placed before cart_key existed
 *   fall back to product:variant, which reconstructs the same string.
 */

if (!function_exists('cbTraceReady')) {
    /** Has the migration been run? Asks; never alters. */
    function cbTraceReady(PDO $pdo): bool
    {
        static $ready = null;
        if ($ready !== null) { return $ready; }
        try {
            $ready = (int)$pdo->query(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_batches'"
            )->fetchColumn() > 0;
        } catch (Throwable $e) {
            error_log('order_batches check failed: ' . $e->getMessage());
            $ready = false;
        }
        return $ready;
    }
}

if (!function_exists('cbTraceLineKey')) {
    /** The cart key for one order item, rebuilt if the order predates it. */
    function cbTraceLineKey(array $item): string
    {
        $k = trim((string)($item['cart_key'] ?? ''));
        if ($k !== '') { return $k; }
        $pid = (int)($item['product_id'] ?? 0);
        $vid = (int)($item['variant_id'] ?? 0);
        return $vid > 0 ? $pid . ':' . $vid : (string)$pid;
    }
}

if (!function_exists('cbTraceOrderLines')) {
    /**
     * One order's items as traceable lines. Pure — decodes, never queries.
     *
     * Lines of the same size merge, because two lines of the same thing are
     * one thing to trace and splitting them would ask for the same batch twice.
     */
    function cbTraceOrderLines(array $order): array
    {
        $items = json_decode((string)($order['items_json'] ?? ''), true);
        if (!is_array($items)) { return []; }

        $lines = [];
        foreach ($items as $it) {
            if (!is_array($it)) { continue; }
            $pid = (int)($it['product_id'] ?? 0);
            $qty = (int)($it['quantity'] ?? 0);
            if ($pid <= 0 || $qty <= 0) { continue; }

            $key = cbTraceLineKey($it);
            if (isset($lines[$key])) {
                $lines[$key]['qty'] += $qty;
                continue;
            }
            $lines[$key] = [
                'cart_key'     => $key,
                'product_id'   => $pid,
                'variant_id'   => (int)($it['variant_id'] ?? 0),
                'product_name' => (string)($it['name'] ?? 'Item'),
                'variant_name' => ($it['variant_name'] ?? null) ?: null,
                'qty'          => $qty,
            ];
        }
        return array_values($lines);
    }
}

if (!function_exists('cbTraceAssignmentsFor')) {
    /**
     * Batch rows for a set of orders, as [order_id][cart_key] => rows.
     *
     * Fetched for the whole page in one query rather than per line: the orders
     * list shows fifty orders of several lines each, and a query per line is
     * how a page that felt instant in testing takes eight seconds on real data.
     */
    function cbTraceAssignmentsFor(PDO $pdo, array $orderIds): array
    {
        if (!$orderIds || !cbTraceReady($pdo)) { return []; }
        $ids = array_values(array_unique(array_map('intval', $orderIds)));
        $in  = implode(',', array_fill(0, count($ids), '?'));

        try {
            $st = $pdo->prepare(
                "SELECT * FROM order_batches WHERE order_id IN ($in)
                  ORDER BY assigned_at ASC, id ASC"
            );
            $st->execute($ids);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('trace assignments failed: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['order_id']][(string)$r['cart_key']][] = $r;
        }
        return $out;
    }
}

if (!function_exists('cbTraceLineStatus')) {
    /**
     * How completely one line is traced.
     *
     * 'none' | 'partial' | 'traced' | 'over'. "over" matters as much as
     * "partial": batch quantities adding up to more than was sold means
     * somebody has double-counted, and a recall built on it would overstate
     * what is in circulation.
     *
     * @return array{state:string,assigned:int,short:int}
     */
    function cbTraceLineStatus(int $lineQty, array $assigned): array
    {
        $sum = 0;
        foreach ($assigned as $a) { $sum += (int)($a['qty'] ?? 0); }

        if ($sum <= 0)         { $state = 'none'; }
        elseif ($sum < $lineQty) { $state = 'partial'; }
        elseif ($sum > $lineQty) { $state = 'over'; }
        else                     { $state = 'traced'; }

        return ['state' => $state, 'assigned' => $sum, 'short' => max(0, $lineQty - $sum)];
    }
}

if (!function_exists('cbTraceRunsFor')) {
    /**
     * The batches that could plausibly have supplied one line.
     *
     * Runs of that flavour, newest first. A size is offered when the run names
     * one, but a run recorded without a size is still offered: early records
     * were kept that way and refusing them would leave those lines permanently
     * untraceable, which is worse than an imprecise link the owner can correct.
     */
    function cbTraceRunsFor(PDO $pdo, int $productId, int $variantId = 0, int $limit = 60): array
    {
        try {
            $st = $pdo->prepare(
                "SELECT id, batch_code, external_batch, product_name, variant_name,
                        variant_id, produced_on, best_before, output_qty, status
                   FROM production_runs
                  WHERE product_id = :pid
                    AND status IN ('completed','in_progress','on_hold')
                    AND (:vid = 0 OR variant_id IS NULL OR variant_id = :vid2)
                  ORDER BY produced_on DESC, id DESC
                  LIMIT :lim"
            );
            $st->bindValue('pid',  $productId, PDO::PARAM_INT);
            $st->bindValue('vid',  $variantId, PDO::PARAM_INT);
            $st->bindValue('vid2', $variantId, PDO::PARAM_INT);
            $st->bindValue('lim',  $limit,     PDO::PARAM_INT);
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('trace runs lookup failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('cbTraceAssign')) {
    /**
     * Link one batch to one order line.
     *
     * The product name is copied in from the RUN, not from the order, and not
     * trusted from the form: the run is the record of what was actually made,
     * and renaming a flavour next year must not rewrite what a recall says was
     * shipped.
     *
     * Re-assigning the same batch to the same line updates the quantity rather
     * than adding a second row — the unique key makes that the only sane
     * reading, and it lets someone correct a typo without first deleting.
     *
     * @return array{ok:bool,message:string}
     */
    function cbTraceAssign(PDO $pdo, int $orderId, string $cartKey, int $runId, int $qty, string $user = '', string $notes = ''): array
    {
        if (!cbTraceReady($pdo)) {
            return ['ok' => false, 'message' => 'Traceability is not set up on this server yet. Run the database update once, then come back.'];
        }
        if ($orderId <= 0 || $cartKey === '') { return ['ok' => false, 'message' => 'Which order line?']; }
        if ($qty <= 0)                        { return ['ok' => false, 'message' => 'Enter how many tubs came from this batch.']; }

        // The order must exist, and the line must really be on it. Without this
        // a hand-made request could attach a batch to a line nobody bought,
        // and the recall list would name a customer who never had it.
        $ord = $pdo->prepare("SELECT id, items_json FROM orders WHERE id = :id");
        $ord->execute(['id' => $orderId]);
        $order = $ord->fetch(PDO::FETCH_ASSOC);
        if (!$order) { return ['ok' => false, 'message' => 'That order is not here any more.']; }

        $line = null;
        foreach (cbTraceOrderLines($order) as $l) {
            if ($l['cart_key'] === $cartKey) { $line = $l; break; }
        }
        if (!$line) { return ['ok' => false, 'message' => 'That item is not on this order.']; }

        $run = $pdo->prepare("SELECT * FROM production_runs WHERE id = :id");
        $run->execute(['id' => $runId]);
        $r = $run->fetch(PDO::FETCH_ASSOC);
        if (!$r) { return ['ok' => false, 'message' => 'That batch is not here any more.']; }

        if ((int)$r['product_id'] !== (int)$line['product_id']) {
            return ['ok' => false, 'message' => 'That batch is for ' . $r['product_name'] . ', but this line is ' . $line['product_name'] . '.'];
        }
        if ($r['status'] === 'scrapped') {
            return ['ok' => false, 'message' => 'Batch ' . $r['batch_code'] . ' was scrapped, so nothing from it should have been sold. Check the batch first.'];
        }

        // Over-assigning is allowed but never silent: a real split can be
        // recorded wrongly and the total is the only thing that catches it.
        $sum = $pdo->prepare(
            "SELECT COALESCE(SUM(qty),0) FROM order_batches
              WHERE order_id = :o AND cart_key = :k AND batch_code <> :b"
        );
        $sum->execute(['o' => $orderId, 'k' => $cartKey, 'b' => $r['batch_code']]);
        $others  = (int)$sum->fetchColumn();
        $warning = ($others + $qty) > (int)$line['qty']
            ? ' Note: that is ' . (($others + $qty) - (int)$line['qty']) . ' more than the ' . $line['qty'] . ' sold on this line.'
            : '';

        try {
            $pdo->prepare(
                "INSERT INTO order_batches
                    (order_id, cart_key, product_id, variant_id, product_name, variant_name,
                     production_run_id, batch_code, external_batch, qty, assigned_by, assigned_at, notes)
                 VALUES (:o, :k, :pid, :vid, :pn, :vn, :run, :bc, :eb, :q, :by, NOW(), :notes)
                 ON DUPLICATE KEY UPDATE qty = VALUES(qty), assigned_by = VALUES(assigned_by),
                                         assigned_at = NOW(), notes = VALUES(notes)"
            )->execute([
                'o'   => $orderId,
                'k'   => $cartKey,
                'pid' => (int)$line['product_id'],
                'vid' => $line['variant_id'] ?: null,
                'pn'  => mb_substr((string)$r['product_name'], 0, 180),
                'vn'  => $r['variant_name'] ? mb_substr((string)$r['variant_name'], 0, 100) : ($line['variant_name'] ?: null),
                'run' => (int)$r['id'],
                'bc'  => (string)$r['batch_code'],
                'eb'  => $r['external_batch'] ?: null,
                'q'   => $qty,
                'by'  => mb_substr($user, 0, 120),
                'notes' => $notes !== '' ? mb_substr($notes, 0, 255) : null,
            ]);
        } catch (Throwable $e) {
            error_log('trace assign failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not save that link.'];
        }

        $label = $r['external_batch'] ?: $r['batch_code'];
        return ['ok' => true, 'message' => $qty . ' × ' . $line['product_name'] . ' linked to batch ' . $label . '.' . $warning];
    }
}

if (!function_exists('cbTraceUnassign')) {
    /** Remove one batch link. */
    function cbTraceUnassign(PDO $pdo, int $id): array
    {
        if ($id <= 0) { return ['ok' => false, 'message' => 'Which link?']; }
        $st = $pdo->prepare("SELECT batch_code, external_batch FROM order_batches WHERE id = :id");
        $st->execute(['id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { return ['ok' => false, 'message' => 'That link is already gone.']; }

        $pdo->prepare("DELETE FROM order_batches WHERE id = :id")->execute(['id' => $id]);
        return ['ok' => true, 'message' => 'Removed the link to ' . ($row['external_batch'] ?: $row['batch_code']) . '.'];
    }
}

if (!function_exists('cbTraceForward')) {
    /**
     * FORWARD TRACE — the recall call list.
     *
     * Given a batch, every customer who received it, with the contact details
     * needed to reach them and the quantity each holds. This is the output an
     * Environmental Health Officer asks for, and the list the phone calls are
     * made from, so it carries the contact columns rather than just order ids.
     *
     * Matches on either batch number, because the code a customer quotes off a
     * tub is the external one and the code in the system is the PR- one.
     */
    function cbTraceForward(PDO $pdo, string $batch): array
    {
        $batch = trim($batch);
        if ($batch === '' || !cbTraceReady($pdo)) { return []; }

        try {
            $st = $pdo->prepare(
                "SELECT ob.*, o.order_code, o.customer_name, o.customer_email, o.phone,
                        o.address, o.postcode, o.status, o.payment_status, o.created_at,
                        o.trade_user_id, o.trade_business_name,
                        pr.produced_on, pr.best_before, pr.operator
                   FROM order_batches ob
                   JOIN orders o ON o.id = ob.order_id
              LEFT JOIN production_runs pr ON pr.id = ob.production_run_id
                  WHERE ob.batch_code = :b OR ob.external_batch = :b2
                  ORDER BY o.created_at DESC, ob.id DESC"
            );
            $st->execute(['b' => $batch, 'b2' => $batch]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('forward trace failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('cbTraceBackward')) {
    /**
     * BACKWARD TRACE — everything that went into one order.
     *
     * The mirror of the above: a customer complains, and this says which
     * batches they were sent, when each was made, by whom, and what went wrong
     * on that run if anything did.
     */
    function cbTraceBackward(PDO $pdo, int $orderId): array
    {
        if ($orderId <= 0 || !cbTraceReady($pdo)) { return []; }
        try {
            $st = $pdo->prepare(
                "SELECT ob.*, pr.produced_on, pr.best_before, pr.operator, pr.status AS run_status,
                        pr.mix_temp_c, pr.pasteurise_temp_c, pr.pasteurise_mins,
                        pr.materials_used, pr.problems, pr.output_qty
                   FROM order_batches ob
              LEFT JOIN production_runs pr ON pr.id = ob.production_run_id
                  WHERE ob.order_id = :o
                  ORDER BY ob.cart_key, ob.id"
            );
            $st->execute(['o' => $orderId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('backward trace failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('cbTraceBatchList')) {
    /** Every batch that has ever been shipped, for the recall picker. */
    function cbTraceBatchList(PDO $pdo): array
    {
        if (!cbTraceReady($pdo)) { return []; }
        try {
            return $pdo->query(
                "SELECT pr.id, pr.batch_code, pr.external_batch, pr.product_name, pr.variant_name,
                        pr.produced_on, pr.best_before, pr.output_qty, pr.status,
                        COALESCE(SUM(ob.qty), 0) AS shipped,
                        COUNT(DISTINCT ob.order_id) AS orders
                   FROM production_runs pr
              LEFT JOIN order_batches ob ON ob.production_run_id = pr.id
               GROUP BY pr.id
               ORDER BY pr.produced_on DESC, pr.id DESC"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('batch list failed: ' . $e->getMessage());
            return [];
        }
    }
}
