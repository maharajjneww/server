<?php

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Services\ResultValidationService;
use PDO;

class AdminResultController {
    public function pending(Request $request): void {
        // Auto-publish any scheduled results whose time has passed
        \App\Services\DelayedPublishingService::processPendingResults();

        $pdo = Database::getConnection();

        $stmt = $pdo->query("
            SELECT 
                r.id,
                r.market_id,
                m.name as market_name,
                r.result_date,
                r.open_panna,
                r.jodi,
                r.close_panna,
                r.status,
                r.received_at,
                r.scheduled_publish_at,
                TIMESTAMPDIFF(SECOND, NOW(), r.scheduled_publish_at) as seconds_remaining,
                r.source_id,
                COALESCE(s.name, 'DPBoss Feed') as source_name
            FROM results r
            JOIN markets m ON r.market_id = m.id
            LEFT JOIN result_sources s ON r.source_id = s.id
            WHERE r.status IN ('draft', 'pending_validation', 'queued_delay')
            ORDER BY r.scheduled_publish_at ASC
        ");
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Response::success($results, 'Pending results retrieved.');
    }

    public function create(Request $request): void {
        $marketId = (string)$request->input('market_id');
        $resultDate = (string)$request->input('result_date', date('Y-m-d'));
        $openPanna = (string)$request->input('open_panna', '***');
        $jodi = (string)$request->input('jodi', '**');
        $closePanna = (string)$request->input('close_panna', '***');
        $delayInput = $request->input('delay_minutes');
        $delayMinutes = ($delayInput !== null && $delayInput !== '') ? (int)$delayInput : 5;

        // Validation (Section 23)
        $validation = ResultValidationService::validate($openPanna, $jodi, $closePanna);
        if (!$validation['is_valid']) {
            Response::error('Validation failed.', 422, $validation['errors']);
        }

        $pdo = Database::getConnection();

        $isImmediate = ($delayMinutes <= 0);
        $status = $isImmediate ? 'published' : 'queued_delay';
        $scheduledTime = $isImmediate ? date('Y-m-d H:i:s') : date('Y-m-d H:i:s', strtotime("+$delayMinutes minutes"));

        $stmt = $pdo->prepare("
            INSERT INTO results (market_id, result_date, open_panna, jodi, close_panna, status, scheduled_publish_at, published_at)
            VALUES (:market_id, :date, :open, :jodi, :close, :status, :scheduled, " . ($isImmediate ? "NOW()" : "NULL") . ")
            ON DUPLICATE KEY UPDATE 
                id = LAST_INSERT_ID(id),
                open_panna = VALUES(open_panna),
                jodi = VALUES(jodi),
                close_panna = VALUES(close_panna),
                status = VALUES(status),
                scheduled_publish_at = VALUES(scheduled_publish_at),
                published_at = IF(VALUES(status) = 'published', NOW(), published_at),
                updated_at = NOW()
        ");
        $stmt->execute([
            ':market_id' => $marketId,
            ':date' => $resultDate,
            ':open' => $openPanna,
            ':jodi' => $jodi,
            ':close' => $closePanna,
            ':status' => $status,
            ':scheduled' => $scheduledTime,
        ]);

        $resultId = (int)$pdo->lastInsertId();

        // If published immediately, settle eligible wagers right now
        if ($isImmediate) {
            \App\Services\SettlementService::settleMarketWagers(
                $marketId,
                $resultId,
                $openPanna,
                $jodi,
                $closePanna
            );
        }

        // Record Audit Log (Section 28)
        $adminUser = $request->getUser();
        $audit = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, entity_type, entity_id, new_values)
            VALUES (:uid, 'ADMIN_CREATE_RESULT', 'result', :rid, :val)
        ");
        $audit->execute([
            ':uid' => $adminUser ? $adminUser['sub'] : null,
            ':rid' => (string)$resultId,
            ':val' => json_encode([
                'market_id' => $marketId,
                'result' => "$openPanna-$jodi-$closePanna",
                'delay_minutes' => $delayMinutes,
                'status' => $status,
                'scheduled' => $scheduledTime,
            ]),
        ]);

        $message = $isImmediate 
            ? "Result published immediately and eligible wagers settled." 
            : "Result queued for publication with $delayMinutes-minute delay.";

        Response::success([
            'result_id' => $resultId,
            'status' => $status,
            'scheduled_publish_at' => $scheduledTime,
        ], $message, 201);
    }

    public function publishNow(Request $request, string $id): void {
        $pdo = Database::getConnection();

        $resStmt = $pdo->prepare("SELECT * FROM results WHERE id = :id LIMIT 1");
        $resStmt->execute([':id' => $id]);
        $resultRow = $resStmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            UPDATE results 
            SET status = 'published', published_at = NOW() 
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);

        if ($resultRow) {
            \App\Services\SettlementService::settleMarketWagers(
                $resultRow['market_id'],
                (int)$resultRow['id'],
                (string)$resultRow['open_panna'],
                (string)$resultRow['jodi'],
                (string)$resultRow['close_panna']
            );
        }

        $adminUser = $request->getUser();
        $audit = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, entity_type, entity_id, new_values)
            VALUES (:uid, 'ADMIN_FORCE_PUBLISH', 'result', :rid, :val)
        ");
        $audit->execute([
            ':uid' => $adminUser ? $adminUser['sub'] : null,
            ':rid' => $id,
            ':val' => json_encode(['status' => 'published', 'force' => true]),
        ]);

