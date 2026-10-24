<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use PDO;

class MarketController {
    public function index(Request $request): void {
        \App\Services\ExternalFeedService::autoSyncIfNeeded(60);
        \App\Services\DelayedPublishingService::processPendingResults();
        $pdo = Database::getConnection();
        $statusFilter = $request->input('status');

        $sql = "
            SELECT 
                m.id, 
                m.name, 
                TIME_FORMAT(m.open_time, '%h:%i %p') as open_time, 
                TIME_FORMAT(m.close_time, '%h:%i %p') as close_time, 
                CASE 
                    WHEN r.status = 'published' AND r.close_panna != '***' THEN 'closed'
                    WHEN m.close_time < m.open_time THEN
                        CASE 
                            WHEN CURRENT_TIME() >= m.open_time OR CURRENT_TIME() <= m.close_time THEN 'running'
                            ELSE 'upcoming'
                        END
                    WHEN CURRENT_TIME() < m.open_time THEN 'upcoming'
                    WHEN CURRENT_TIME() >= m.open_time AND CURRENT_TIME() <= m.close_time THEN 'running'
                    ELSE 'closed'
                END as status, 
                m.is_featured,
                COALESCE(r.open_panna, '***') as open_panna,
                COALESCE(r.jodi, '**') as jodi,
                COALESCE(r.close_panna, '***') as close_panna
            FROM markets m
            LEFT JOIN results r ON r.market_id = m.id AND r.result_date = CURRENT_DATE() AND r.status = 'published'
            WHERE m.is_active = TRUE
        ";

        $params = [];
        if ($statusFilter && in_array($statusFilter, ['running', 'upcoming', 'closed'])) {
            $sql .= " HAVING status = :status";
            $params[':status'] = $statusFilter;
        }

        $sql .= " ORDER BY m.sort_order ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $markets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Response::success($markets, 'Markets retrieved.');
    }

    public function show(Request $request, string $id): void {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            SELECT 
                m.id, 
                m.name, 
                TIME_FORMAT(m.open_time, '%h:%i %p') as open_time, 
                TIME_FORMAT(m.close_time, '%h:%i %p') as close_time, 
                CASE 
                    WHEN r.status = 'published' AND r.close_panna != '***' THEN 'closed'
                    WHEN m.close_time < m.open_time THEN
                        CASE 
                            WHEN CURRENT_TIME() >= m.open_time OR CURRENT_TIME() <= m.close_time THEN 'running'
                            ELSE 'upcoming'
                        END
                    WHEN CURRENT_TIME() < m.open_time THEN 'upcoming'
                    WHEN CURRENT_TIME() >= m.open_time AND CURRENT_TIME() <= m.close_time THEN 'running'
                    ELSE 'closed'
                END as status, 
                m.is_featured,
                m.delay_minutes,
                COALESCE(r.open_panna, '***') as open_panna,
                COALESCE(r.jodi, '**') as jodi,
                COALESCE(r.close_panna, '***') as close_panna
            FROM markets m
            LEFT JOIN results r ON r.market_id = m.id AND r.result_date = CURRENT_DATE() AND r.status = 'published'
            WHERE m.id = :id AND m.is_active = TRUE
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $market = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$market) {
            Response::notFound("Market '$id' not found.");
        }

        // Fetch past 7 days results for this market
        $historyStmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(result_date, '%d-%m-%Y') as date,
                open_panna,
                jodi,
                close_panna
            FROM results
            WHERE market_id = :id AND status = 'published'
            ORDER BY result_date DESC
            LIMIT 7
        ");
        $historyStmt->execute([':id' => $id]);
        $market['past_results'] = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

        Response::success($market, 'Market details retrieved.');
    }
}
