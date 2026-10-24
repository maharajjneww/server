<?php

namespace App\Core;

class Response {
    public static function json(
        mixed $data = null,
        string $message = 'Request completed successfully',
        int $statusCode = 200,
        bool $success = true
    ): void {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode([
            'success' => $success,
            'data' => $data,
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function success(mixed $data = null, string $message = 'Success', int $statusCode = 200): void {
        self::json($data, $message, $statusCode, true);
    }

    public static function error(string $message = 'Request failed', int $statusCode = 400, mixed $errors = null): void {
        self::json($errors, $message, $statusCode, false);
    }

    public static function unauthorized(string $message = 'Unauthorized access'): void {
        self::error($message, 401);
    }

    public static function forbidden(string $message = 'Forbidden action'): void {
        self::error($message, 403);
    }

    public static function notFound(string $message = 'Resource not found'): void {
        self::error($message, 404);
    }
}
