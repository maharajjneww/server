<?php

return [
    'name' => getenv('APP_NAME') ?: 'Matka Live Results API',
    'env' => getenv('APP_ENV') ?: 'production',
    'debug' => (bool)(getenv('APP_DEBUG') ?: false),
    'url' => getenv('APP_URL') ?: 'https://api.matkalive.internal',
    'timezone' => 'Asia/Kolkata',
    
    // Delayed publication requirement (Section 21)
    'delayed_publishing_minutes' => (int)(getenv('DELAYED_PUBLISHING_MINUTES') ?: 5),

    // CORS Allowed Origins
    'cors_allowed_origins' => explode(',', getenv('CORS_ALLOWED_ORIGINS') ?: '*'),
];
