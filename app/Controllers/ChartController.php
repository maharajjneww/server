<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use PDO;

class ChartController {
    public function show(Request $request, string $market): void {
        $pdo = Database::getConnection();

        // Verify market
        $mStmt = $pdo->prepare("SELECT id, name FROM markets WHERE id = :m OR name = :m LIMIT 1");
        $mStmt->execute([':m' => $market]);
        $marketData = $mStmt->fetch(PDO::FETCH_ASSOC);

        if (!$marketData) {
            Response::notFound("Market '$market' not found.");
        }

        // Fetch past 12 weeks of data grouped by date
        $stmt = $pdo->prepare("
            SELECT 
                result_date,
                DATE_FORMAT(result_date, '%a') as day_name,
                WEEK(result_date, 1) as week_num,
                YEAR(result_date) as year_num,
                open_panna,
                jodi,
                close_panna
            FROM results
            WHERE market_id = :market_id AND status = 'published'
            ORDER BY result_date DESC
            LIMIT 90
        ");
        $stmt->execute([':market_id' => $marketData['id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group into weekly structure
        $weeksMap = [];
        foreach ($rows as $row) {
            $weekKey = $row['year_num'] . '-W' . $row['week_num'];
            if (!isset($weeksMap[$weekKey])) {
                $weeksMap[$weekKey] = [
                    'week_key' => $weekKey,
                    'days' => [],
                ];
            }
            $weeksMap[$weekKey]['days'][] = [
                'date' => $row['result_date'],
                'day' => $row['day_name'],
                'open' => $row['open_panna'],
                'jodi' => $row['jodi'],
                'close' => $row['close_panna'],
            ];
        }

        Response::success([
            'market' => $marketData,
            'weeks' => array_values($weeksMap),
        ], 'Chart matrix data retrieved.');
    }
}
