<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Core\JWT;
use PDO;

class AuthController {
    public function register(Request $request): void {
        $name = trim((string)$request->input('name', ''));
        $email = trim(strtolower((string)$request->input('email', '')));
        $phone = trim((string)$request->input('phone', ''));
        $password = (string)$request->input('password', '');

        if (empty($name) || empty($email) || empty($password)) {
            Response::error('Name, email, and password are required fields.', 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Invalid email address format.', 422);
        }

        if (strlen($password) < 8) {
            Response::error('Password must be at least 8 characters.', 422);
        }

        $pdo = Database::getConnection();

        // Check duplicate
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        if ($stmt->fetch()) {
            Response::error('An account with this email address already exists.', 409);
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        $insert = $pdo->prepare("
            INSERT INTO users (uuid, name, email, phone, password_hash, role_id, status, verification_status)
            VALUES (:uuid, :name, :email, :phone, :hash, 1, 'active', 'pending')
        ");
        $insert->execute([
            ':uuid' => $uuid,
            ':name' => $name,
            ':email' => $email,
            ':phone' => $phone ?: null,
            ':hash' => $passwordHash,
        ]);

        $userId = (int)$pdo->lastInsertId();

        // Automatically provision virtual demo wallet with initial demo points (Section 4 & 5)
        $wallet = \App\Services\WalletService::getOrCreateWallet($userId);

        // Generate JWT Access & Refresh Token
        $token = JWT::generate([
            'sub' => $userId,
            'uuid' => $uuid,
            'name' => $name,
            'email' => $email,
            'role_id' => 1,
        ], 2592000); // 30 days

        Response::success([
            'user' => [
                'id' => (string)$userId,
                'uuid' => $uuid,
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'verification_status' => 'pending',
            ],
            'wallet' => [
                'virtual_balance' => (int)$wallet['virtual_balance'],
                'reserved_balance' => (int)$wallet['reserved_balance'],
                'available_balance' => (int)$wallet['virtual_balance'] - (int)$wallet['reserved_balance'],
                'currency_type' => 'VIRTUAL_POINTS',
            ],
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => 2592000,
        ], 'Registration completed successfully.', 201);
    }

    public function login(Request $request): void {
        $email = trim(strtolower((string)$request->input('email', '')));
        $password = (string)$request->input('password', '');

        if (empty($email) || empty($password)) {
            Response::error('Email and password are required.', 422);
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT id, uuid, name, email, phone, password_hash, role_id, status, verification_status 
            FROM users 
            WHERE email = :email 
            LIMIT 1
        ");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            Response::error('Invalid credentials provided.', 401);
        }

        if ($user['status'] === 'suspended') {
            Response::forbidden('Your account is currently suspended.');
        }

        if ($user['status'] === 'self_excluded') {
            Response::forbidden('This account has an active self-exclusion period.');
        }

        $token = JWT::generate([
            'sub' => $user['id'],
            'uuid' => $user['uuid'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role_id' => $user['role_id'],
        ], 2592000); // 30 days

        // Ensure user has virtual wallet provisioned
        $wallet = \App\Services\WalletService::getOrCreateWallet((int)$user['id']);

        Response::success([
            'user' => [
                'id' => (string)$user['id'],
                'uuid' => $user['uuid'],
                'name' => $user['name'],
                'email' => $user['email'],
                'phone' => $user['phone'],
                'role_id' => (int)$user['role_id'],
                'role' => ((int)$user['role_id'] === 3 ? 'admin' : 'user'),
                'verification_status' => $user['verification_status'],
            ],
            'wallet' => [
                'virtual_balance' => (int)$wallet['virtual_balance'],
                'reserved_balance' => (int)$wallet['reserved_balance'],
                'available_balance' => (int)$wallet['virtual_balance'] - (int)$wallet['reserved_balance'],
                'currency_type' => 'VIRTUAL_POINTS',
            ],
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => 2592000,
        ], 'Authenticated successfully.');
    }

    public function logout(Request $request): void {
        // In stateless JWT, client deletes token; server logs the revocation if stored
        Response::success(null, 'Logged out successfully.');
    }

    public function forgotPassword(Request $request): void {
        $email = trim((string)$request->input('email', ''));
        if (empty($email)) {
            Response::error('Email is required.', 422);
        }

        // Always return generic success to prevent email enumeration
        Response::success(null, 'If the email exists in our system, password reset instructions have been dispatched.');
    }
}
