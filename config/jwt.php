<?php

return [
    'secret' => getenv('JWT_SECRET') ?: 'matka_super_secure_jwt_secret_key_2026_xyz987',
    'algo' => 'HS256',
    'access_token_ttl' => (int)(getenv('JWT_ACCESS_TTL') ?: 2592000), // 30 days
    'refresh_token_ttl' => (int)(getenv('JWT_REFRESH_TTL') ?: 2592000), // 30 days
    'issuer' => 'matka-live-api',
];
