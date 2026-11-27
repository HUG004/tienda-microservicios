<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\JwtService;
use App\Utils\ApiException;

final class AuthMiddleware
{
    public static function authenticate(): array
    {
        $authorization =
            $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if (
            !is_string($authorization)
            || trim($authorization) === ''
        ) {
            throw new ApiException(
                401,
                'TOKEN_REQUERIDO',
                'Se requiere un token de autenticacion'
            );
        }

        if (!preg_match(
            '/^Bearer[ \t]+(\S+)$/iD',
            trim($authorization),
            $matches
        )) {
            throw new ApiException(
                401,
                'TOKEN_INVALIDO',
                'El encabezado de autenticacion es invalido'
            );
        }

        $jwtService = new JwtService();

        return $jwtService->validate(
            $matches[1]
        );
    }

    public static function requireRole(
        array $user,
        string ...$roles
    ): void {
        if (!in_array(
            $user['rol'] ?? null,
            $roles,
            true
        )) {
            throw new ApiException(
                403,
                'ACCESO_DENEGADO',
                'No tienes permisos para esta operacion'
            );
        }
    }

    public static function requireOwner(
        array $user,
        int $ownerId,
        bool $allowAdmin = false
    ): void {
        if (($user['id'] ?? null) === $ownerId) {
            return;
        }

        if (
            $allowAdmin
            && ($user['rol'] ?? null) === 'admin'
        ) {
            return;
        }

        throw new ApiException(
            403,
            'ACCESO_DENEGADO',
            'No tienes permisos para acceder a este recurso'
        );
    }
}