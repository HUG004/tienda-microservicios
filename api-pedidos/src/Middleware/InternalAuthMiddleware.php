<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Utils\ApiException;

final class InternalAuthMiddleware
{
    public static function hasCredentials(): bool
    {
        return array_key_exists(
            'HTTP_X_INTERNAL_TOKEN',
            $_SERVER
        );
    }

    public static function authenticate(): void
    {
        $expectedToken = getenv(
            'INTERNAL_API_TOKEN'
        );

        if (
            $expectedToken === false
            || strlen($expectedToken) < 32
        ) {
            throw new ApiException(
                500,
                'CONFIGURACION_INTERNA_INVALIDA',
                'Error de configuracion del servicio'
            );
        }

        $receivedToken =
            $_SERVER['HTTP_X_INTERNAL_TOKEN'] ?? '';

        if (
            !is_string($receivedToken)
            || $receivedToken === ''
        ) {
            throw new ApiException(
                401,
                'TOKEN_INTERNO_REQUERIDO',
                'Se requiere autenticacion interna'
            );
        }

        if (!hash_equals(
            $expectedToken,
            $receivedToken
        )) {
            throw new ApiException(
                401,
                'TOKEN_INTERNO_INVALIDO',
                'Las credenciales internas son invalidas'
            );
        }
    }
}