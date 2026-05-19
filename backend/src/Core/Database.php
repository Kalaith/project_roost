<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $host = Env::required('DB_HOST');
        $port = Env::requiredInt('DB_PORT');
        $database = Env::required('DB_NAME');
        $username = Env::required('DB_USERNAME');
        $password = Env::required('DB_PASSWORD');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);

        self::$connection = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return self::$connection;
    }

    public static function requiredTableName(string $envName): string
    {
        $table = Env::required($envName);

        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new RuntimeException("{$envName} must contain only letters, numbers, and underscores.");
        }

        return $table;
    }
}
