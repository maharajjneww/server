<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\WalletService;
use Exception;

class WalletController {
    /**
     * GET /api/wallet
     * Retrieve the authenticated user's virtual wallet.
     */
    public function getWallet(Request $request): void {
        $user = $request->getUser();
        if (!$user) {
            Response::unauthorized();
        }

        try {
            $wallet = WalletService::getOrCreateWallet((int)$user['sub']);
            $available = (int)$wallet['virtual_balance'] - (int)$wallet['reserved_balance'];

            Response::success([
                'wallet_id' => (int)$wallet['id'],
                'user_id' => (int)$wallet['user_id'],
                'virtual_balance' => (int)$wallet['virtual_balance'],
                'reserved_balance' => (int)$wallet['reserved_balance'],
                'available_balance' => $available,
                'currency_type' => 'VIRTUAL_POINTS',
                'is_demo' => true,
                'status' => $wallet['status'],
                'updated_at' => $wallet['updated_at'],
            ], 'Virtual wallet retrieved successfully.');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * POST /api/wallet/demo-credit
     * Add simulated demo points. No payment gateway or real currency.
     */
    public function addDemoPoints(Request $request): void {
        $user = $request->getUser();
        if (!$user) {
            Response::unauthorized();
        }

        $amount = (int)$request->input('amount', 0);
        $description = (string)$request->input('description', 'Demo points top-up');

        try {
            $result = WalletService::addDemoPoints((int)$user['sub'], $amount, $description);
            Response::success($result, "Successfully credited " . number_format($amount) . " virtual demo points.", 201);
        } catch (Exception $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    /**
     * POST /api/wallet/demo-withdrawal
     * Request simulated demo withdrawal.
     */
    public function requestDemoWithdrawal(Request $request): void {
        $user = $request->getUser();
        if (!$user) {
            Response::unauthorized();
        }

        $amount = (int)$request->input('amount', 0);
        $note = (string)$request->input('note', 'Simulated demo withdrawal');

        try {
            $result = WalletService::requestDemoWithdrawal((int)$user['sub'], $amount, $note);
            Response::success($result, "Simulated demo withdrawal of " . number_format($amount) . " points recorded.", 201);
        } catch (Exception $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    /**
     * GET /api/wallet/transactions
     * Retrieve the authenticated user's immutable ledger transactions.
     */
    public function getTransactions(Request $request): void {
        $user = $request->getUser();
        if (!$user) {
            Response::unauthorized();
        }

        $limit = min(100, max(1, (int)$request->input('limit', 50)));
        $offset = max(0, (int)$request->input('offset', 0));

        try {
            $transactions = WalletService::getTransactions((int)$user['sub'], $limit, $offset);
            Response::success($transactions, 'Transaction ledger retrieved successfully.');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * GET /api/wallet/withdrawals
     * Retrieve user's demo withdrawal history.
     */
    public function getWithdrawals(Request $request): void {
        $user = $request->getUser();
        if (!$user) {
            Response::unauthorized();
        }

        try {
            $withdrawals = WalletService::getWithdrawals((int)$user['sub']);
            Response::success($withdrawals, 'Demo withdrawal history retrieved.');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * GET /api/wallet/summary
     * High-level summary of wallet, recent transactions and stats.
     */
    public function getSummary(Request $request): void {
        $user = $request->getUser();
        if (!$user) {
            Response::unauthorized();
        }

        try {
            $wallet = WalletService::getOrCreateWallet((int)$user['sub']);
            $transactions = WalletService::getTransactions((int)$user['sub'], 5, 0);

            Response::success([
                'balance' => (int)$wallet['virtual_balance'],
                'reserved_balance' => (int)$wallet['reserved_balance'],
                'available_balance' => (int)$wallet['virtual_balance'] - (int)$wallet['reserved_balance'],
                'currency_type' => 'VIRTUAL_POINTS',
                'recent_transactions' => $transactions,
            ], 'Wallet summary retrieved.');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }
}
