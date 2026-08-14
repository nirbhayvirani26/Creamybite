<?php
/**
 * Production runs — the domain layer.
 *
 * A batch record. What was made, how much came out, what went in, what went
 * wrong, and what was done differently. The SOP checklist on the Documents
 * page lists "Batch production record" as the thing that makes a recall
 * possible; this is that record, kept in the system rather than on a clipboard.
 *
 * WHY THE PRODUCT NAME IS COPIED IN
 *   product_id points at the catalogue, but product_name and variant_name are
 *   stored alongside it. Renaming "Kesar Pista" next spring must not silently
 *   rewrite what last summer's batch says it was. The id is for linking; the
 *   name is the record.
 *
 * STOCK IS NEVER MOVED AUTOMATICALLY
 *   Finishing a run does not touch stock_qty. The owner presses a button, once,
 *   and the run is marked so it cannot be counted twice. Production and stock
 *   drifting apart is annoying; stock silently doubling because a run was
 *   edited twice is worse.
 */

if (!function_exists('cbProdReady')) {
    /** Has the migration been run? Asks; never alters. */
    function cbProdReady(PDO $pdo): bool
    {
        static $ready = null;
        if ($ready !== null) { return $ready; }
        try {
            $ready = (int)$pdo->query(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'production_runs'"
            )->fetchColumn() > 0;
        } catch (Throwable $e) {
            error_log('production_runs check failed: ' . $e->getMessage());
            $ready = false;
        }
        return $ready;
    }
}

if (!function_exists('cbProdStatuses')) {
    /** The states a run can be in, with what each one means in the shop. */
    function cbProdStatuses(): array
    {
        return [
            'planned'     => 'Planned — not started yet',
            'in_progress' => 'In progress — being made now',
            'completed'   => 'Completed — finished and counted',
            'on_hold'     => 'On hold — stopped, waiting on something',
            'scrapped'    => 'Scrapped — not usable, nothing to sell',
        ];
    }
}

