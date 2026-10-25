<?php

use App\Core\Router;
use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\Controllers\MarketController;
use App\Controllers\ResultController;
use App\Controllers\ChartController;
use App\Controllers\NotificationController;
use App\Controllers\ProfileController;
use App\Controllers\WalletController;
use App\Controllers\WagerController;
use App\Controllers\Admin\AdminResultController;
use App\Controllers\Admin\AdminWalletController;
use App\Middleware\AuthMiddleware;
use App\Middleware\AdminMiddleware;

return function (Router $router): void {
    // -------------------------------------------------------------------------
    // Public Authentication Endpoints (Section 30)
    // -------------------------------------------------------------------------
    $router->post('/api/auth/register', [AuthController::class, 'register']);
    $router->post('/api/auth/login', [AuthController::class, 'login']);
    $router->post('/api/auth/logout', [AuthController::class, 'logout']);
    $router->post('/api/auth/forgot-password', [AuthController::class, 'forgotPassword']);

    // -------------------------------------------------------------------------
    // Core Mobile Content Endpoints (Section 30)
    // -------------------------------------------------------------------------
    $router->get('/api/home', [HomeController::class, 'index']);
    $router->get('/api/markets', [MarketController::class, 'index']);
    $router->get('/api/markets/{id}', [MarketController::class, 'show']);

    $router->get('/api/results/latest', [ResultController::class, 'latest']);
    $router->get('/api/results/history', [ResultController::class, 'history']);
    $router->get('/api/results/{market}/{date}', [ResultController::class, 'byMarketAndDate']);

    $router->get('/api/charts/{market}', [ChartController::class, 'show']);

    $router->get('/api/notifications', [NotificationController::class, 'index']);
    $router->post('/api/notifications/device', [NotificationController::class, 'registerDevice']);

    // -------------------------------------------------------------------------
    // Authenticated Profile & Responsible-Use Endpoints
    // -------------------------------------------------------------------------
    $router->get('/api/profile', [ProfileController::class, 'show'], [AuthMiddleware::class]);
    $router->put('/api/profile', [ProfileController::class, 'update'], [AuthMiddleware::class]);
    $router->post('/api/profile/self-exclusion', [ProfileController::class, 'selfExclusion'], [AuthMiddleware::class]);

    // -------------------------------------------------------------------------
    // Authenticated Virtual Wallet & Demo Wager Endpoints (Simulation Mode)
    // -------------------------------------------------------------------------
    $router->get('/api/wallet', [WalletController::class, 'getWallet'], [AuthMiddleware::class]);
    $router->get('/api/wallet/summary', [WalletController::class, 'getSummary'], [AuthMiddleware::class]);
    $router->post('/api/wallet/demo-credit', [WalletController::class, 'addDemoPoints'], [AuthMiddleware::class]);
    $router->post('/api/wallet/demo-withdrawal', [WalletController::class, 'requestDemoWithdrawal'], [AuthMiddleware::class]);
    $router->get('/api/wallet/transactions', [WalletController::class, 'getTransactions'], [AuthMiddleware::class]);
    $router->get('/api/wallet/withdrawals', [WalletController::class, 'getWithdrawals'], [AuthMiddleware::class]);

    $router->post('/api/wagers', [WagerController::class, 'create'], [AuthMiddleware::class]);
    $router->get('/api/wagers', [WagerController::class, 'index'], [AuthMiddleware::class]);
    $router->get('/api/wagers/history', [WagerController::class, 'history'], [AuthMiddleware::class]);

    // -------------------------------------------------------------------------
    // Administrative Endpoints (Section 30)
    // -------------------------------------------------------------------------
    $router->get('/api/admin/results/pending', [AdminResultController::class, 'pending'], [AuthMiddleware::class, AdminMiddleware::class]);
    $router->post('/api/admin/results', [AdminResultController::class, 'create'], [AuthMiddleware::class, AdminMiddleware::class]);
    $router->post('/api/admin/results/{id}/publish', [AdminResultController::class, 'publishNow'], [AuthMiddleware::class, AdminMiddleware::class]);
    $router->post('/api/admin/results/{id}/schedule', [AdminResultController::class, 'schedule'], [AuthMiddleware::class, AdminMiddleware::class]);
    $router->post('/api/admin/results/{id}/reject', [AdminResultController::class, 'reject'], [AuthMiddleware::class, AdminMiddleware::class]);
    $router->post('/api/admin/sync-live-feed', [AdminResultController::class, 'syncLiveFeed'], [AuthMiddleware::class, AdminMiddleware::class]);
    $router->post('/api/admin/markets/{id}/reset-today', [AdminResultController::class, 'resetTodayResult'], [AuthMiddleware::class, AdminMiddleware::class]);

    // Admin Wallet & Wager Management
    $router->get('/api/admin/wallets', [AdminWalletController::class, 'wallets'], [AuthMiddleware::class, AdminMiddleware::class]);
    $router->get('/api/admin/wallets/{id}', [AdminWalletController::class, 'userWallet'], [AuthMiddleware::class, AdminMiddleware::class]);
    $router->post('/api/admin/wallet-adjustment', [AdminWalletController::class, 'adjustPoints'], [AuthMiddleware::class, AdminMiddleware::class]);
    $router->get('/api/admin/demo-withdrawals', [AdminWalletController::class, 'withdrawals'], [AuthMiddleware::class, AdminMiddleware::class]);
    $router->post('/api/admin/demo-withdrawals/{id}/approve', [AdminWalletController::class, 'approveWithdrawal'], [AuthMiddleware::class, AdminMiddleware::class]);
    $router->post('/api/admin/demo-withdrawals/{id}/reject', [AdminWalletController::class, 'rejectWithdrawal'], [AuthMiddleware::class, AdminMiddleware::class]);
    $router->get('/api/admin/wagers/analysis', [AdminWalletController::class, 'betAnalysis'], [AuthMiddleware::class, AdminMiddleware::class]);
    $router->get('/api/admin/wagers/market-summary', [AdminWalletController::class, 'marketBetSummary'], [AuthMiddleware::class, AdminMiddleware::class]);
    $router->get('/api/admin/wagers', [AdminWalletController::class, 'wagers'], [AuthMiddleware::class, AdminMiddleware::class]);
    $router->get('/api/admin/wagers/{id}', [AdminWalletController::class, 'showWager'], [AuthMiddleware::class, AdminMiddleware::class]);
    $router->post('/api/admin/wagers/{id}', [AdminWalletController::class, 'updateWager'], [AuthMiddleware::class, AdminMiddleware::class]);
    $router->put('/api/admin/wagers/{id}', [AdminWalletController::class, 'updateWager'], [AuthMiddleware::class, AdminMiddleware::class]);
};
