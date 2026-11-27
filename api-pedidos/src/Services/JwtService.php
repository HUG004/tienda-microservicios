<?php

declare(strict_types=1);

namespace App\Services;

use App\Utils\ApiException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

final class JwtService
{
    private string $publicKey;

    private string $issuer;

    private string $audience;

    public function __construct()
    {
        $path = getenv('JWT_PUBLIC_KEY_PATH');

        $issuer = getenv('JWT_ISSUER');

        $audience = getenv('JWT_AUDIENCE');

        if (
            $path === false || $path === ''
            || $issuer === false || $issuer === ''
            || $audience === false || $audience === ''
        ) {
            throw new ApiException(
                500,
                'JWT_CONFIGURACION_INVALIDA',
                'Error de configuracion de autenticacion'
            );
        }

        if (!is_readable($path)) {
            throw new ApiException(
                500,
                'JWT_CLAVE_NO_DISPONIBLE',
                'La clave de autenticacion no esta disponible'
            );
        }

        $publicKey = file_get_contents($path);

        if (
            $publicKey === false
            || openssl_pkey_get_public($publicKey) === false
        ) {
            throw new ApiException(
                500,
                'JWT_CLAVE_INVALIDA',
                'Error de configuracion de autenticacion'
            );
        }

        $this->publicKey = $publicKey;

        $this->issuer = $issuer;

        $this->audience = $audience;
    }

    public function validate(string $token): array
    {
        try {
            $claims = JWT::decode(
                $token,
                new Key(
                    $this->publicKey,
                    'RS256'
                )
            );

        } catch (Throwable $exception) {
            throw new ApiException(
                401,
                'TOKEN_INVALIDO',
                'El token es invalido o ha expirado',
                $exception
            );
        }

        if (
            !isset(
                $claims->iss,
                $claims->aud,
                $claims->sub,
                $claims->rol,
                $claims->iat,
                $claims->exp
            )
        ) {
            $this->invalidToken();
        }

        if ($claims->iss !== $this->issuer) {
            $this->invalidToken();
        }

        if ($claims->aud !== $this->audience) {
            $this->invalidToken();
        }

        if (
            !is_int($claims->iat)
            || !is_int($claims->exp)
            || $claims->iat > time()
            || $claims->exp <= time()
            || $claims->exp <= $claims->iat
        ) {
            $this->invalidToken();
        }

        if (
            !is_string($claims->sub)
            || !preg_match(
                '/^[1-9][0-9]*$/D',
                $claims->sub
            )
        ) {
            $this->invalidToken();
        }

        $userId = filter_var(
            $claims->sub,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1
                ]
            ]
        );

        if ($userId === false) {
            $this->invalidToken();
        }

        if (
            !is_string($claims->rol)
            || !in_array(
                $claims->rol,
                ['cliente', 'admin'],
                true
            )
        ) {
            $this->invalidToken();
        }

        return [
            'id' => $userId,
            'rol' => $claims->rol,
            'iat' => $claims->iat,
            'exp' => $claims->exp
        ];
    }

    private function invalidToken(): never
    {
        throw new ApiException(
            401,
            'TOKEN_INVALIDO',
            'El token es invalido o ha expirado'
        );
    }
}