        Response::success(null, 'Result published immediately and eligible wagers settled.');
    }

    public function schedule(Request $request, string $id): void {
        $delayMinutes = max(1, (int)$request->input('delay_minutes', 5));
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            UPDATE results 
            SET status = 'queued_delay', 
                scheduled_publish_at = DATE_ADD(NOW(), INTERVAL :mins MINUTE),
                updated_at = NOW() 
            WHERE id = :id
        ");
        $stmt->execute([
            ':mins' => $delayMinutes,
            ':id' => $id,
        ]);

        $adminUser = $request->getUser();
        $audit = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, entity_type, entity_id, new_values)
            VALUES (:uid, 'ADMIN_SCHEDULE_RESULT', 'result', :rid, :val)
        ");
        $audit->execute([
            ':uid' => $adminUser ? $adminUser['sub'] : null,
            ':rid' => $id,
            ':val' => json_encode(['delay_minutes' => $delayMinutes, 'status' => 'queued_delay']),
        ]);

        Response::success(null, "Result scheduled to automatically publish in $delayMinutes minute(s).");
    }

    public function reject(Request $request, string $id): void {
        $reason = (string)$request->input('reason', 'Administrative rejection');

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            UPDATE results 
            SET status = 'rejected' 
            WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);

        $adminUser = $request->getUser();
        $audit = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, entity_type, entity_id, new_values)
            VALUES (:uid, 'ADMIN_REJECT_RESULT', 'result', :rid, :val)
        ");
        $audit->execute([
            ':uid' => $adminUser ? $adminUser['sub'] : null,
            ':rid' => $id,
            ':val' => json_encode(['status' => 'rejected', 'reason' => $reason]),
        ]);

        Response::success(null, 'Result rejected and cancelled from publication queue.');
    }

    public function syncLiveFeed(Request $request): void {
        try {
            $synced = \App\Services\ExternalFeedService::syncFromDpboss();
            Response::success($synced, 'Live real-world results synced from DPBoss successfully.');
        } catch (\Throwable $e) {
            Response::error('Failed to sync live feed: ' . $e->getMessage(), 500);
        }
    }

    public function resetTodayResult(Request $request, string $id): void {
        $pdo = \App\Core\Database::getConnection();
        $stmt = $pdo->prepare("DELETE FROM results WHERE market_id = :id AND result_date = CURRENT_DATE()");
        $stmt->execute([':id' => $id]);

        Response::success(null, "Today's result for '$id' has been cleared and market reset to live.");
    }
}
