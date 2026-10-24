<?php

namespace App\Services;

use App\Core\Database;
use PDO;
use Exception;

class WagerService {
    /**
     * Supported wager types and their default multipliers.
     */
    const WAGER_TYPES = [
        'single_ank'   => ['label' => 'Single Ank',   'default_multiplier' => 9.0],
        'jodi'         => ['label' => 'Jodi',         'default_multiplier' => 90.0],
        'single_panna' => ['label' => 'Single Panna', 'default_multiplier' => 140.0],
        'double_panna' => ['label' => 'Double Panna', 'default_multiplier' => 280.0],
        'triple_panna' => ['label' => 'Triple Panna', 'default_multiplier' => 600.0],
    ];

    /**
     * Validate selection string format for given wager type.
     */
    public static function validateSelection(string $wagerType, string $selection): bool {
        $selection = trim($selection);

        switch ($wagerType) {
            case 'single_ank':
                return preg_match('/^[0-9]$/', $selection) === 1;

            case 'jodi':
                return preg_match('/^[0-9]{2}$/', $selection) === 1;

            case 'single_panna':
            case 'double_panna':
            case 'triple_panna':
                return preg_match('/^[0-9]{3}$/', $selection) === 1;

            default:
                return false;
        }
    }

    /**
     * Get winning multiplier from system_settings or default.
     */
    public static function getMultiplier(string $wagerType): float {
        $pdo = Database::getConnection();
        $key = 'multiplier_' . strtolower($wagerType);
        $stmt = $pdo->prepare("SELECT value FROM system_settings WHERE `key` = :k LIMIT 1");
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && is_numeric($row['value'])) {
            return (float)$row['value'];
        }

