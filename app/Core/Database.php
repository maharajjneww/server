<?php

namespace App\Core;

use PDO;
use PDOException;

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $config = require __DIR__ . '/../../config/database.php';
            $mysql = $config['connections']['mysql'];

            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $mysql['host'],
                $mysql['port'],
                $mysql['database'],
                $mysql['charset']
            );

            try {
                self::$instance = new PDO(
                    $dsn,
                    $mysql['username'],
                    $mysql['password'],
                    $mysql['options']
                );
            } catch (PDOException $e) {
                // In production, never expose raw DB credentials
                throw new \RuntimeException('Database connection failure: ' . $e->getMessage());
            }
        }

        return self::$instance;
    }
}
