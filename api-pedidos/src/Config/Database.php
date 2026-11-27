<?php

declare(strict_types=1);

namespace App\Config;

use PDO;
use RuntimeException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connect(): PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $host = self::env('DB_HOST');
        $port = self::env('DB_PORT', '5432');
        $database = self::env('DB_NAME');
        $user = self::env('DB_USER');
        $password = self::env('DB_PASSWORD');

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;connect_timeout=5',
            $host,
            $port,
            $database
        );

        self::$connection = new PDO(
            $dsn,
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false
            ]
        );

        self::$connection->exec("SET TIME ZONE 'UTC'");

        return self::$connection;
    }

    private static function env(
        string $name,
        ?string $default = null
    ): string {
        $value = getenv($name);

        if ($value === false || $value === '') {
            if ($default !== null) {
                return $default;
            }

            throw new RuntimeException(
                "Variable de entorno requerida: {$name}"
            );
        }

        return $value;
    }
}