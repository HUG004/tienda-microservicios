<?php

declare(strict_types=1);

namespace App\Utils;

final class Response
{
    public static function json(
        array $body,
        int $status = 200
    ): never {
        http_response_code($status);

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        echo json_encode(
            $body,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_THROW_ON_ERROR
        );

        exit;
    }

    public static function success(
        string $mensaje,
        array $datos = [],
        int $status = 200
    ): never {
        self::json(
            [
                'mensaje' => $mensaje,
                'datos' => $datos
            ],
            $status
        );
    }

    public static function error(
        string $codigo,
        string $mensaje,
        int $status = 400
    ): never {
        self::json(
            [
                'error' => [
                    'codigo' => $codigo,
                    'mensaje' => $mensaje
                ]
            ],
            $status
        );
    }
}