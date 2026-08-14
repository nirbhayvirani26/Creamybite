<?php
// ============================================================
//  Creamy Bite – Order Taking Settings
//  Controls whether delivery / collection orders are accepted,
//  independently for trade customers and normal customers.
//
//  Four switches in total:
//    taking_retail_delivery    taking_retail_collection
//    taking_trade_delivery     taking_trade_collection
//
//  Everything defaults to ON, and any database problem is
//  treated as ON, so orders are never blocked by accident.
// ============================================================

const ORDER_AUDIENCES = ['retail', 'trade'];
const ORDER_METHODS   = ['delivery', 'collection'];

function orderSettingKey(string $audience, string $method): string {
    return 'taking_' . $audience . '_' . $method;
}

function orderAudienceLabel(string $audience): string {
    return $audience === 'trade' ? 'Trade customers' : 'Normal customers';
}

/** Which set of switches applies to the person currently checking out. */
function currentOrderAudience(): string {
    return empty($_SESSION['trade_user']) ? 'retail' : 'trade';
}

/** Create the settings table and seed the four switches (once per request). */
function ensureOrderSettings(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
            `setting_key`   VARCHAR(64) NOT NULL PRIMARY KEY,
            `setting_value` VARCHAR(255) NOT NULL DEFAULT '',
            `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $seed = $pdo->prepare(
            "INSERT IGNORE INTO `settings` (setting_key, setting_value) VALUES (:k, '1')"
        );
        foreach (ORDER_AUDIENCES as $audience) {
            foreach (ORDER_METHODS as $method) {
                $seed->execute(['k' => orderSettingKey($audience, $method)]);
            }
        }
    } catch (PDOException $e) {
        error_log('Order settings init error: ' . $e->getMessage());
    }
}

/**
 * All four switches.
 * Returns ['retail' => ['delivery' => bool, 'collection' => bool], 'trade' => [...]]
 */
function getOrderSettings(PDO $pdo): array {
    $out = [];
    foreach (ORDER_AUDIENCES as $audience) {
        foreach (ORDER_METHODS as $method) {
            $out[$audience][$method] = true;   // fail open
        }
    }

    ensureOrderSettings($pdo);
    try {
        $rows = $pdo->query(
            "SELECT setting_key, setting_value FROM `settings`
             WHERE setting_key LIKE 'taking\\_%'"
        )->fetchAll();
        foreach ($rows as $row) {
            foreach (ORDER_AUDIENCES as $audience) {
                foreach (ORDER_METHODS as $method) {
                    if ($row['setting_key'] === orderSettingKey($audience, $method)) {
                        $out[$audience][$method] = ($row['setting_value'] === '1');
                    }
                }
            }
        }
    } catch (PDOException $e) {
        error_log('Order settings read error: ' . $e->getMessage());
    }

    return $out;
}

/** Is this fulfilment method currently accepted for this kind of customer? */
function isOrderMethodEnabled(PDO $pdo, string $audience, string $method): bool {
    if (!in_array($audience, ORDER_AUDIENCES, true)) return true;
    if (!in_array($method,   ORDER_METHODS,   true)) return true;

    $settings = getOrderSettings($pdo);
    return !empty($settings[$audience][$method]);
}

/** Flip one switch. Returns false if the audience/method is not recognised. */
function setOrderMethodEnabled(PDO $pdo, string $audience, string $method, bool $enabled): bool {
    if (!in_array($audience, ORDER_AUDIENCES, true)) return false;
    if (!in_array($method,   ORDER_METHODS,   true)) return false;

    ensureOrderSettings($pdo);
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO `settings` (setting_key, setting_value) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = :v2"
        );
        return $stmt->execute([
            'k'  => orderSettingKey($audience, $method),
            'v'  => $enabled ? '1' : '0',
            'v2' => $enabled ? '1' : '0',
        ]);
    } catch (PDOException $e) {
        error_log('Order settings write error: ' . $e->getMessage());
        return false;
    }
}
