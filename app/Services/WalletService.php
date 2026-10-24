<?php

namespace App\Services;

use App\Core\Database;
use PDO;
use Exception;

class WalletService {
    /**
     * Get or create a virtual wallet for a user.
     * Guarantees that every user has a wallet with initial demo points credited.
     */
    public static function getOrCreateWallet(int $userId): array {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("SELECT * FROM wallets WHERE user_id = :uid LIMIT 1");
        $stmt->execute([':uid' => $userId]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($wallet) {
            return $wallet;
        }

        // Fetch initial demo points from system settings
        $pointsStmt = $pdo->prepare("SELECT value FROM system_settings WHERE `key` = 'initial_demo_points' LIMIT 1");
        $pointsStmt->execute();
        $settingRow = $pointsStmt->fetch(PDO::FETCH_ASSOC);
        $initialPoints = $settingRow ? (int)$settingRow['value'] : 10000;

        $pdo->beginTransaction();
        try {
            // Re-check inside transaction
            $checkStmt = $pdo->prepare("SELECT * FROM wallets WHERE user_id = :uid FOR UPDATE");
            $checkStmt->execute([':uid' => $userId]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $pdo->commit();
                return $existing;
            }

            $insertStmt = $pdo->prepare("
                INSERT INTO wallets (user_id, virtual_balance, reserved_balance, status)
                VALUES (:uid, :balance, 0, 'active')
            ");
            $insertStmt->execute([
                ':uid' => $userId,
                ':balance' => $initialPoints,
            ]);
            $walletId = (int)$pdo->lastInsertId();

            // Record immutable ledger entry
            $txStmt = $pdo->prepare("
                INSERT INTO wallet_transactions 
                (user_id, wallet_id, transaction_type, amount, balance_before, balance_after, reference_type, reference_id, status, description)
                VALUES 
                (:uid, :wid, 'DEMO_INITIAL_CREDIT', :amount, 0, :after, 'initial', 'welcome_bonus', 'COMPLETED', 'Initial virtual demo points granted on signup')
            ");
            $txStmt->execute([
                ':uid' => $userId,
                ':wid' => $walletId,
                ':amount' => $initialPoints,
                ':after' => $initialPoints,
            ]);

            $pdo->commit();

            return [
                'id' => $walletId,
                'user_id' => $userId,
                'virtual_balance' => $initialPoints,
                'reserved_balance' => 0,
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Add virtual demo points to a user's wallet.
     * Strictly simulated - zero payment gateway or real currency.
     */
    public static function addDemoPoints(int $userId, int $amount, string $description = 'Demo top-up'): array {
        if ($amount <= 0) {
            throw new Exception('Top-up amount must be greater than zero.');
        }

        if ($amount > 500000) {
            throw new Exception('Maximum demo points per single top-up is 500,000.');
        }

        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        try {
            self::getOrCreateWallet($userId);

            $stmt = $pdo->prepare("SELECT * FROM wallets WHERE user_id = :uid FOR UPDATE");
            $stmt->execute([':uid' => $userId]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$wallet || $wallet['status'] !== 'active') {
                throw new Exception('Virtual wallet is currently inactive or locked.');
            }

            $before = (int)$wallet['virtual_balance'];
            $after = $before + $amount;

            $update = $pdo->prepare("UPDATE wallets SET virtual_balance = :after WHERE id = :wid");
            $update->execute([
                ':after' => $after,
                ':wid' => $wallet['id'],
            ]);

            $tx = $pdo->prepare("
                INSERT INTO wallet_transactions 
                (user_id, wallet_id, transaction_type, amount, balance_before, balance_after, reference_type, reference_id, status, description)
                VALUES 
                (:uid, :wid, 'DEMO_CREDIT', :amount, :before, :after, 'topup', UUID(), 'COMPLETED', :desc)
            ");
            $tx->execute([
                ':uid' => $userId,
                ':wid' => $wallet['id'],
                ':amount' => $amount,
                ':before' => $before,
                ':after' => $after,
                ':desc' => $description,
            ]);

            $pdo->commit();

            return [
                'wallet_id' => (int)$wallet['id'],
                'previous_balance' => $before,
                'added_points' => $amount,
                'current_balance' => $after,
                'available_balance' => $after - (int)$wallet['reserved_balance'],
                'currency_type' => 'VIRTUAL_POINTS',
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Request simulated demo withdrawal.
     * Deducts points from virtual balance and queues request for review/record.
     */
    public static function requestDemoWithdrawal(int $userId, int $amount, string $note = 'Demo withdrawal simulation'): array {
        if ($amount <= 0) {
            throw new Exception('Withdrawal amount must be greater than zero.');
        }

        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        try {
            self::getOrCreateWallet($userId);

            $stmt = $pdo->prepare("SELECT * FROM wallets WHERE user_id = :uid FOR UPDATE");
            $stmt->execute([':uid' => $userId]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$wallet || $wallet['status'] !== 'active') {
                throw new Exception('Virtual wallet is currently inactive or locked.');
            }

            $currentBalance = (int)$wallet['virtual_balance'];
            $reserved = (int)$wallet['reserved_balance'];
            $available = $currentBalance - $reserved;

            if ($amount > $available) {
                throw new Exception("Insufficient virtual points. Available: " . number_format($available) . " points.");
            }

            $after = $currentBalance - $amount;

            $update = $pdo->prepare("UPDATE wallets SET virtual_balance = :after WHERE id = :wid");
            $update->execute([
                ':after' => $after,
                ':wid' => $wallet['id'],
            ]);

            $wStmt = $pdo->prepare("
                INSERT INTO demo_withdrawals (user_id, wallet_id, amount, status, admin_note)
                VALUES (:uid, :wid, :amount, 'PENDING', :note)
            ");
            $wStmt->execute([
                ':uid' => $userId,
                ':wid' => $wallet['id'],
                ':amount' => $amount,
                ':note' => $note,
            ]);
            $withdrawalId = (int)$pdo->lastInsertId();

            $tx = $pdo->prepare("
                INSERT INTO wallet_transactions 
                (user_id, wallet_id, transaction_type, amount, balance_before, balance_after, reference_type, reference_id, status, description)
                VALUES 
                (:uid, :wid, 'DEMO_WITHDRAWAL', :amount, :before, :after, 'withdrawal', :ref, 'COMPLETED', :desc)
            ");
            $tx->execute([
                ':uid' => $userId,
                ':wid' => $wallet['id'],
                ':amount' => -$amount,
                ':before' => $currentBalance,
                ':after' => $after,
                ':ref' => (string)$withdrawalId,
                ':desc' => "Demo withdrawal simulation request #$withdrawalId",
            ]);

            $pdo->commit();

            return [
                'withdrawal_id' => $withdrawalId,
                'amount' => $amount,
                'previous_balance' => $currentBalance,
                'current_balance' => $after,
                'status' => 'PENDING',
                'message' => 'Simulated withdrawal request recorded successfully.',
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Get immutable transaction ledger for user.
     */
    public static function getTransactions(int $userId, int $limit = 50, int $offset = 0): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT id, transaction_type, amount, balance_before, balance_after, reference_type, reference_id, status, description, created_at
            FROM wallet_transactions
            WHERE user_id = :uid
            ORDER BY id DESC
            LIMIT :lim OFFSET :off
        ");
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get demo withdrawal history for user.
     */
    public static function getWithdrawals(int $userId): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT id, amount, status, admin_note, created_at, processed_at
            FROM demo_withdrawals
            WHERE user_id = :uid
            ORDER BY id DESC
        ");
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Administrative point adjustment with mandatory reason and ledger audit.
     */
    public static function adminAdjustPoints(int $targetUserId, int $amount, string $operation, string $reason, int $adminId): array {
        if ($amount <= 0) {
            throw new Exception('Adjustment amount must be positive.');
        }

        $operation = strtoupper($operation);
        if (!in_array($operation, ['CREDIT', 'DEBIT'])) {
            throw new Exception('Operation must be CREDIT or DEBIT.');
        }

        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        try {
            self::getOrCreateWallet($targetUserId);

            $stmt = $pdo->prepare("SELECT * FROM wallets WHERE user_id = :uid FOR UPDATE");
            $stmt->execute([':uid' => $targetUserId]);
            $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

            $before = (int)$wallet['virtual_balance'];
            $signedAmount = ($operation === 'CREDIT') ? $amount : -$amount;
            $after = $before + $signedAmount;

            if ($after < 0) {
                throw new Exception('Adjustment would result in negative wallet balance.');
            }

            $update = $pdo->prepare("UPDATE wallets SET virtual_balance = :after WHERE id = :wid");
            $update->execute([':after' => $after, ':wid' => $wallet['id']]);

            $tx = $pdo->prepare("
                INSERT INTO wallet_transactions 
                (user_id, wallet_id, transaction_type, amount, balance_before, balance_after, reference_type, reference_id, status, description)
                VALUES 
                (:uid, :wid, 'ADMIN_ADJUSTMENT', :amount, :before, :after, 'admin_adj', :adminId, 'COMPLETED', :desc)
            ");
            $tx->execute([
                ':uid' => $targetUserId,
                ':wid' => $wallet['id'],
                ':amount' => $signedAmount,
                ':before' => $before,
                ':after' => $after,
                ':adminId' => (string)$adminId,
                ':desc' => "Admin Adjustment ($operation): $reason",
            ]);

            // Audit log
            $audit = $pdo->prepare("
                INSERT INTO audit_logs (user_id, action, entity_type, entity_id, new_values, ip_address)
                VALUES (:adminId, 'admin_wallet_adjustment', 'wallet', :wid, :details, '127.0.0.1')
            ");
            $audit->execute([
                ':adminId' => $adminId,
                ':wid' => (string)$wallet['id'],
                ':details' => json_encode([
                    'target_user' => $targetUserId,
                    'operation' => $operation,
                    'amount' => $amount,
                    'before' => $before,
                    'after' => $after,
                    'reason' => $reason,
                ]),
            ]);

            $pdo->commit();

            return [
                'target_user_id' => $targetUserId,
                'previous_balance' => $before,
                'adjustment' => $signedAmount,
                'current_balance' => $after,
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Admin approve demo withdrawal request.
     */
    public static function adminApproveWithdrawal(int $withdrawalId, int $adminId, string $note = ''): array {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM demo_withdrawals WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $withdrawalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new Exception('Withdrawal request not found.');
        }

        if ($row['status'] !== 'PENDING') {
            throw new Exception('Withdrawal request is already processed.');
        }

        $update = $pdo->prepare("
            UPDATE demo_withdrawals 
            SET status = 'APPROVED', processed_by = :adminId, processed_at = NOW(), admin_note = :note 
            WHERE id = :id
        ");
        $update->execute([
            ':adminId' => $adminId,
            ':note' => $note ?: 'Approved by admin',
            ':id' => $withdrawalId,
        ]);

        return ['withdrawal_id' => $withdrawalId, 'status' => 'APPROVED'];
    }

    /**
     * Admin reject demo withdrawal request and refund virtual points.
     */
    public static function adminRejectWithdrawal(int $withdrawalId, int $adminId, string $note = ''): array {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("SELECT * FROM demo_withdrawals WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $withdrawalId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new Exception('Withdrawal request not found.');
            }

            if ($row['status'] !== 'PENDING') {
                throw new Exception('Withdrawal request is already processed.');
            }

            $wStmt = $pdo->prepare("SELECT * FROM wallets WHERE id = :wid FOR UPDATE");
            $wStmt->execute([':wid' => $row['wallet_id']]);
            $wallet = $wStmt->fetch(PDO::FETCH_ASSOC);

            $refundAmount = (int)$row['amount'];
            $before = (int)$wallet['virtual_balance'];
            $after = $before + $refundAmount;

            $updateWallet = $pdo->prepare("UPDATE wallets SET virtual_balance = :after WHERE id = :wid");
            $updateWallet->execute([':after' => $after, ':wid' => $wallet['id']]);

            $tx = $pdo->prepare("
                INSERT INTO wallet_transactions 
                (user_id, wallet_id, transaction_type, amount, balance_before, balance_after, reference_type, reference_id, status, description)
                VALUES 
                (:uid, :wid, 'WITHDRAWAL_REFUND', :amount, :before, :after, 'withdrawal_refund', :ref, 'COMPLETED', :desc)
            ");
            $tx->execute([
                ':uid' => $row['user_id'],
                ':wid' => $wallet['id'],
                ':amount' => $refundAmount,
                ':before' => $before,
                ':after' => $after,
                ':ref' => (string)$withdrawalId,
                ':desc' => "Refund for rejected demo withdrawal #$withdrawalId: $note",
            ]);

            $update = $pdo->prepare("
                UPDATE demo_withdrawals 
                SET status = 'REJECTED', processed_by = :adminId, processed_at = NOW(), admin_note = :note 
                WHERE id = :id
            ");
            $update->execute([
                ':adminId' => $adminId,
                ':note' => $note ?: 'Rejected by admin',
                ':id' => $withdrawalId,
            ]);

            $pdo->commit();

            return ['withdrawal_id' => $withdrawalId, 'status' => 'REJECTED', 'refunded_points' => $refundAmount];
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
