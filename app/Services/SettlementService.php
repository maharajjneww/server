<?php

namespace App\Services;

use App\Core\Database;
use PDO;
use Exception;

class SettlementService {
    /**
     * Settle all unsettled demo wagers for a given market when a result is published.
     * Guarantees idempotent settlement: no wager is settled or credited more than once.
     */
    public static function settleMarketWagers(
        string $marketId,
        int $resultId,
        string $openPanna,
        string $jodi,
        string $closePanna
    ): array {
        $pdo = Database::getConnection();

        // 1. Fetch market name
        $mStmt = $pdo->prepare("SELECT name FROM markets WHERE id = :mid LIMIT 1");
        $mStmt->execute([':mid' => $marketId]);
        $market = $mStmt->fetch(PDO::FETCH_ASSOC);
        $marketName = $market ? $market['name'] : $marketId;

        // 2. Fetch all UNSETTLED wagers for this market
        $wStmt = $pdo->prepare("
            SELECT * FROM demo_wagers 
            WHERE market_id = :mid AND settlement_status = 'UNSETTLED'
            ORDER BY id ASC
        ");
        $wStmt->execute([':mid' => $marketId]);
        $wagers = $wStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($wagers)) {
            return [
                'market_id' => $marketId,
                'result_id' => $resultId,
                'settled_count' => 0,
                'won_count' => 0,
                'lost_count' => 0,
                'total_winnings_credited' => 0,
            ];
        }

        $openAnk = strlen($jodi) >= 1 ? substr($jodi, 0, 1) : '';
        $closeAnk = strlen($jodi) >= 2 ? substr($jodi, 1, 1) : '';

        $settledCount = 0;
        $wonCount = 0;
        $lostCount = 0;
        $totalWinnings = 0;

        foreach ($wagers as $wager) {
            $wagerId = (int)$wager['id'];
            $userId = (int)$wager['user_id'];
            $wagerType = $wager['wager_type'];
            $selection = trim($wager['selection']);
            $potentialWin = (int)$wager['potential_win'];

            $pdo->beginTransaction();

            try {
                // Lock wager to verify settlement_status is still UNSETTLED
                $lockWager = $pdo->prepare("SELECT settlement_status FROM demo_wagers WHERE id = :wid FOR UPDATE");
                $lockWager->execute([':wid' => $wagerId]);
                $currentWager = $lockWager->fetch(PDO::FETCH_ASSOC);

                if (!$currentWager || $currentWager['settlement_status'] === 'SETTLED') {
                    $pdo->rollBack();
                    continue; // Skip if already settled by another process
                }

                $isWin = false;

                switch ($wagerType) {
                    case 'jodi':
                        $isWin = ($selection === $jodi);
                        break;

                    case 'single_ank':
                        $isWin = ($selection === $openAnk || $selection === $closeAnk);
                        break;

                    case 'single_panna':
                    case 'double_panna':
                    case 'triple_panna':
                        $isWin = ($selection === $openPanna || $selection === $closePanna);
                        break;
                }

                if ($isWin) {
                    $wonCount++;
                    $totalWinnings += $potentialWin;

                    // Credit user wallet
                    $wStmt = $pdo->prepare("SELECT id, virtual_balance FROM wallets WHERE user_id = :uid FOR UPDATE");
                    $wStmt->execute([':uid' => $userId]);
                    $wallet = $wStmt->fetch(PDO::FETCH_ASSOC);

                    if ($wallet) {
                        $balanceBefore = (int)$wallet['virtual_balance'];
                        $balanceAfter = $balanceBefore + $potentialWin;

                        $updateWallet = $pdo->prepare("UPDATE wallets SET virtual_balance = :after WHERE id = :wid");
                        $updateWallet->execute([
                            ':after' => $balanceAfter,
                            ':wid' => $wallet['id'],
                        ]);

                        // Record immutable winning credit in ledger
                        $txInsert = $pdo->prepare("
                            INSERT INTO wallet_transactions 
                            (user_id, wallet_id, transaction_type, amount, balance_before, balance_after, reference_type, reference_id, status, description)
                            VALUES 
                            (:uid, :wid, 'WINNING_CREDIT', :amt, :before, :after, 'wager', :ref, 'COMPLETED', :desc)
                        ");
                        $txInsert->execute([
                            ':uid' => $userId,
                            ':wid' => $wallet['id'],
                            ':amt' => $potentialWin,
                            ':before' => $balanceBefore,
                            ':after' => $balanceAfter,
                            ':ref' => (string)$wagerId,
                            ':desc' => "Virtual Winning on $marketName ($wagerType: $selection)",
                        ]);
                    }

                    // Update Wager to WON & SETTLED
                    $updateWager = $pdo->prepare("
                        UPDATE demo_wagers 
                        SET status = 'WON', settlement_status = 'SETTLED', winning_amount = :win, result_id = :rid, settled_at = NOW() 
                        WHERE id = :wid
                    ");
                    $updateWager->execute([
                        ':win' => $potentialWin,
                        ':rid' => $resultId,
                        ':wid' => $wagerId,
                    ]);
                } else {
                    $lostCount++;
                    // Update Wager to LOST & SETTLED
                    $updateWager = $pdo->prepare("
                        UPDATE demo_wagers 
                        SET status = 'LOST', settlement_status = 'SETTLED', winning_amount = 0, result_id = :rid, settled_at = NOW() 
                        WHERE id = :wid
                    ");
                    $updateWager->execute([
                        ':rid' => $resultId,
                        ':wid' => $wagerId,
                    ]);
                }

                $pdo->commit();
                $settledCount++;
            } catch (Exception $e) {
                $pdo->rollBack();
                // Continue with next wager, logging error if needed
            }
        }

        return [
            'market_id' => $marketId,
            'result_id' => $resultId,
            'settled_count' => $settledCount,
            'won_count' => $wonCount,
            'lost_count' => $lostCount,
            'total_winnings_credited' => $totalWinnings,
        ];
    }
}
