<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use PDO;

class HomeController {
    public function index(Request $request): void {
        $pdo = Database::getConnection();

        // Auto-sync with live DPBoss feed if last sync > 3 minutes ago
        // 0. Auto-sync external feed every 60 seconds (1 minute)
        \App\Services\ExternalFeedService::autoSyncIfNeeded(60);

        // Process any delayed results that have reached their scheduled publish time
        \App\Services\DelayedPublishingService::processPendingResults();

        // 1. Fetch Markets with their latest published result
        $marketsStmt = $pdo->query("
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
                COALESCE(r.close_panna, '***') as close_panna,
                COALESCE(r.published_at, '') as last_published_at
            FROM markets m
            LEFT JOIN results r ON r.market_id = m.id AND r.result_date = CURRENT_DATE() AND r.status = 'published'
            WHERE m.is_active = TRUE
            ORDER BY m.sort_order ASC, m.open_time ASC
        ");
        $markets = $marketsStmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. Announcements
        $announcementsStmt = $pdo->query("
            SELECT title, message, priority 
            FROM announcements 
            WHERE is_active = TRUE 
            ORDER BY priority DESC, created_at DESC 
            LIMIT 5
        ");
        $announcements = $announcementsStmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Latest Published Results
        $latestResultsStmt = $pdo->query("
            SELECT 
                r.id,
                r.market_id,
                m.name as market_name,
                DATE_FORMAT(r.result_date, '%d-%m-%Y') as date,
                r.open_panna,
                r.jodi,
                r.close_panna,
                TIME_FORMAT(r.published_at, '%h:%i %p') as published_at,
                (r.result_date = CURRENT_DATE()) as is_live
            FROM results r
            JOIN markets m ON r.market_id = m.id
            WHERE r.status = 'published'
            ORDER BY r.published_at DESC
            LIMIT 10
        ");
        $latestResults = $latestResultsStmt->fetchAll(PDO::FETCH_ASSOC);

        Response::success([
            'markets' => $markets,
            'announcements' => $announcements,
            'latest_results' => $latestResults,
            'server_time' => date('Y-m-d H:i:s'),
            'app_version' => '1.0.0',
        ], 'Home data retrieved successfully.');
    }
}
