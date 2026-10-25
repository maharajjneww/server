<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');

// CLI Check
if (php_sapi_name() !== 'cli') {
    die("Access denied: Worker can only be executed via CLI.\n");
}

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/app/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

use App\Services\DelayedPublishingService;
use App\Services\ExternalFeedService;

$timestamp = date('Y-m-d H:i:s');
echo "[$timestamp] Starting Matka Live Result Worker Daemon (Updating from DPBoss every minute)...\n";

$isOnce = in_array('--once', $argv ?? []);

while (true) {
    $now = date('Y-m-d H:i:s');
    try {
        // 1. Sync live external data feed from DPBoss (Section 21)
        try {
            $synced = ExternalFeedService::syncFromDpboss();
            echo "[$now] Live DPBoss Feed Synced: " . count($synced) . " markets updated in DB.\n";
        } catch (\Throwable $fe) {
            echo "[$now] DPBoss Sync Warning: " . $fe->getMessage() . "\n";
        }

        // 2. Process delayed publishing queue & wager settlements
        $published = DelayedPublishingService::processPendingResults();
        if ($published > 0) {
            echo "[$now] Published and settled $published eligible delayed result(s).\n";
        }
    } catch (\Throwable $e) {
        echo "[$now] Worker Execution Error: " . $e->getMessage() . "\n";
    }

    if ($isOnce) {
        break;
    }

    // Sleep 60 seconds (1 minute interval)
    sleep(60);
}
