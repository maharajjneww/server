<?php

namespace App\Services;

use App\Core\Database;
use PDO;

class ExternalFeedService {
    private const SOURCE_URL = 'https://dpbossnet.me';

    private const MARKET_MAP = [
        'KALYAN MORNING' => 'mkt_kalyan_morning',
        'MILAN MORNING' => 'mkt_milan_morning',
        'SRIDEVI' => 'mkt_sridevi',
        'MAIN BAZAR MORNING' => 'mkt_main_bazar_morning',
        'MADHURI' => 'mkt_madhuri',
        'SRIDEVI MORNING' => 'mkt_sridevi_morning',
        'MAHARANI' => 'mkt_maharani',
        'KALYAN' => 'mkt_kalyan',
        'MILAN DAY' => 'mkt_milan_day',
        'RAJDHANI DAY' => 'mkt_rajdhani_day',
        'TIME BAZAR' => 'mkt_time_bazar',
        'MADHUR DAY' => 'mkt_madhur_day',
        'KALYAN NIGHT' => 'mkt_kalyan_night',
        'MAIN BAZAR' => 'mkt_main_bazar',
        'MILAN NIGHT' => 'mkt_milan_night',
        'SUPREME NIGHT' => 'mkt_supreme_night',
        'SUNDAY BAZAR' => 'mkt_sunday_bazar',
        'MAIN SRIDEVI DAY' => 'mkt_main_sridevi_day',
        'NEW TIME BAZAR' => 'mkt_new_time_bazar',
        'PADMAVATHI' => 'mkt_padmavathi',
        'MADHUR MORNING' => 'mkt_madhur_morning',
        'PRABHAT' => 'mkt_prabhat',
        'SUPER DAY' => 'mkt_super_day',
    ];

