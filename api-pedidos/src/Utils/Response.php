<?php

declare(strict_types=1);

namespace App\Utils;

use JsonException;

final class Response
{
    public static function json(
        array $data,
        int $status = 200
    ): never {
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_THROW_ON_ERROR
        );

        http_response_code($status);

        header(
            'Content-Type: application/json; charset=utf-8'
        );

        header('Cache-Control: no-store');

        echo $json;

        exit;
    }

    public static function success(
        string $message,
        mixed $data = [],
        int $status = 200
    ): never {
        self::json(
            [
                'mensaje' => $message,
                'datos' => $data
            ],
            $status
        );
    }

    public static function error(
        string $code,
        string $message,
        int $status = 400
    ): never {
        self::json(
            [
                'error' => [
                    'codigo' => $code,
                    'mensaje' => $message
                ]
            ],
            $status
        );
    }

    public static function fromException(
        ApiException $exception
    ): never {
        self::error(
            $exception->getErrorCode(),
            $exception->getMessage(),
            $exception->getHttpStatus()
        );
    }

    public static function readJson(): array
    {
        $body = file_get_contents('php://input');

        if ($body === false || trim($body) === '') {
            throw new ApiException(
                400,
                'CUERPO_REQUERIDO',
                'El cuerpo de la solicitud es obligatorio'
            );
        }

        try {
            $data = json_decode(
                $body,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new ApiException(
                400,
                'JSON_INVALIDO',
                'El cuerpo de la solicitud no contiene JSON valido',
                $exception
            );
        }

        if (
            !is_array($data)
            || !str_starts_with(ltrim($body), '{')
        ) {
            throw new ApiException(
                400,
                'JSON_INVALIDO',
                'Se esperaba un objeto JSON'
            );
        }

        return $data;
    }
}