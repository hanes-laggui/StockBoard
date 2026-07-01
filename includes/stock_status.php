<?php
/**
 * includes/stock_status.php
 *
 * Computes a live stock status for every product using a 90-day
 * rolling sales average to derive avg_daily, days_left, and restock qty.
 */

function getDynamicStockStatuses(PDO $db): array
{
    //  All products 
    $prods = $db->query("
        SELECT id, current_stock, low_stock_threshold
        FROM   products
    ")->fetchAll(PDO::FETCH_ASSOC);

    //  90-day sales totals per product 
    $stmt = $db->prepare("
        SELECT   si.product_id,
                 SUM(si.quantity) AS total_qty
        FROM     sale_items  si
        JOIN     sales       s  ON s.id = si.sale_id
        WHERE    s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
          AND    s.status = 'Valid'
        GROUP BY si.product_id
    ");
    $stmt->execute();

    $salesMap = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $salesMap[(int)$r['product_id']] = (int)$r['total_qty'];
    }

    $result = [];

    foreach ($prods as $r) {
        $id      = (int) $r['id'];
        $stock   = (int) $r['current_stock'];
        $lowThr  = (int) $r['low_stock_threshold'];
        $warnThr = $lowThr * 2;

        // Moving-average (90-day window, same logic as prediction.php)
        $totalSold  = $salesMap[$id] ?? 0;
        $avgDaily   = round($totalSold / 90, 3);          // units per day
        $monthlyAvg = round($avgDaily * 30, 1);           // units per 30 days
        $daysLeft   = $avgDaily > 0 ? round($stock / $avgDaily, 1) : null;
        $restockQty = $avgDaily > 0
            ? max(0, (int) ceil($avgDaily * 30 - $stock))
            : 0;

        // Determine risk tier
        if ($stock <= $lowThr)   { $risk = 'low'; }
        elseif ($stock <= $warnThr) { $risk = 'warning'; }
        else                          { $risk = 'ok'; }

        $statusLabel = match ($risk) {
            'low'     => ' Low Stock',
            'warning' => ' Warning',
            default   => ' OK',
        };

        $badgeClass = match ($risk) {
            'low'     => 'b-low',
            'warning' => 'b-warn',
            default   => 'b-ok',
        };

        $result[$id] = [
            'dynamic_threshold' => $lowThr,
            'warn_threshold'    => $warnThr,
            'monthly_avg'       => $monthlyAvg,
            'avg_daily'         => $avgDaily,
            'days_left'         => $daysLeft,
            'restock_qty'       => $restockQty,
            'risk'              => $risk,
            'status_label'      => $statusLabel,
            'badge_class'       => $badgeClass,
            'setting_name'      => '90-Day Moving Average',
        ];
    }

    return $result;
}

