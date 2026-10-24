<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use PDO;

class ResultController {
    public function latest(Request $request): void {
        $pdo = Database::getConnection();

        $stmt = $pdo->query("
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
            ORDER BY r.result_date DESC, r.published_at DESC
            LIMIT 30
        ");
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Response::success($results, 'Latest results retrieved.');
    }

    public function history(Request $request): void {
        $pdo = Database::getConnection();

        $marketId = $request->input('market_id');
        $year = (int)($request->input('year') ?: date('Y'));
        $month = (int)($request->input('month') ?: date('m'));
        $page = max(1, (int)($request->input('page') ?: 1));
        $limit = min(100, max(1, (int)($request->input('limit') ?: 60)));
        $offset = ($page - 1) * $limit;

        $sql = "
            SELECT 
                r.id,
                r.market_id,
                m.name as market_name,
                DATE_FORMAT(r.result_date, '%d/%m/%Y') as date,
                DATE_FORMAT(r.result_date, '%a') as day,
                r.open_panna,
                r.jodi,
                r.close_panna,
                TIME_FORMAT(r.published_at, '%h:%i %p') as published_at
            FROM results r
            JOIN markets m ON r.market_id = m.id
            WHERE r.status = 'published'
              AND YEAR(r.result_date) = :year
              AND MONTH(r.result_date) = :month
        ";

        $params = [
            ':year' => $year,
            ':month' => $month,
        ];

        if (!empty($marketId) && $marketId !== 'ALL' && $marketId !== 'ALL MARKETS') {
            $sql .= " AND (r.market_id = :mid OR m.name = :mname)";
            $params[':mid'] = $marketId;
            $params[':mname'] = $marketId;
        }

        $sql .= " ORDER BY r.result_date DESC, r.published_at DESC LIMIT $limit OFFSET $offset";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Response::success([
            'records' => $records,
            'page' => $page,
            'limit' => $limit,
            'year' => $year,
            'month' => $month,
        ], 'Result history retrieved.');
    }

    public function byMarketAndDate(Request $request, string $market, string $date): void {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            SELECT 
                r.id,
                r.market_id,
                m.name as market_name,
                DATE_FORMAT(r.result_date, '%d-%m-%Y') as date,
                r.open_panna,
                r.jodi,
                r.close_panna,
                TIME_FORMAT(r.published_at, '%h:%i %p') as published_at
            FROM results r
            JOIN markets m ON r.market_id = m.id
            WHERE (r.market_id = :market OR m.name = :market)
              AND (r.result_date = :date OR DATE_FORMAT(r.result_date, '%d-%m-%Y') = :date)
              AND r.status = 'published'
            LIMIT 1
        ");
        $stmt->execute([':market' => $market, ':date' => $date]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            Response::notFound("Result not found for market '$market' on date '$date'.");
        }

        Response::success($result, 'Result retrieved.');
    }
}
