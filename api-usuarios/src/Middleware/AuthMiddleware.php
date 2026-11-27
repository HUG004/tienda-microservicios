<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\JwtService;
use App\Utils\Response;
use UnexpectedValueException;

final class AuthMiddleware
{
    public function __construct(
        private JwtService $jwt
    ) {}

    public function autenticar(): array
    {
        $authorization =
            $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if (
            !preg_match(
                '/^Bearer\s+(\S+)$/i',
                trim($authorization),
                $matches
            )
        ) {
            Response::error(
                'TOKEN_REQUERIDO',
                'Se requiere un token de autenticación',
                401
            );
        }

        $token = $matches[1];

        try {
            return $this->jwt->validar($token);
        } catch (UnexpectedValueException $e) {
            Response::error(
                'TOKEN_INVALIDO',
                'El token es inválido o ha expirado',
                401
            );
        } catch (\InvalidArgumentException $e) {
            Response::error(
                'TOKEN_INVALIDO',
                'El token es inválido',
                401
            );
        }
    }

    public function exigirRol(
        array $usuario,
        string $rol
    ): void {
        if (($usuario['rol'] ?? null) !== $rol) {
            Response::error(
                'ACCESO_DENEGADO',
                'No tienes permisos para esta operación',
                403
            );
        }
    }

    public function exigirPropietarioOAdmin(
        array $usuario,
        int $usuarioId
    ): void {
        $esPropietario =
            (int) $usuario['sub'] === $usuarioId;

        $esAdmin =
            $usuario['rol'] === 'admin';

        if (!$esPropietario && !$esAdmin) {
            Response::error(
                'ACCESO_DENEGADO',
                'No tienes permisos para consultar este usuario',
                403
            );
        }
    }
}