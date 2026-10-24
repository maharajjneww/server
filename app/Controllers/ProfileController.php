<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use PDO;

class ProfileController {
    public function show(Request $request): void {
        $jwtUser = $request->getUser();
        if (!$jwtUser) {
            Response::unauthorized();
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT 
                u.id, 
                u.uuid, 
                u.name, 
                u.email, 
                u.phone, 
                u.status, 
                u.verification_status,
                p.avatar_url,
                p.timezone,
                p.notification_preferences
            FROM users u
            LEFT JOIN user_profiles p ON p.user_id = u.id
            WHERE u.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $jwtUser['sub']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            Response::notFound('User profile not found.');
        }

        Response::success($user, 'User profile retrieved.');
    }

    public function update(Request $request): void {
        $jwtUser = $request->getUser();
        if (!$jwtUser) {
            Response::unauthorized();
        }

        $name = trim((string)$request->input('name', ''));
        $phone = trim((string)$request->input('phone', ''));

        if (empty($name)) {
            Response::error('Name cannot be empty.', 422);
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            UPDATE users 
            SET name = :name, phone = :phone, updated_at = NOW() 
            WHERE id = :id
        ");
        $stmt->execute([
            ':name' => $name,
            ':phone' => $phone ?: null,
            ':id' => $jwtUser['sub'],
        ]);

        Response::success(null, 'Profile updated successfully.');
    }

    public function selfExclusion(Request $request): void {
        $jwtUser = $request->getUser();
        if (!$jwtUser) {
            Response::unauthorized();
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            UPDATE users 
            SET status = 'self_excluded', updated_at = NOW() 
            WHERE id = :id
        ");
        $stmt->execute([':id' => $jwtUser['sub']]);

        // Audit log
        $audit = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, entity_type, entity_id, new_values)
            VALUES (:uid, 'SELF_EXCLUSION_REQUEST', 'user', :uid, :val)
        ");
        $audit->execute([
            ':uid' => $jwtUser['sub'],
            ':val' => json_encode(['status' => 'self_excluded', 'requested_at' => date('c')]),
        ]);

        Response::success(null, 'Self-exclusion period activated. Your account has been temporarily restricted.');
    }
}
