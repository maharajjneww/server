<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use PDO;

class NotificationController {
    public function index(Request $request): void {
        $pdo = Database::getConnection();

        $stmt = $pdo->query("
            SELECT 
                id, 
                type, 
                title, 
                message, 
                market_id as market, 
                result_info, 
                deep_link, 
                created_at as timestamp 
            FROM notifications 
            ORDER BY created_at DESC 
            LIMIT 50
        ");
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Response::success($notifications, 'Notifications retrieved.');
    }

    public function registerDevice(Request $request): void {
        $fcmToken = trim((string)$request->input('fcm_token', ''));
        $platform = (string)$request->input('platform', 'android');

        if (empty($fcmToken)) {
            Response::error('FCM device token is required.', 422);
        }

        $user = $request->getUser();
        $userId = $user ? $user['sub'] : null;

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            INSERT INTO notification_devices (user_id, fcm_token, platform)
            VALUES (:user_id, :token, :platform)
            ON DUPLICATE KEY UPDATE 
                user_id = COALESCE(:user_id, user_id),
                is_active = TRUE,
                last_active_at = NOW()
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':token' => $fcmToken,
            ':platform' => $platform,
        ]);

        Response::success(null, 'Device token registered successfully.');
    }
}
