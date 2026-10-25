<?php

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Services\WalletService;
use PDO;
use Exception;

class AdminWalletController {
    /**
     * GET /api/admin/wallets
     * List user virtual wallets.
     */
    public function wallets(Request $request): void {
        $pdo = Database::getConnection();
        $search = trim((string)$request->input('search', ''));

        $sql = "
            SELECT w.id, w.user_id, u.name, u.email, u.phone, w.virtual_balance, w.reserved_balance, w.status, w.updated_at
            FROM wallets w
            JOIN users u ON u.id = w.user_id
        ";

        if ($search !== '') {
            $sql .= " WHERE u.name LIKE :s OR u.email LIKE :s OR u.phone LIKE :s";
        }

        $sql .= " ORDER BY w.virtual_balance DESC LIMIT 100";

        $stmt = $pdo->prepare($sql);
        if ($search !== '') {
            $stmt->execute([':s' => "%$search%"]);
        } else {
            $stmt->execute();
        }

        $wallets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        Response::success($wallets, 'Wallets retrieved successfully.');
    }

    /**
     * GET /api/admin/wallets/{userId}
     * Detail of a user's wallet and recent ledger entries.
     */
    public function userWallet(Request $request, string $userId): void {
        $uid = (int)$userId;
        try {
            $wallet = WalletService::getOrCreateWallet($uid);
            $transactions = WalletService::getTransactions($uid, 50, 0);

            Response::success([
                'wallet' => $wallet,
                'transactions' => $transactions,
            ], 'User wallet detail retrieved.');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * POST /api/admin/wallet-adjustment
     * Admin manual point adjustment with mandatory reason and ledger entry.
     */
    public function adjustPoints(Request $request): void {
        $admin = $request->getUser();
        $userId = (int)$request->input('user_id', 0);
        $rawAmount = (int)$request->input('amount', 0);
        if ($rawAmount === 0) {
            $rawAmount = (int)$request->input('points', 0);
        }
        $reqOp = strtoupper(trim((string)$request->input('operation', '')));
        $reason = trim((string)$request->input('reason', ''));

        // If amount is negative, automatically treat as DEBIT
        if ($rawAmount < 0) {
            $amount = abs($rawAmount);
            $operation = 'DEBIT';
        } else {
            $amount = $rawAmount;
            $operation = ($reqOp === 'DEBIT') ? 'DEBIT' : 'CREDIT';
        }

        if ($userId <= 0 || $amount <= 0 || empty($reason)) {
            Response::error('user_id, a non-zero amount, and a reason are required.', 422);
        }

        try {
            $res = WalletService::adminAdjustPoints($userId, $amount, $operation, $reason, (int)($admin ? $admin['sub'] : 1));
            $actionWord = ($operation === 'DEBIT') ? 'deducted from' : 'credited to';
            Response::success($res, "Successfully $actionWord user wallet: " . ($operation === 'DEBIT' ? "-$amount" : "+$amount") . " pts.");
        } catch (Exception $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    /**
     * GET /api/admin/demo-withdrawals
     * List all demo withdrawal requests.
     */
    public function withdrawals(Request $request): void {
        $pdo = Database::getConnection();
        $status = $request->input('status');

        $sql = "
            SELECT w.id, w.user_id, u.name, u.email, w.amount, w.status, w.admin_note, w.created_at, w.processed_at
            FROM demo_withdrawals w
            JOIN users u ON u.id = w.user_id
        ";

        if ($status) {
            $sql .= " WHERE w.status = :st";
        }

        $sql .= " ORDER BY w.id DESC LIMIT 100";

        $stmt = $pdo->prepare($sql);
        if ($status) {
            $stmt->execute([':st' => (string)$status]);
        } else {
            $stmt->execute();
        }

        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        Response::success($items, 'Demo withdrawal requests retrieved.');
    }

    /**
     * POST /api/admin/demo-withdrawals/{id}/approve
     */
    public function approveWithdrawal(Request $request, string $id): void {
        $admin = $request->getUser();
        $note = (string)$request->input('note', 'Approved by admin');

        try {
            $res = WalletService::adminApproveWithdrawal((int)$id, (int)$admin['sub'], $note);
            Response::success($res, 'Withdrawal request approved.');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    /**
     * POST /api/admin/demo-withdrawals/{id}/reject
     */
    public function rejectWithdrawal(Request $request, string $id): void {
        $admin = $request->getUser();
        $note = (string)$request->input('note', 'Rejected by admin');

        try {
            $res = WalletService::adminRejectWithdrawal((int)$id, (int)$admin['sub'], $note);
            Response::success($res, 'Withdrawal request rejected and points refunded.');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    /**
     * GET /api/admin/wagers
     * List all wagers across markets for admin inspection.
     */
    public function wagers(Request $request): void {
        $pdo = Database::getConnection();
        $status = $request->input('status');
        $marketId = $request->input('market_id');
        $search = trim((string)$request->input('search', ''));

        // Stats summary with total bets, staked, won payout, lost amount, and winning/losing user counts
        $statsStmt = $pdo->query("
            SELECT 
                COUNT(*) as total_bets,
                COALESCE(SUM(wager_amount), 0) as total_staked,
                COALESCE(SUM(winning_amount), 0) as total_won_payout,
                COALESCE(SUM(CASE WHEN status = 'LOST' THEN wager_amount ELSE 0 END), 0) as total_lost_amount,
                SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'WON' THEN 1 ELSE 0 END) as won_count,
                SUM(CASE WHEN status = 'LOST' THEN 1 ELSE 0 END) as lost_count,
                COUNT(DISTINCT CASE WHEN status = 'WON' THEN user_id END) as win_users_count,
                COUNT(DISTINCT CASE WHEN status = 'LOST' THEN user_id END) as loss_users_count,
                COUNT(DISTINCT CASE WHEN status = 'PENDING' THEN user_id END) as pending_users_count
            FROM demo_wagers
        ");
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $totalStakedNum = (int)($stats['total_staked'] ?? 0);
        $totalWonNum = (int)($stats['total_won_payout'] ?? 0);
        $stats['house_net_profit'] = $totalStakedNum - $totalWonNum;

        $sql = "
            SELECT w.id, w.user_id, u.name as user_name, u.email as user_email, 
                   w.market_id, COALESCE(m.name, w.market_id) as market_name, 
                   w.wager_type, w.wager_type as game_type, 
                   w.selection, w.selection as bet_number, 
                   w.wager_amount, w.wager_amount as points_wagered, 
                   w.multiplier, w.potential_win, w.potential_win as potential_win_points, 
                   w.status, w.settlement_status, w.winning_amount, 
                   w.created_at, w.created_at as placed_at, w.settled_at,
                   wal.virtual_balance as user_wallet_balance,
                   r.open_panna as result_open_panna, r.jodi as result_jodi, r.close_panna as result_close_panna,
                   r.status as result_status
            FROM demo_wagers w
            LEFT JOIN users u ON u.id = w.user_id
            LEFT JOIN wallets wal ON wal.user_id = w.user_id
            LEFT JOIN markets m ON m.id = w.market_id
            LEFT JOIN results r ON r.market_id = w.market_id AND r.result_date = DATE(w.created_at)
            WHERE 1=1
        ";

        $params = [];
        if ($status && $status !== 'ALL') {
            $sql .= " AND w.status = :st";
            $params[':st'] = (string)$status;
        }
        if ($marketId && $marketId !== 'ALL') {
            $sql .= " AND (w.market_id = :mid OR m.name = :mname)";
            $params[':mid'] = (string)$marketId;
            $params[':mname'] = (string)$marketId;
        }
        if (!empty($search)) {
            $sql .= " AND (u.name LIKE :srch OR u.email LIKE :srch OR w.selection LIKE :srch OR m.name LIKE :srch)";
            $params[':srch'] = "%$search%";
        }

        $sql .= " ORDER BY w.id DESC LIMIT 150";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $wagers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        Response::success([
            'stats' => $stats,
            'wagers' => $wagers,
        ], 'Admin wagers retrieved.');
    }

    /**
     * GET /api/admin/wagers/{id}
     * Full detailed view of a wager with user details, market result match, and audit ledger.
     */
    public function showWager(Request $request, string $id): void {
        $wagerId = (int)$id;
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            SELECT w.id, w.user_id, u.name as user_name, u.email as user_email, u.phone as user_phone,
                   wal.virtual_balance as user_wallet_balance,
                   w.market_id, COALESCE(m.name, w.market_id) as market_name, 
                   TIME_FORMAT(m.open_time, '%h:%i %p') as open_time, 
                   TIME_FORMAT(m.close_time, '%h:%i %p') as close_time, 
                   m.status as market_status,
                   w.wager_type, w.wager_type as game_type, 
                   w.selection, w.selection as bet_number, 
                   w.wager_amount, w.wager_amount as points_wagered, 
                   w.multiplier, w.potential_win, w.potential_win as potential_win_points, 
                   w.status, w.settlement_status, w.winning_amount, 
                   w.created_at, w.created_at as placed_at, w.settled_at,
                   r.open_panna as result_open_panna, r.jodi as result_jodi, r.close_panna as result_close_panna,
                   r.status as result_status, r.published_at as result_published_at
            FROM demo_wagers w
            LEFT JOIN users u ON u.id = w.user_id
            LEFT JOIN wallets wal ON wal.user_id = w.user_id
            LEFT JOIN markets m ON m.id = w.market_id
            LEFT JOIN results r ON r.market_id = w.market_id AND r.result_date = DATE(w.created_at)
            WHERE w.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $wagerId]);
        $wager = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$wager) {
            Response::error("Wager #$wagerId not found.", 404);
        }

        // Add game explanation / result match analysis
        $isMatch = false;
        $analysisExplanation = 'Market result has not been declared yet.';
        if (!empty($wager['result_jodi']) && $wager['result_jodi'] !== '**') {
            $sel = trim((string)$wager['selection']);
            $wt = $wager['wager_type'];
            if ($wt === 'jodi') {
                $isMatch = ($sel === $wager['result_jodi']);
                $analysisExplanation = $isMatch 
                    ? "User Jodi '$sel' EXACTLY MATCHED declared Jodi '{$wager['result_jodi']}'."
                    : "User Jodi '$sel' did NOT match declared Jodi '{$wager['result_jodi']}'.";
            } elseif ($wt === 'single_ank') {
                $openAnk = substr($wager['result_jodi'], 0, 1);
                $closeAnk = substr($wager['result_jodi'], 1, 1);
                $isMatch = ($sel === $openAnk || $sel === $closeAnk);
                $analysisExplanation = $isMatch 
                    ? "User Single Ank '$sel' matched declared digit in '{$wager['result_jodi']}'."
                    : "User Single Ank '$sel' did NOT match open ($openAnk) or close ($closeAnk) digits.";
            } elseif (in_array($wt, ['single_panna', 'double_panna', 'triple_panna'])) {
                $isMatch = ($sel === $wager['result_open_panna'] || $sel === $wager['result_close_panna']);
                $analysisExplanation = $isMatch 
                    ? "User Panna '$sel' matched declared Panna ({$wager['result_open_panna']} / {$wager['result_close_panna']})."
                    : "User Panna '$sel' did NOT match open panna ({$wager['result_open_panna']}) or close panna ({$wager['result_close_panna']}).";
            }
        }

        $wager['is_result_match'] = $isMatch;
        $wager['result_explanation'] = $analysisExplanation;

        Response::success($wager, "Wager details retrieved.");
    }

    /**
     * GET /api/admin/wagers/analysis
     * Bet-wise and market exposure analysis for risk management and result impact assessment.
     */
    public function betAnalysis(Request $request): void {
        $pdo = Database::getConnection();
        $marketId = $request->input('market_id');
        $wagerType = $request->input('wager_type');
        $date = $request->input('date');

        // All markets for filter
        $marketsStmt = $pdo->query("SELECT id, name, open_time, close_time, status FROM markets ORDER BY name ASC");
        $allMarkets = $marketsStmt->fetchAll(PDO::FETCH_ASSOC);

        $selectedMarket = null;
        if ($marketId && $marketId !== 'ALL') {
            foreach ($allMarkets as $m) {
                if ($m['id'] === $marketId) {
                    $selectedMarket = $m;
                    break;
                }
            }
        }

        // Fetch declared / pending result for this market & date if applicable
        $result = null;
        if ($selectedMarket) {
            $dtCondition = $date ? "r.result_date = :dt" : "r.result_date = CURRENT_DATE()";
            $resStmt = $pdo->prepare("
                SELECT r.*, COALESCE(s.name, 'DPBoss Feed') as source_name
                FROM results r
                LEFT JOIN result_sources s ON r.source_id = s.id
                WHERE r.market_id = :mid AND $dtCondition
                ORDER BY r.id DESC LIMIT 1
            ");
            $params = [':mid' => $selectedMarket['id']];
            if ($date) $params[':dt'] = $date;
            $resStmt->execute($params);
            $result = $resStmt->fetch(PDO::FETCH_ASSOC);
        }

        // 1. Overall summary for the filtered criteria
        $statsSql = "
            SELECT 
                COUNT(*) as total_bets,
                COALESCE(SUM(wager_amount), 0) as total_staked,
                COALESCE(SUM(winning_amount), 0) as total_payout,
                COALESCE(SUM(potential_win), 0) as total_potential_exposure,
                SUM(CASE WHEN status = 'WON' THEN 1 ELSE 0 END) as won_count,
                SUM(CASE WHEN status = 'LOST' THEN 1 ELSE 0 END) as lost_count,
                SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'CANCELLED' THEN 1 ELSE 0 END) as cancelled_count
            FROM demo_wagers
            WHERE 1=1
        ";
        $params = [];
        if ($selectedMarket) {
            $statsSql .= " AND market_id = :mid";
            $params[':mid'] = $selectedMarket['id'];
        }
        if ($wagerType && $wagerType !== 'ALL') {
            $statsSql .= " AND wager_type = :wt";
            $params[':wt'] = $wagerType;
        }
        if ($date) {
            $statsSql .= " AND DATE(created_at) = :dt";
            $params[':dt'] = $date;
        }

        $statsStmt = $pdo->prepare($statsSql);
        $statsStmt->execute($params);
        $summary = $statsStmt->fetch(PDO::FETCH_ASSOC);

        $totalStaked = (int)($summary['total_staked'] ?? 0);
        $totalPayout = (int)($summary['total_payout'] ?? 0);
        $netProfit = $totalStaked - $totalPayout;
        $marginPct = ($totalStaked > 0) ? round(($netProfit / $totalStaked) * 100, 2) : 0.0;

        $summary['net_profit'] = $netProfit;
        $summary['margin_pct'] = $marginPct;

        // 2. Bet-wise aggregation (grouped by wager_type and selection)
        $betwiseSql = "
            SELECT 
                w.market_id,
                COALESCE(m.name, w.market_id) as market_name,
                w.wager_type,
                w.selection as bet_number,
                COUNT(*) as bet_count,
                COUNT(DISTINCT w.user_id) as unique_users,
                COALESCE(SUM(w.wager_amount), 0) as total_staked,
                COALESCE(SUM(w.potential_win), 0) as potential_payout,
                COALESCE(SUM(w.winning_amount), 0) as settled_payout,
                MAX(w.multiplier) as multiplier,
                SUM(CASE WHEN w.status = 'WON' THEN 1 ELSE 0 END) as won_count,
                SUM(CASE WHEN w.status = 'LOST' THEN 1 ELSE 0 END) as lost_count,
                SUM(CASE WHEN w.status = 'PENDING' THEN 1 ELSE 0 END) as pending_count
            FROM demo_wagers w
            LEFT JOIN markets m ON m.id = w.market_id
            WHERE 1=1
        ";
        $betwiseParams = [];
        if ($selectedMarket) {
            $betwiseSql .= " AND w.market_id = :mid";
            $betwiseParams[':mid'] = $selectedMarket['id'];
        }
        if ($wagerType && $wagerType !== 'ALL') {
            $betwiseSql .= " AND w.wager_type = :wt";
            $betwiseParams[':wt'] = $wagerType;
        }
        if ($date) {
            $betwiseSql .= " AND DATE(w.created_at) = :dt";
            $betwiseParams[':dt'] = $date;
        }
        $betwiseSql .= " GROUP BY w.market_id, w.wager_type, w.selection ORDER BY potential_payout DESC, total_staked DESC LIMIT 150";

        $betwiseStmt = $pdo->prepare($betwiseSql);
        $betwiseStmt->execute($betwiseParams);
        $betwiseList = $betwiseStmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate risk exposure level & result match indicator
        foreach ($betwiseList as &$item) {
            $payout = (int)$item['potential_payout'];
            $staked = (int)$item['total_staked'];
            if ($payout >= 50000 || $staked >= 2000) {
                $item['risk_level'] = 'HIGH';
            } elseif ($payout >= 15000 || $staked >= 500) {
                $item['risk_level'] = 'MEDIUM';
            } else {
                $item['risk_level'] = 'LOW';
            }

            // Check if matches declared/staged result
            $item['is_result_match'] = false;
            if ($result) {
                $sel = trim((string)$item['bet_number']);
                $wt = $item['wager_type'];
                if ($wt === 'jodi' && $sel === $result['jodi']) {
                    $item['is_result_match'] = true;
                } elseif ($wt === 'single_ank') {
                    $openAnk = strlen($result['jodi']) >= 1 ? substr($result['jodi'], 0, 1) : '';
                    $closeAnk = strlen($result['jodi']) >= 2 ? substr($result['jodi'], 1, 1) : '';
                    if ($sel === $openAnk || $sel === $closeAnk) {
                        $item['is_result_match'] = true;
                    }
                } elseif (in_array($wt, ['single_panna', 'double_panna', 'triple_panna'])) {
                    if ($sel === $result['open_panna'] || $sel === $result['close_panna']) {
                        $item['is_result_match'] = true;
                    }
                }
            }
        }

        Response::success([
            'markets' => $allMarkets,
            'selected_market' => $selectedMarket,
            'result' => $result,
            'summary' => $summary,
            'betwise' => $betwiseList,
        ], 'Bet analysis retrieved.');
    }

    /**
     * GET /api/admin/wagers/market-summary
     * Market-wise bet aggregation and results breakdown:
     * Returns each market with total users betted, total bets, total staked amount,
     * won payout amount, loss amount kept by house, pending stakes, declared results,
     * game-type breakdown, and number-wise pick summaries.
     */
    public function marketBetSummary(Request $request): void {
        $pdo = Database::getConnection();
        $date = $request->input('date');
        $marketFilter = $request->input('market_id');

        $dtCondition = $date ? "DATE(w.created_at) = :dt" : "1=1";
        $resDtCondition = $date ? "r.result_date = :dt" : "r.result_date = CURRENT_DATE()";

        $sql = "
            SELECT 
                m.id as market_id,
                m.name as market_name,
                TIME_FORMAT(m.open_time, '%h:%i %p') as open_time,
                TIME_FORMAT(m.close_time, '%h:%i %p') as close_time,
                m.status as market_status,
                COUNT(w.id) as total_bets,
                COUNT(DISTINCT w.user_id) as total_users,
                COALESCE(SUM(w.wager_amount), 0) as total_staked,
                COALESCE(SUM(w.winning_amount), 0) as total_won_payout,
                COALESCE(SUM(CASE WHEN w.status = 'LOST' THEN w.wager_amount ELSE 0 END), 0) as total_lost_amount,
                COALESCE(SUM(CASE WHEN w.status = 'PENDING' THEN w.wager_amount ELSE 0 END), 0) as pending_staked,
                COALESCE(SUM(CASE WHEN w.status = 'PENDING' THEN w.potential_win ELSE 0 END), 0) as pending_exposure,
                COUNT(DISTINCT CASE WHEN w.status = 'PENDING' THEN w.user_id END) as pending_users,
                COUNT(DISTINCT CASE WHEN w.status = 'WON' THEN w.user_id END) as win_users,
                COUNT(DISTINCT CASE WHEN w.status = 'LOST' THEN w.user_id END) as loss_users,
                SUM(CASE WHEN w.status = 'PENDING' THEN 1 ELSE 0 END) as pending_bets_count,
                SUM(CASE WHEN w.status = 'WON' THEN 1 ELSE 0 END) as won_bets_count,
                SUM(CASE WHEN w.status = 'LOST' THEN 1 ELSE 0 END) as lost_bets_count,
                r.open_panna as result_open_panna,
                r.jodi as result_jodi,
                r.close_panna as result_close_panna,
                r.status as result_status,
                r.published_at as result_published_at
            FROM markets m
            LEFT JOIN demo_wagers w ON w.market_id = m.id AND $dtCondition
            LEFT JOIN results r ON r.market_id = m.id AND $resDtCondition
            WHERE 1=1
        ";

        $params = [];
        if ($date) {
            $params[':dt'] = $date;
        }
        if ($marketFilter && $marketFilter !== 'ALL') {
            $sql .= " AND m.id = :mid";
            $params[':mid'] = $marketFilter;
        }

        $sql .= " GROUP BY m.id, m.name, m.open_time, m.close_time, m.status, r.open_panna, r.jodi, r.close_panna, r.status, r.published_at
                  ORDER BY total_bets DESC, total_staked DESC, m.name ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $markets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($markets as &$mkt) {
            $mid = $mkt['market_id'];
            $staked = (int)$mkt['total_staked'];
            $won = (int)$mkt['total_won_payout'];
            $mkt['house_net_balance'] = $staked - $won;

            if ((int)$mkt['total_bets'] > 0) {
                // Game type breakdown
                $gtStmt = $pdo->prepare("
                    SELECT 
                        wager_type,
                        COUNT(*) as bets_count,
                        COUNT(DISTINCT user_id) as users_count,
                        COALESCE(SUM(wager_amount), 0) as staked_amount,
                        COALESCE(SUM(winning_amount), 0) as payout_amount,
                        SUM(CASE WHEN status = 'WON' THEN 1 ELSE 0 END) as won_count,
                        SUM(CASE WHEN status = 'LOST' THEN 1 ELSE 0 END) as lost_count,
                        SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending_count
                    FROM demo_wagers
                    WHERE market_id = :mid " . ($date ? "AND DATE(created_at) = :dt" : "") . "
                    GROUP BY wager_type
                    ORDER BY staked_amount DESC
                ");
                $gtParams = [':mid' => $mid];
                if ($date) $gtParams[':dt'] = $date;
                $gtStmt->execute($gtParams);
                $mkt['game_types'] = $gtStmt->fetchAll(PDO::FETCH_ASSOC);

                // Top picks / numbers in this market
                $pickStmt = $pdo->prepare("
                    SELECT 
                        selection,
                        wager_type,
                        COUNT(*) as bets_count,
                        COUNT(DISTINCT user_id) as users_count,
                        COALESCE(SUM(wager_amount), 0) as total_staked,
                        COALESCE(SUM(potential_win), 0) as potential_payout,
                        COALESCE(SUM(winning_amount), 0) as won_payout,
                        SUM(CASE WHEN status = 'WON' THEN 1 ELSE 0 END) as won_count,
                        SUM(CASE WHEN status = 'LOST' THEN 1 ELSE 0 END) as lost_count,
                        SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending_count
                    FROM demo_wagers
                    WHERE market_id = :mid " . ($date ? "AND DATE(created_at) = :dt" : "") . "
                    GROUP BY selection, wager_type
                    ORDER BY total_staked DESC, bets_count DESC
                    LIMIT 20
                ");
                $pickParams = [':mid' => $mid];
                if ($date) $pickParams[':dt'] = $date;
                $pickStmt->execute($pickParams);
                $picks = $pickStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($picks as &$p) {
                    $p['is_winner'] = false;
                    if (!empty($mkt['result_jodi']) || !empty($mkt['result_open_panna'])) {
                        $sel = trim((string)$p['selection']);
                        $wt = $p['wager_type'];
                        if ($wt === 'jodi' && $sel === $mkt['result_jodi']) {
                            $p['is_winner'] = true;
                        } elseif (in_array($wt, ['single_panna', 'double_panna', 'triple_panna'])) {
                            if ($sel === $mkt['result_open_panna'] || $sel === $mkt['result_close_panna']) {
                                $p['is_winner'] = true;
                            }
                        } elseif ($wt === 'single_ank') {
                            $openAnk = strlen($mkt['result_jodi'] ?? '') >= 1 ? substr($mkt['result_jodi'], 0, 1) : '';
                            $closeAnk = strlen($mkt['result_jodi'] ?? '') >= 2 ? substr($mkt['result_jodi'], 1, 1) : '';
                            if ($sel === $openAnk || $sel === $closeAnk) {
                                $p['is_winner'] = true;
                            }
                        }
                    }
                }
                $mkt['top_picks'] = $picks;
            } else {
                $mkt['game_types'] = [];
                $mkt['top_picks'] = [];
            }
        }

        $totalAllStaked = (int)array_sum(array_column($markets, 'total_staked'));
        $totalAllWon = (int)array_sum(array_column($markets, 'total_won_payout'));
        $totalAllLost = (int)array_sum(array_column($markets, 'total_lost_amount'));
        $totalAllBets = (int)array_sum(array_column($markets, 'total_bets'));
        $marketsWithBets = count(array_filter($markets, fn($m) => (int)$m['total_bets'] > 0));
        
        $allUniqueUsers = 0;
        if ($totalAllBets > 0) {
            $userCountSql = "SELECT COUNT(DISTINCT user_id) FROM demo_wagers" . ($date ? " WHERE DATE(created_at) = :dt" : "");
            $uStmt = $pdo->prepare($userCountSql);
            if ($date) $uStmt->execute([':dt' => $date]);
            else $uStmt->execute();
            $allUniqueUsers = (int)($uStmt->fetchColumn() ?: 0);
        }

        Response::success([
            'overview' => [
                'total_markets' => count($markets),
                'markets_with_bets' => $marketsWithBets,
                'total_users' => $allUniqueUsers,
                'total_bets' => $totalAllBets,
                'total_staked' => $totalAllStaked,
                'total_won_payout' => $totalAllWon,
                'total_lost_amount' => $totalAllLost,
                'house_net_balance' => $totalAllStaked - $totalAllWon,
            ],
            'markets' => $markets,
        ], 'Market bet summaries retrieved.');
    }

    /**
     * POST /api/admin/wagers/{id}
     * Admin modification of user wager with full ledger reconciliation and audit logging.
     */
    public function updateWager(Request $request, string $id): void {
        $wagerId = (int)$id;
        $pdo = Database::getConnection();

        $admin = $request->getUser();
        $adminId = $admin ? (int)$admin['sub'] : null;

        $newSelection = $request->input('selection');
        $newAmountInput = $request->input('wager_amount');
        $newType = $request->input('wager_type');
        $newStatus = $request->input('status');
        $adminNote = trim((string)$request->input('admin_note', 'Admin modified bet'));

        $pdo->beginTransaction();

        try {
            // Lock wager
            $stmt = $pdo->prepare("SELECT * FROM demo_wagers WHERE id = :wid FOR UPDATE");
            $stmt->execute([':wid' => $wagerId]);
            $wager = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$wager) {
                $pdo->rollBack();
                Response::error("Wager #$wagerId not found.", 404);
            }

            $userId = (int)$wager['user_id'];
            $oldStatus = (string)$wager['status'];
            $oldAmount = (int)$wager['wager_amount'];
            $oldWinning = (int)$wager['winning_amount'];
            $oldPotential = (int)$wager['potential_win'];
            $oldSelection = (string)$wager['selection'];
            $oldType = (string)$wager['wager_type'];

            // Lock user's wallet
            $wStmt = $pdo->prepare("SELECT * FROM wallets WHERE user_id = :uid FOR UPDATE");
            $wStmt->execute([':uid' => $userId]);
            $wallet = $wStmt->fetch(PDO::FETCH_ASSOC);
            if (!$wallet) {
                $pdo->rollBack();
                Response::error("User wallet not found for wager.", 404);
            }

            $type = $newType ?: $oldType;
            $selection = ($newSelection !== null && $newSelection !== '') ? trim((string)$newSelection) : $oldSelection;
            $amount = ($newAmountInput !== null && $newAmountInput !== '') ? (int)$newAmountInput : $oldAmount;
            $status = $newStatus ?: $oldStatus;

            if ($amount <= 0) {
                $pdo->rollBack();
                Response::error("Wager amount must be positive.", 422);
            }

            // Validate selection format
            if (!\App\Services\WagerService::validateSelection($type, $selection)) {
                $pdo->rollBack();
                Response::error("Invalid selection '$selection' for game type '$type'.", 422);
            }

            $multiplier = \App\Services\WagerService::getMultiplier($type);
            $potentialWin = (int)round($amount * $multiplier);

            $currentBalance = (int)$wallet['virtual_balance'];
            $newWinningAmount = $oldWinning;
            $newSettlementStatus = $wager['settlement_status'];
            $settledAt = $wager['settled_at'];

            // --- Wallet Balance Adjustments based on Status Transitions ---
            if ($status === 'CANCELLED') {
                if ($oldStatus !== 'CANCELLED') {
                    // Refund the stake
                    $refundAmt = $oldAmount;
                    if ($oldStatus === 'WON' && $oldWinning > 0) {
                        $refundAmt = $oldAmount - $oldWinning;
                    }

                    $newBal = $currentBalance + $refundAmt;
                    if ($newBal < 0) {
                        $pdo->rollBack();
                        Response::error("Cannot cancel: user has already spent winning points.", 422);
                    }

                    $pdo->prepare("UPDATE wallets SET virtual_balance = :bal WHERE id = :wid")
                        ->execute([':bal' => $newBal, ':wid' => $wallet['id']]);

                    $pdo->prepare("
                        INSERT INTO wallet_transactions 
                        (user_id, wallet_id, transaction_type, amount, balance_before, balance_after, reference_type, reference_id, status, description)
                        VALUES (:uid, :wid, 'WAGER_REFUND', :amt, :before, :after, 'wager', :ref, 'COMPLETED', :desc)
                    ")->execute([
                        ':uid' => $userId,
                        ':wid' => $wallet['id'],
                        ':amt' => $refundAmt,
                        ':before' => $currentBalance,
                        ':after' => $newBal,
                        ':ref' => (string)$wagerId,
                        ':desc' => "Admin cancelled wager #$wagerId: $adminNote",
                    ]);
                    $currentBalance = $newBal;
                }
                $newWinningAmount = 0;
                $newSettlementStatus = 'SETTLED';
                $settledAt = date('Y-m-d H:i:s');
            } elseif ($status === 'WON') {
                $winningToCredit = $potentialWin;
                if ($oldStatus === 'WON') {
                    $diff = $winningToCredit - $oldWinning;
                    if ($diff != 0) {
                        $newBal = $currentBalance + $diff;
                        if ($newBal < 0) {
                            $pdo->rollBack();
                            Response::error("Adjustment would cause negative balance.", 422);
                        }
                        $pdo->prepare("UPDATE wallets SET virtual_balance = :bal WHERE id = :wid")
                            ->execute([':bal' => $newBal, ':wid' => $wallet['id']]);

                        $pdo->prepare("
                            INSERT INTO wallet_transactions 
                            (user_id, wallet_id, transaction_type, amount, balance_before, balance_after, reference_type, reference_id, status, description)
                            VALUES (:uid, :wid, 'WINNING_CREDIT', :amt, :before, :after, 'wager', :ref, 'COMPLETED', :desc)
                        ")->execute([
                            ':uid' => $userId,
                            ':wid' => $wallet['id'],
                            ':amt' => $diff,
                            ':before' => $currentBalance,
                            ':after' => $newBal,
                            ':ref' => (string)$wagerId,
                            ':desc' => "Admin adjusted winning payout for wager #$wagerId: $adminNote",
                        ]);
                        $currentBalance = $newBal;
                    }
                } else {
                    // Credit full winning payout
                    $newBal = $currentBalance + $winningToCredit;
                    $pdo->prepare("UPDATE wallets SET virtual_balance = :bal WHERE id = :wid")
                        ->execute([':bal' => $newBal, ':wid' => $wallet['id']]);

                    $pdo->prepare("
                        INSERT INTO wallet_transactions 
                        (user_id, wallet_id, transaction_type, amount, balance_before, balance_after, reference_type, reference_id, status, description)
                        VALUES (:uid, :wid, 'WINNING_CREDIT', :amt, :before, :after, 'wager', :ref, 'COMPLETED', :desc)
                    ")->execute([
                        ':uid' => $userId,
                        ':wid' => $wallet['id'],
                        ':amt' => $winningToCredit,
                        ':before' => $currentBalance,
                        ':after' => $newBal,
                        ':ref' => (string)$wagerId,
                        ':desc' => "Admin marked wager #$wagerId as WON: $adminNote",
                    ]);
                    $currentBalance = $newBal;
                }
                $newWinningAmount = $winningToCredit;
                $newSettlementStatus = 'SETTLED';
                $settledAt = date('Y-m-d H:i:s');
            } elseif ($status === 'LOST') {
                if ($oldStatus === 'WON' && $oldWinning > 0) {
                    // Reverse the winning credit
                    $newBal = $currentBalance - $oldWinning;
                    if ($newBal < 0) {
                        $pdo->rollBack();
                        Response::error("Cannot mark as LOST: user has already withdrawn/spent winning points.", 422);
                    }
                    $pdo->prepare("UPDATE wallets SET virtual_balance = :bal WHERE id = :wid")
                        ->execute([':bal' => $newBal, ':wid' => $wallet['id']]);

                    $pdo->prepare("
                        INSERT INTO wallet_transactions 
                        (user_id, wallet_id, transaction_type, amount, balance_before, balance_after, reference_type, reference_id, status, description)
                        VALUES (:uid, :wid, 'ADMIN_ADJUSTMENT', :amt, :before, :after, 'wager', :ref, 'COMPLETED', :desc)
                    ")->execute([
                        ':uid' => $userId,
                        ':wid' => $wallet['id'],
                        ':amt' => -$oldWinning,
                        ':before' => $currentBalance,
                        ':after' => $newBal,
                        ':ref' => (string)$wagerId,
                        ':desc' => "Winning reversed on wager #$wagerId marked as LOST by admin: $adminNote",
                    ]);
                    $currentBalance = $newBal;
                }
                $newWinningAmount = 0;
                $newSettlementStatus = 'SETTLED';
                $settledAt = date('Y-m-d H:i:s');
            } elseif ($status === 'PENDING') {
                if ($oldStatus === 'CANCELLED') {
                    // Re-debit stake
                    if ($currentBalance < $amount) {
                        $pdo->rollBack();
                        Response::error("User has insufficient balance ($currentBalance pts) to reactivate $amount pts bet.", 422);
                    }
                    $newBal = $currentBalance - $amount;
                    $pdo->prepare("UPDATE wallets SET virtual_balance = :bal WHERE id = :wid")
                        ->execute([':bal' => $newBal, ':wid' => $wallet['id']]);

                    $pdo->prepare("
                        INSERT INTO wallet_transactions 
                        (user_id, wallet_id, transaction_type, amount, balance_before, balance_after, reference_type, reference_id, status, description)
                        VALUES (:uid, :wid, 'DEMO_WAGER_DEBIT', :amt, :before, :after, 'wager', :ref, 'COMPLETED', :desc)
                    ")->execute([
                        ':uid' => $userId,
                        ':wid' => $wallet['id'],
                        ':amt' => -$amount,
                        ':before' => $currentBalance,
                        ':after' => $newBal,
                        ':ref' => (string)$wagerId,
                        ':desc' => "Wager #$wagerId reactivated by admin: $adminNote",
                    ]);
                    $currentBalance = $newBal;
                } elseif ($oldStatus === 'PENDING' && $amount !== $oldAmount) {
                    $diff = $amount - $oldAmount;
                    if ($diff > 0) {
                        if ($currentBalance < $diff) {
                            $pdo->rollBack();
                            Response::error("User has insufficient balance ($currentBalance pts) to increase stake by $diff pts.", 422);
                        }
                        $newBal = $currentBalance - $diff;
                        $pdo->prepare("UPDATE wallets SET virtual_balance = :bal WHERE id = :wid")
                            ->execute([':bal' => $newBal, ':wid' => $wallet['id']]);

                        $pdo->prepare("
                            INSERT INTO wallet_transactions 
                            (user_id, wallet_id, transaction_type, amount, balance_before, balance_after, reference_type, reference_id, status, description)
                            VALUES (:uid, :wid, 'DEMO_WAGER_DEBIT', :amt, :before, :after, 'wager', :ref, 'COMPLETED', :desc)
                        ")->execute([
                            ':uid' => $userId,
                            ':wid' => $wallet['id'],
                            ':amt' => -$diff,
                            ':before' => $currentBalance,
                            ':after' => $newBal,
                            ':ref' => (string)$wagerId,
                            ':desc' => "Admin increased stake on wager #$wagerId by $diff pts: $adminNote",
                        ]);
                        $currentBalance = $newBal;
                    } elseif ($diff < 0) {
                        $refund = abs($diff);
                        $newBal = $currentBalance + $refund;
                        $pdo->prepare("UPDATE wallets SET virtual_balance = :bal WHERE id = :wid")
                            ->execute([':bal' => $newBal, ':wid' => $wallet['id']]);

                        $pdo->prepare("
                            INSERT INTO wallet_transactions 
                            (user_id, wallet_id, transaction_type, amount, balance_before, balance_after, reference_type, reference_id, status, description)
                            VALUES (:uid, :wid, 'WAGER_REFUND', :amt, :before, :after, 'wager', :ref, 'COMPLETED', :desc)
                        ")->execute([
                            ':uid' => $userId,
                            ':wid' => $wallet['id'],
                            ':amt' => $refund,
                            ':before' => $currentBalance,
                            ':after' => $newBal,
                            ':ref' => (string)$wagerId,
                            ':desc' => "Admin reduced stake on wager #$wagerId by $refund pts: $adminNote",
                        ]);
                        $currentBalance = $newBal;
                    }
                }
                $newWinningAmount = 0;
                $newSettlementStatus = 'UNSETTLED';
                $settledAt = null;
            }

            // Update wager row
            $updateStmt = $pdo->prepare("
                UPDATE demo_wagers 
                SET wager_type = :wt,
                    selection = :sel,
                    wager_amount = :amt,
                    multiplier = :mult,
                    potential_win = :pot,
                    status = :st,
                    settlement_status = :settle,
                    winning_amount = :win,
                    settled_at = :sat,
                    updated_at = NOW()
                WHERE id = :wid
            ");
            $updateStmt->execute([
                ':wt' => $type,
                ':sel' => $selection,
                ':amt' => $amount,
                ':mult' => $multiplier,
                ':pot' => $potentialWin,
                ':st' => $status,
                ':settle' => $newSettlementStatus,
                ':win' => $newWinningAmount,
                ':sat' => $settledAt,
                ':wid' => $wagerId,
            ]);

            // Audit Log
            $auditStmt = $pdo->prepare("
                INSERT INTO audit_logs (user_id, action, entity_type, entity_id, new_values)
                VALUES (:uid, 'ADMIN_EDIT_WAGER', 'wager', :wid, :val)
            ");
            $auditStmt->execute([
                ':uid' => $adminId,
                ':wid' => (string)$wagerId,
                ':val' => json_encode([
                    'old' => [
                        'selection' => $oldSelection,
                        'wager_amount' => $oldAmount,
                        'wager_type' => $oldType,
                        'status' => $oldStatus,
                        'winning_amount' => $oldWinning,
                    ],
                    'new' => [
                        'selection' => $selection,
                        'wager_amount' => $amount,
                        'wager_type' => $type,
                        'status' => $status,
                        'winning_amount' => $newWinningAmount,
                        'potential_win' => $potentialWin,
                    ],
                    'admin_note' => $adminNote,
                ]),
            ]);

            $pdo->commit();

            Response::success([
                'id' => $wagerId,
                'selection' => $selection,
                'wager_amount' => $amount,
                'wager_type' => $type,
                'multiplier' => $multiplier,
                'potential_win' => $potentialWin,
                'status' => $status,
                'winning_amount' => $newWinningAmount,
                'user_new_balance' => $currentBalance,
            ], "Wager #$wagerId updated successfully by admin.");
        } catch (\Throwable $e) {
            $pdo->rollBack();
            Response::error('Failed to update wager: ' . $e->getMessage(), 500);
        }
    }
}