    /**
     * Fetches and synchronizes real-world Matka numbers from DPBoss feed (Section 21).
     * Returns an array of synced markets and their live results.
     */
    public static function syncFromDpboss(): array {
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'header' => "Accept: text/html,application/xhtml+xml\r\n",
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ]);

        $html = @file_get_contents(self::SOURCE_URL, false, $context);
        if (!$html) {
            // Fallback to curl
            $ch = curl_init(self::SOURCE_URL);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
            $html = curl_exec($ch);
            curl_close($ch);
        }

        if (!$html) {
            throw new \RuntimeException('Unable to reach DPBoss live data source.');
        }

        preg_match_all('/<h4>\s*([^<]+?)\s*<\/h4>\s*<span>\s*([^<]+?)\s*<\/span>(?:\s*<p>\s*([^<]+?)\s*<\/p>)?/is', $html, $matches, PREG_SET_ORDER);

        $pdo = Database::getConnection();
        $synced = [];
        $today = date('Y-m-d');

        foreach ($matches as $match) {
            $rawName = trim(strtoupper($match[1]));
            $rawResult = trim($match[2]);
            $rawTiming = isset($match[3]) ? $match[3] : null;

            if (!isset(self::MARKET_MAP[$rawName])) {
                continue;
            }

            $marketId = self::MARKET_MAP[$rawName];

            // Synchronize real-world market open/close timings directly from DPBoss feed
            if ($rawTiming) {
                $parsedTiming = self::parseTimingString($rawTiming);
                if ($parsedTiming) {
                    $mktTimeStmt = $pdo->prepare("
                        UPDATE markets 
                        SET open_time = :open_time, close_time = :close_time 
                        WHERE id = :id AND (open_time != :open_time2 OR close_time != :close_time2)
                    ");
                    $mktTimeStmt->execute([
                        ':open_time' => $parsedTiming['open_time'],
                        ':close_time' => $parsedTiming['close_time'],
                        ':id' => $marketId,
                        ':open_time2' => $parsedTiming['open_time'],
                        ':close_time2' => $parsedTiming['close_time'],
                    ]);
                }
            }

            $parsed = self::parseResultString($rawResult);
            if (!$parsed) {
                continue;
            }

            $openPanna = $parsed['open_panna'];
            $jodi = $parsed['jodi'];
            $closePanna = $parsed['close_panna'];

            // Check if existing result exists for today
            $checkStmt = $pdo->prepare("
                SELECT id, open_panna, jodi, close_panna, status 
                FROM results 
                WHERE market_id = :mid AND result_date = :dt 
                LIMIT 1
            ");
            $checkStmt->execute([':mid' => $marketId, ':dt' => $today]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            // If numbers haven't changed, skip (already published or already in review)
            if ($existing && 
                $existing['open_panna'] === $openPanna && 
                $existing['jodi'] === $jodi && 
                $existing['close_panna'] === $closePanna) {
                continue;
            }

            // Results from DPBoss feed enter 'pending_validation' Admin Review Queue (Section 20 & 21)
            // Allows Admin to review numbers, evaluate bet liabilities, and publish or schedule on demand
            $stmt = $pdo->prepare("
                INSERT INTO results (
                    market_id, result_date, open_panna, jodi, close_panna, 
                    status, scheduled_publish_at, published_at, received_at
                )
                VALUES (
                    :market_id, :result_date, :open_panna, :jodi, :close_panna, 
                    'pending_validation', NULL, NULL, NOW()
                )
                ON DUPLICATE KEY UPDATE 
                    id = LAST_INSERT_ID(id),
                    open_panna = VALUES(open_panna),
                    jodi = VALUES(jodi),
                    close_panna = VALUES(close_panna),
                    status = IF(status = 'queued_delay', 'queued_delay', 'pending_validation'),
                    received_at = NOW(),
                    updated_at = NOW()
            ");

            $stmt->execute([
                ':market_id' => $marketId,
                ':result_date' => $today,
                ':open_panna' => $openPanna,
                ':jodi' => $jodi,
                ':close_panna' => $closePanna,
            ]);

            $resultId = (int)$pdo->lastInsertId();

            $synced[] = [
                'market_id' => $marketId,
                'name' => $rawName,
                'raw_result' => $rawResult,
                'parsed' => "$openPanna-$jodi-$closePanna",
                'status' => 'pending_validation',
            ];
        }

        // Record Audit Log
        $audit = $pdo->prepare("
            INSERT INTO audit_logs (action, entity_type, entity_id, new_values)
            VALUES ('EXTERNAL_SYNC_DPBOSS', 'market_feed', 'dpboss', :val)
        ");
        $audit->execute([
            ':val' => json_encode(['count' => count($synced), 'timestamp' => date('Y-m-d H:i:s')]),
        ]);

        return $synced;
    }

    /**
     * Self-healing throttle: auto-syncs from DPBoss if interval has elapsed (default 60 seconds / every minute).
     */
    public static function autoSyncIfNeeded(int $intervalSeconds = 60): bool {
        $syncFile = __DIR__ . '/../../storage/dpboss_last_sync.txt';
        $dir = dirname($syncFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $lastSync = file_exists($syncFile) ? (int)@file_get_contents($syncFile) : 0;
        $now = time();

        if (($now - $lastSync) >= $intervalSeconds) {
            @file_put_contents($syncFile, (string)$now);
            try {
                self::syncFromDpboss();
                \App\Services\DelayedPublishingService::processPendingResults();
                return true;
            } catch (\Throwable $e) {
                error_log("[DPBOSS AUTO-SYNC ERROR] " . $e->getMessage());
            }
        }
        return false;
    }

    /**
     * Parses standard Matka strings like "477-86-600", "139-3", "660-2", "***-**-***"
     */
    private static function parseResultString(string $str): ?array {
        $str = preg_replace('/[^\d\*\-]/', '', $str);

        // Pattern 1: Full format 3-2-3 (e.g. 477-86-600)
        if (preg_match('/^(\d{3})-(\d{2})-(\d{3})$/', $str, $m)) {
            return [
                'open_panna' => $m[1],
                'jodi' => $m[2],
                'close_panna' => $m[3],
            ];
        }

        // Pattern 2: Half format 3-1 (e.g. 139-3 or 660-2)
        if (preg_match('/^(\d{3})-(\d{1})$/', $str, $m)) {
            return [
                'open_panna' => $m[1],
                'jodi' => $m[2] . '*',
                'close_panna' => '***',
            ];
        }

        // Pattern 3: Full placeholder or unannounced
        if (preg_match('/^\*{3}-\*{2}-\*{3}$/', $str)) {
            return [
                'open_panna' => '***',
                'jodi' => '**',
                'close_panna' => '***',
            ];
        }

        return null;
    }

    /**
     * Parses DPBoss timing string like "04:50 PM &nbsp;&nbsp; 06:50 PM" or "10:00 PM 12:10 AM"
     */
    public static function parseTimingString(?string $raw): ?array {
        if (!$raw) {
            return null;
        }
        $clean = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $clean = preg_replace('/[\s\x{00a0}]+/u', ' ', trim($clean));

        if (preg_match('/(\d{1,2}:\d{2}\s*(?:AM|PM))\s+(\d{1,2}:\d{2}\s*(?:AM|PM))/i', $clean, $m)) {
            $openTimestamp = strtotime($m[1]);
            $closeTimestamp = strtotime($m[2]);
            if ($openTimestamp !== false && $closeTimestamp !== false) {
                return [
                    'open_time' => date('H:i:s', $openTimestamp),
                    'close_time' => date('H:i:s', $closeTimestamp),
                ];
            }
        }
        return null;
    }
}
