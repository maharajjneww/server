<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\WagerService;
use Exception;

class WagerController {
    /**
     * POST /api/wagers
     * Place a new demo wager with idempotency and atomic point deduction.
     */
    public function create(Request $request): void {
        $user = $request->getUser();
        if (!$user) {
            Response::unauthorized();
        }

        $marketId = (string)$request->input('market_id', '');
        $wagerType = (string)$request->input('wager_type', 'jodi');
        $selection = (string)$request->input('selection', '');
        $amount = (int)$request->input('amount', 0);
        $idempotencyKey = (string)$request->input('idempotency_key', '');

        if (empty($marketId) || empty($selection) || $amount <= 0) {
            Response::error('market_id, selection, and a positive amount are required.', 422);
        }

        try {
            $result = WagerService::placeWager(
                (int)$user['sub'],
                $marketId,
                $wagerType,
                $selection,
                $amount,
                $idempotencyKey ?: null
            );

            Response::success($result, 'Demo wager placed successfully.', 201);
        } catch (Exception $e) {
            Response::error($e->getMessage(), 422);
        }
    }

    /**
     * GET /api/wagers
     * List user wagers with optional status filter (e.g., PENDING, WON, LOST).
     */
    public function index(Request $request): void {
        $user = $request->getUser();
        if (!$user) {
            Response::unauthorized();
        }

        $status = $request->input('status');
        $limit = min(100, max(1, (int)$request->input('limit', 50)));
        $offset = max(0, (int)$request->input('offset', 0));

        try {
            $wagers = WagerService::getUserWagers((int)$user['sub'], $status ? (string)$status : null, $limit, $offset);
            Response::success($wagers, 'Wagers retrieved successfully.');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * GET /api/wagers/history
     * Alias for wager history.
     */
    public function history(Request $request): void {
        $this->index($request);
    }
}