        return self::WAGER_TYPES[$wagerType]['default_multiplier'] ?? 90.0;
    }

    /**
     * Place a demo wager with idempotency and atomic point deduction.
     */
    public static function placeWager(
        int $userId,
        string $marketId,
        string $wagerType,
        string $selection,
        int $amount,
        ?string $idempotencyKey = null
    ): array {
        if ($amount < 10) {
            throw new Exception('Minimum demo wager amount is 10 virtual points.');
        }

        if ($amount > 100000) {
            throw new Exception('Maximum demo wager amount is 100,000 virtual points.');
        }

        $wagerType = strtolower(trim($wagerType));
        if (!array_key_exists($wagerType, self::WAGER_TYPES)) {
            throw new Exception("Invalid wager type. Supported: " . implode(', ', array_keys(self::WAGER_TYPES)));
        }

        $selection = trim($selection);
        if (!self::validateSelection($wagerType, $selection)) {
            throw new Exception("Invalid selection format for $wagerType.");
        }

        $pdo = Database::getConnection();

        // 1. Idempotency Check: Prevent duplicate debit on double tap or network retry
        if ($idempotencyKey) {
            $existingStmt = $pdo->prepare("
                SELECT w.*, m.name as market_name 
                FROM demo_wagers w
                JOIN markets m ON m.id = w.market_id
                WHERE w.idempotency_key = :key AND w.user_id = :uid
                LIMIT 1
            ");
            $existingStmt->execute([':key' => $idempotencyKey, ':uid' => $userId]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $wallet = WalletService::getOrCreateWallet($userId);
                return [
                    'wager' => $existing,
                    'remaining_balance' => (int)$wallet['virtual_balance'],
                    'is_duplicate' => true,
                ];
            }
        }

        // 2. Validate Market
        $mStmt = $pdo->prepare("SELECT id, name, status FROM markets WHERE id = :mid LIMIT 1");
        $mStmt->execute([':mid' => $marketId]);
        $market = $mStmt->fetch(PDO::FETCH_ASSOC);

        if (!$market) {
            throw new Exception("Market '$marketId' does not exist.");
        }

        if ($market['status'] === 'closed') {
            throw new Exception("Market '{$market['name']}' is currently closed for betting.");
        }

        $multiplier = self::getMultiplier($wagerType);
        $potentialWin = (int)round($amount * $multiplier);

        $pdo->beginTransaction();

        try {
            // Ensure wallet exists and lock row
            WalletService::getOrCreateWallet($userId);

            $wStmt = $pdo->prepare("SELECT id, virtual_balance, reserved_balance, status FROM wallets WHERE user_id = :uid FOR UPDATE");
            $wStmt->execute([':uid' => $userId]);
            $wallet = $wStmt->fetch(PDO::FETCH_ASSOC);

            if (!$wallet || $wallet['status'] !== 'active') {
                throw new Exception('Virtual wallet is locked or inactive.');
            }

            $currentBalance = (int)$wallet['virtual_balance'];
            $reserved = (int)$wallet['reserved_balance'];
            $available = $currentBalance - $reserved;

            if ($amount > $available) {
                throw new Exception("Insufficient virtual points. Available: " . number_format($available) . " points, required: " . number_format($amount) . " points.");
            }

            $balanceAfter = $currentBalance - $amount;

            // Deduct from wallet
            $updateWallet = $pdo->prepare("UPDATE wallets SET virtual_balance = :after WHERE id = :wid");
            $updateWallet->execute([
                ':after' => $balanceAfter,
                ':wid' => $wallet['id'],
            ]);

            // Insert Wager
            $wagerInsert = $pdo->prepare("
                INSERT INTO demo_wagers 
                (user_id, market_id, wager_type, selection, wager_amount, multiplier, potential_win, status, settlement_status, idempotency_key)
                VALUES 
                (:uid, :mid, :type, :sel, :amt, :mult, :pot, 'PENDING', 'UNSETTLED', :idem)
            ");
            $wagerInsert->execute([
                ':uid' => $userId,
                ':mid' => $marketId,
                ':type' => $wagerType,
                ':sel' => $selection,
                ':amt' => $amount,
                ':mult' => $multiplier,
                ':pot' => $potentialWin,
                ':idem' => $idempotencyKey ?: uniqid('wgr_', true),
            ]);
            $wagerId = (int)$pdo->lastInsertId();

            // Insert Ledger Transaction
            $txInsert = $pdo->prepare("
                INSERT INTO wallet_transactions 
                (user_id, wallet_id, transaction_type, amount, balance_before, balance_after, reference_type, reference_id, status, description)
                VALUES 
                (:uid, :wid, 'WAGER_DEBIT', :amt, :before, :after, 'wager', :ref, 'COMPLETED', :desc)
            ");
            $txInsert->execute([
                ':uid' => $userId,
                ':wid' => $wallet['id'],
                ':amt' => -$amount,
                ':before' => $currentBalance,
                ':after' => $balanceAfter,
                ':ref' => (string)$wagerId,
                ':desc' => "Demo Wager on {$market['name']} ($wagerType: $selection)",
            ]);

            $pdo->commit();

            return [
                'wager' => [
                    'id' => $wagerId,
                    'market_id' => $marketId,
                    'market_name' => $market['name'],
                    'wager_type' => $wagerType,
                    'selection' => $selection,
                    'wager_amount' => $amount,
                    'potential_win' => $potentialWin,
                    'status' => 'PENDING',
                    'settlement_status' => 'UNSETTLED',
                    'created_at' => date('Y-m-d H:i:s'),
                ],
                'previous_balance' => $currentBalance,
                'remaining_balance' => $balanceAfter,
                'is_duplicate' => false,
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Get user wager history with optional status filter.
     */
    public static function getUserWagers(int $userId, ?string $status = null, int $limit = 50, int $offset = 0): array {
        $pdo = Database::getConnection();

        $sql = "
            SELECT w.id, w.market_id, COALESCE(m.name, w.market_id) as market_name, w.wager_type, w.selection, 
                   w.wager_amount, w.multiplier, w.potential_win, w.status, w.settlement_status, 
                   w.winning_amount, w.result_id, w.created_at, w.settled_at
            FROM demo_wagers w
            LEFT JOIN markets m ON m.id = w.market_id
            WHERE w.user_id = :uid
        ";

        $params = [':uid' => $userId];

        if ($status && in_array(strtoupper($status), ['PENDING', 'WON', 'LOST', 'CANCELLED'])) {
            $sql .= " AND w.status = :st";
            $params[':st'] = strtoupper($status);
        }

        $sql .= " ORDER BY w.id DESC LIMIT :lim OFFSET :off";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($r) {
            return [
                'id' => (int)$r['id'],
                'market_id' => $r['market_id'],
                'market_name' => $r['market_name'] ?? $r['market_id'],
                'wager_type' => $r['wager_type'],
                'selection' => $r['selection'],
                'wager_amount' => (int)$r['wager_amount'],
                'multiplier' => (float)$r['multiplier'],
                'potential_win' => (int)$r['potential_win'],
                'status' => strtoupper($r['status']),
                'settlement_status' => strtoupper($r['settlement_status'] ?? 'UNSETTLED'),
                'winning_amount' => (int)($r['winning_amount'] ?? 0),
                'result_id' => !empty($r['result_id']) ? (int)$r['result_id'] : null,
                'created_at' => $r['created_at'],
                'settled_at' => $r['settled_at'] ?? null,
            ];
        }, $records);
    }
}
