<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use DateTimeImmutable;
use DateTimeZone;

final class Usuario
{
    public function __construct(
        private PDO $db
    ) {}

    public function crear(
        string $nombre,
        string $correo,
        string $contrasenaHash
    ): array {
        $sql = "
            INSERT INTO usuarios (
                nombre,
                correo,
                contrasena_hash,
                rol
            )
            VALUES (
                :nombre,
                :correo,
                :contrasena_hash,
                'cliente'
            )
            RETURNING
                id,
                nombre,
                correo,
                rol,
                activo,
                creado_en
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            'nombre' => $nombre,
            'correo' => $correo,
            'contrasena_hash' => $contrasenaHash
        ]);

        return self::perfil($stmt->fetch());
    }

    public function buscarPorCorreo(
        string $correo
    ): ?array {
        $sql = "
            SELECT *
            FROM usuarios
            WHERE correo = :correo
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            'correo' => $correo
        ]);

        $usuario = $stmt->fetch();

        return $usuario ?: null;
    }

    public function buscarPorId(int $id): ?array
    {
        $sql = "
            SELECT
                id,
                nombre,
                correo,
                rol,
                activo,
                creado_en
            FROM usuarios
            WHERE id = :id
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            'id' => $id
        ]);

        $usuario = $stmt->fetch();

        return $usuario
            ? self::perfil($usuario)
            : null;
    }

    public static function estaActivo(
        mixed $valor
    ): bool {
        return in_array(
            $valor,
            [true, 1, '1', 't', 'true'],
            true
        );
    }

    public static function perfil(
        array $usuario
    ): array {
        $fecha = new DateTimeImmutable(
            $usuario['creado_en']
        );

        return [
            'id' => (int) $usuario['id'],
            'nombre' => $usuario['nombre'],
            'correo' => $usuario['correo'],
            'rol' => $usuario['rol'],
            'activo' => self::estaActivo(
                $usuario['activo']
            ),
            'creado_en' => $fecha
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z')
        ];
    }
}