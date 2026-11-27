<?php

declare(strict_types=1);

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;
use UnexpectedValueException;

final class JwtService
{
    private string $issuer;
    private string $audience;
    private int $ttl;

    public function __construct()
    {
        $this->issuer = $this->env(
            'JWT_ISSUER'
        );

        $this->audience = $this->env(
            'JWT_AUDIENCE'
        );

        $this->ttl = (int) $this->env(
            'JWT_TTL_SECONDS'
        );

        if ($this->ttl <= 0) {
            throw new RuntimeException(
                'Vigencia JWT incorrecta'
            );
        }
    }

    public function generar(array $usuario): string
    {
        $ahora = time();

        $payload = [
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'sub' => (string) $usuario['id'],
            'rol' => $usuario['rol'],
            'iat' => $ahora,
            'exp' => $ahora + $this->ttl
        ];

        $privateKey = $this->leerClave(
            'JWT_PRIVATE_KEY_PATH'
        );

        return JWT::encode(
            $payload,
            $privateKey,
            'RS256'
        );
    }

    public function validar(string $token): array
    {
        $publicKey = $this->leerClave(
            'JWT_PUBLIC_KEY_PATH'
        );

        $payload = (array) JWT::decode(
            $token,
            new Key($publicKey, 'RS256')
        );

        if (($payload['iss'] ?? null) !== $this->issuer) {
            throw new UnexpectedValueException(
                'Emisor JWT incorrecto'
            );
        }

        if (($payload['aud'] ?? null) !== $this->audience) {
            throw new UnexpectedValueException(
                'Audiencia JWT incorrecta'
            );
        }

        if (
            !isset($payload['sub']) ||
            !is_string($payload['sub']) ||
            !ctype_digit($payload['sub']) ||
            (int) $payload['sub'] <= 0
        ) {
            throw new UnexpectedValueException(
                'Identificador JWT incorrecto'
            );
        }

        if (
            !isset($payload['rol']) ||
            !in_array(
                $payload['rol'],
                ['cliente', 'admin'],
                true
            )
        ) {
            throw new UnexpectedValueException(
                'Rol JWT incorrecto'
            );
        }

        if (
            !isset($payload['iat'], $payload['exp']) ||
            !is_int($payload['iat']) ||
            !is_int($payload['exp']) ||
            $payload['iat'] > time() ||
            $payload['exp'] <= time() ||
            $payload['exp'] <= $payload['iat'] ||
            $payload['exp'] - $payload['iat'] > $this->ttl
        ) {
            throw new UnexpectedValueException(
                'Vigencia JWT incorrecta'
            );
        }

        return $payload;
    }

    public function vigencia(): int
    {
        return $this->ttl;
    }

    private function leerClave(
        string $variable
    ): string {
        $ruta = $this->env($variable);

        if (!is_readable($ruta)) {
            throw new RuntimeException(
                'No se puede leer la clave RSA'
            );
        }

        $clave = file_get_contents($ruta);

        if ($clave === false || $clave === '') {
            throw new RuntimeException(
                'Clave RSA incorrecta'
            );
        }

        return $clave;
    }

    private function env(string $nombre): string
    {
        $valor = getenv($nombre);

        if ($valor === false || $valor === '') {
            throw new RuntimeException(
                "Variable requerida: {$nombre}"
            );
        }

        return $valor;
    }
}