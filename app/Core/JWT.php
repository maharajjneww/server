<?php

namespace App\Core;

class JWT {
    public static function generate(array $payload, int $ttlSeconds = 2592000): string {
        $config = require __DIR__ . '/../../config/jwt.php';

        $header = [
            'typ' => 'JWT',
            'alg' => 'HS256'
        ];

        $payload['iss'] = $config['issuer'];
        $payload['iat'] = time();
        $payload['exp'] = time() + $ttlSeconds;

        $base64Header = self::base64UrlEncode(json_encode($header));
        $base64Payload = self::base64UrlEncode(json_encode($payload));

        $signature = hash_hmac('sha256', "$base64Header.$base64Payload", $config['secret'], true);
        $base64Signature = self::base64UrlEncode($signature);

        return "$base64Header.$base64Payload.$base64Signature";
    }

    public static function verify(string $token): ?array {
        $config = require __DIR__ . '/../../config/jwt.php';

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$base64Header, $base64Payload, $base64Signature] = $parts;

        $expectedSig = hash_hmac('sha256', "$base64Header.$base64Payload", $config['secret'], true);
        $calculatedSig = self::base64UrlEncode($expectedSig);

        if (!hash_equals($calculatedSig, $base64Signature)) {
            return null; // Invalid signature
        }

        $payload = json_decode(self::base64UrlDecode($base64Payload), true);
        if (!is_array($payload) || !isset($payload['exp']) || $payload['exp'] < time()) {
            return null; // Expired or malformed
        }

        return $payload;
    }

    private static function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
