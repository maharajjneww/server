<?php

namespace App\Services;

use App\Core\Database;
use PDO;

class DelayedPublishingService {
    /**
     * Scans for eligible delayed results and publishes them (Section 21 & 38).
     * Returns count of published results.
     */
    public static function processPendingResults(): int {
        $pdo = Database::getConnection();

        // 1. Fetch eligible delayed results
        $stmt = $pdo->prepare("
            SELECT r.*, m.name as market_name 
            FROM results r
            JOIN markets m ON r.market_id = m.id
            WHERE r.status = 'queued_delay' 
              AND r.scheduled_publish_at <= NOW()
        ");
        $stmt->execute();
        $eligible = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($eligible)) {
            return 0;
        }

        $publishedCount = 0;

        foreach ($eligible as $row) {
            $pdo->beginTransaction();
            try {
                // Update result to published
                $update = $pdo->prepare("
                    UPDATE results 
                    SET status = 'published', published_at = NOW()
                    WHERE id = :id
                ");
                $update->execute([':id' => $row['id']]);

                // Create push notification record (Section 17 & 18)
                $resultStr = "{$row['open_panna']}-{$row['jodi']}-{$row['close_panna']}";
                $notif = $pdo->prepare("
                    INSERT INTO notifications (type, title, message, market_id, result_info, deep_link)
                    VALUES ('NEW_RESULT', :title, :message, :market_id, :result_info, :deep_link)
                ");
                $notif->execute([
                    ':title' => "{$row['market_name']} Result Published",
                    ':message' => "Official verified result for {$row['market_name']} is now live: $resultStr.",
                    ':market_id' => $row['market_id'],
                    ':result_info' => $resultStr,
                    ':deep_link' => "app://result/{$row['market_id']}",
                ]);

                // Audit log
                $audit = $pdo->prepare("
                    INSERT INTO audit_logs (action, entity_type, entity_id, new_values)
                    VALUES ('AUTO_PUBLISH_DELAYED', 'result', :entity_id, :new_values)
                ");
                $audit->execute([
                    ':entity_id' => (string)$row['id'],
                    ':new_values' => json_encode(['result' => $resultStr, 'status' => 'published']),
                ]);

                $pdo->commit();
                $publishedCount++;

                // Settle all unsettled demo wagers for this market (Section 15 & 30)
                SettlementService::settleMarketWagers(
                    $row['market_id'],
                    (int)$row['id'],
                    (string)$row['open_panna'],
                    (string)$row['jodi'],
                    (string)$row['close_panna']
                );
            } catch (\Throwable $e) {
                $pdo->rollBack();
                error_log("Failed to auto-publish result ID {$row['id']}: " . $e->getMessage());
            }
        }

        return $publishedCount;
    }
}