if (!function_exists('cbProdNextBatchCode')) {
    /**
     * PR-YYMMDD-01, counting up within the day.
     *
     * Readable on a tub and on a spreadsheet, sortable, and it says when the
     * batch was made without anyone having to look it up — which is the whole
     * point when a customer rings with a code and a question.
     */
    function cbProdNextBatchCode(PDO $pdo, string $date = ''): string
    {
        $d    = $date !== '' ? $date : date('Y-m-d');
        $stem = 'PR-' . date('ymd', strtotime($d)) . '-';
        try {
            $st = $pdo->prepare(
                "SELECT batch_code FROM production_runs
                 WHERE batch_code LIKE :s ORDER BY batch_code DESC LIMIT 1"
            );
            $st->execute(['s' => $stem . '%']);
            $last = (string)$st->fetchColumn();
        } catch (Throwable $e) {
            $last = '';
        }
        $n = $last !== '' ? ((int)substr($last, -2)) + 1 : 1;
        return $stem . str_pad((string)$n, 2, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('cbProdCatalogue')) {
    /**
     * Every product and its sizes, for the picker. Read from the catalogue so
     * a new flavour appears here the moment it is added on the Products page —
     * there is no second list to keep in step.
     */
    function cbProdCatalogue(PDO $pdo): array
    {
        try {
            $rows = $pdo->query(
                "SELECT p.id AS product_id, p.name AS product_name,
                        v.id AS variant_id, v.name AS variant_name, v.case_qty
                 FROM products p
                 LEFT JOIN product_variants v ON v.product_id = p.id
                 ORDER BY p.name, v.sort_order, v.name"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('production catalogue failed: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $pid = (int)$r['product_id'];
            if (!isset($out[$pid])) {
                $out[$pid] = ['id' => $pid, 'name' => $r['product_name'], 'variants' => []];
            }
            if (!empty($r['variant_id'])) {
                $out[$pid]['variants'][] = [
                    'id'       => (int)$r['variant_id'],
                    'name'     => (string)$r['variant_name'],
                    'case_qty' => (int)($r['case_qty'] ?? 0),
                ];
            }
        }
        return array_values($out);
    }
}

if (!function_exists('cbProdList')) {
    /** Runs, newest first. */
    function cbProdList(PDO $pdo, string $status = '', int $limit = 200): array
    {
        if (!cbProdReady($pdo)) { return []; }
        try {
            if ($status !== '' && isset(cbProdStatuses()[$status])) {
                $st = $pdo->prepare(
                    "SELECT * FROM production_runs WHERE status = :s
                     ORDER BY produced_on DESC, id DESC LIMIT :l"
                );
                $st->bindValue('s', $status);
                $st->bindValue('l', $limit, PDO::PARAM_INT);
                $st->execute();
                return $st->fetchAll(PDO::FETCH_ASSOC);
            }
            $st = $pdo->prepare(
                "SELECT * FROM production_runs ORDER BY produced_on DESC, id DESC LIMIT :l"
            );
            $st->bindValue('l', $limit, PDO::PARAM_INT);
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('production list failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('cbProdYield')) {
    /**
     * Good units out of what was planned, as a percentage.
     *
     * The number worth watching: a run that planned 100 and yielded 82 has lost
     * nearly a fifth of a day's work somewhere, and the problems field is where
     * the reason should be.
     */
    function cbProdYield(array $run): ?float
    {
        $planned = (int)($run['planned_qty'] ?? 0);
        if ($planned <= 0) { return null; }
        return round(((int)($run['output_qty'] ?? 0) / $planned) * 100, 1);
    }
}

if (!function_exists('cbProdSummary')) {
    /** Totals for a period, for the strip at the top of the page. */
    function cbProdSummary(PDO $pdo, int $days = 30): array
    {
        $empty = ['runs' => 0, 'output' => 0, 'rejects' => 0, 'yield' => null, 'problems' => 0];
        if (!cbProdReady($pdo)) { return $empty; }
        try {
            $st = $pdo->prepare(
                "SELECT COUNT(*) AS runs,
                        COALESCE(SUM(output_qty),0)  AS output,
                        COALESCE(SUM(reject_qty),0)  AS rejects,
                        COALESCE(SUM(planned_qty),0) AS planned,
                        SUM(problems IS NOT NULL AND problems <> '') AS problems
                 FROM production_runs
                 WHERE produced_on >= DATE_SUB(CURDATE(), INTERVAL :d DAY)
                   AND status <> 'planned'"
            );
            $st->bindValue('d', $days, PDO::PARAM_INT);
            $st->execute();
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $planned = (int)($r['planned'] ?? 0);
            return [
                'runs'     => (int)($r['runs'] ?? 0),
                'output'   => (int)($r['output'] ?? 0),
                'rejects'  => (int)($r['rejects'] ?? 0),
                'problems' => (int)($r['problems'] ?? 0),
                'yield'    => $planned > 0 ? round(((int)$r['output'] / $planned) * 100, 1) : null,
            ];
        } catch (Throwable $e) {
            error_log('production summary failed: ' . $e->getMessage());
            return $empty;
        }
    }
}

if (!function_exists('cbProdAddToStock')) {
    /**
     * Put a finished run's output into stock, once.
     *
     * Guarded by stock_added, and the update is conditional on that flag still
     * being 0, so two clicks in quick succession cannot both land. total_stock
     * moves with stock_qty because the stock model is
     * total = on hand + sold + damaged, and this is genuinely new stock rather
     * than a correction.
     *
     * @return array{ok:bool,message:string}
     */
    function cbProdAddToStock(PDO $pdo, int $runId): array
    {
        if (!cbProdReady($pdo)) {
            return ['ok' => false, 'message' => 'Production is not set up on this server yet.'];
        }

        $st = $pdo->prepare("SELECT * FROM production_runs WHERE id = :id");
        $st->execute(['id' => $runId]);
        $run = $st->fetch(PDO::FETCH_ASSOC);

        if (!$run)                          { return ['ok' => false, 'message' => 'That run is not here.']; }
        if ((int)$run['stock_added'] === 1) { return ['ok' => false, 'message' => 'This run has already been added to stock.']; }
        if ($run['status'] !== 'completed') { return ['ok' => false, 'message' => 'Mark the run completed first — only a finished run has a real output figure.']; }
        if ((int)$run['output_qty'] <= 0)   { return ['ok' => false, 'message' => 'There is no output on this run to add.']; }
        if (empty($run['product_id']))      { return ['ok' => false, 'message' => 'This run is not linked to a product, so there is nothing to add it to.']; }

        $qty = (int)$run['output_qty'];

        try {
            $pdo->beginTransaction();

            // Conditional on stock_added still being 0: if another request got
            // here first this affects no rows and the whole thing rolls back.
            $mark = $pdo->prepare(
                "UPDATE production_runs SET stock_added = 1, updated_at = NOW()
                 WHERE id = :id AND stock_added = 0"
            );
            $mark->execute(['id' => $runId]);
            if ($mark->rowCount() === 0) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'This run has already been added to stock.'];
            }

            $pdo->prepare(
                "UPDATE products
                 SET stock_qty   = stock_qty   + :q,
                     total_stock = total_stock + :q2
                 WHERE id = :pid"
            )->execute(['q' => $qty, 'q2' => $qty, 'pid' => (int)$run['product_id']]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('Adding production to stock failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not update the stock. Nothing was changed.'];
        }

        return [
            'ok'      => true,
            'message' => $qty . ' added to ' . $run['product_name'] . '.',
        ];
    }
}
