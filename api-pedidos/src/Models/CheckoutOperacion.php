<?php

declare(strict_types=1);

namespace App\Models;

use App\Config\Database;
use App\Utils\ApiException;
use PDO;

final class CheckoutOperacion
{
    private PDO $pdo;

    private const CAMPOS_ACTUALIZABLES = [
        'estado',
        'pago_id',
        'pago_estado',
        'pago_referencia',
        'inventario_confirmado',
        'inventario_liberado',
        'envio_generado',
        'carrito_vaciado',
        'ultimo_error',
        'completado_en'
    ];

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connect();
    }

    public function crearORecuperar(
        int $pedidoId,
        string $claveIdempotencia,
        string $solicitudHash
    ): array {
        $stmt = $this->pdo->prepare('
            INSERT INTO checkout_operaciones (
                pedido_id,
                clave_idempotencia,
                solicitud_hash,
                estado
            )
            VALUES (
                :pedido_id,
                :clave,
                :hash,
                \'iniciado\'
            )
            ON CONFLICT DO NOTHING
        ');

        $stmt->execute([
            'pedido_id' => $pedidoId,
            'clave' => $claveIdempotencia,
            'hash' => $solicitudHash
        ]);

        $operacion = $this->buscarPorPedido(
            $pedidoId
        );

        if ($operacion === null) {
            throw new ApiException(
                409,
                'CLAVE_IDEMPOTENCIA_EN_USO',
                'La clave pertenece a otra operacion'
            );
        }

        if (!hash_equals(
            $operacion['clave_idempotencia'],
            $claveIdempotencia
        )) {
            throw new ApiException(
                409,
                'CHECKOUT_EXISTENTE',
                'El pedido ya tiene una operacion de checkout'
            );
        }

        if (!hash_equals(
            $operacion['solicitud_hash'],
            $solicitudHash
        )) {
            throw new ApiException(
                409,
                'IDEMPOTENCIA_CONFLICTO',
                'La solicitud no coincide con la operacion original'
            );
        }

        return $operacion;
    }

    public function buscarPorPedido(
        int $pedidoId
    ): ?array {
        $stmt = $this->pdo->prepare('
            SELECT *
            FROM checkout_operaciones
            WHERE pedido_id = :pedido_id
        ');

        $stmt->execute([
            'pedido_id' => $pedidoId
        ]);

        $operacion = $stmt->fetch();

        return $operacion === false
            ? null
            : $this->formatear($operacion);
    }

    public function buscarPorClave(
        string $clave
    ): ?array {
        $stmt = $this->pdo->prepare('
            SELECT *
            FROM checkout_operaciones
            WHERE clave_idempotencia = :clave
        ');

        $stmt->execute([
            'clave' => $clave
        ]);

        $operacion = $stmt->fetch();

        return $operacion === false
            ? null
            : $this->formatear($operacion);
    }

    public function actualizar(
        int $pedidoId,
        array $campos
    ): array {
        if ($campos === []) {
            throw new ApiException(
                500,
                'ACTUALIZACION_INVALIDA',
                'No se indicaron campos para actualizar'
            );
        }

        $asignaciones = [];

        foreach ($campos as $campo => $valor) {
            if (!in_array(
                $campo,
                self::CAMPOS_ACTUALIZABLES,
                true
            )) {
                throw new ApiException(
                    500,
                    'CAMPO_NO_PERMITIDO',
                    'Campo de actualizacion no permitido'
                );
            }

            $asignaciones[] =
                "{$campo} = :{$campo}";
        }

        $sql = '
            UPDATE checkout_operaciones
            SET ' . implode(', ', $asignaciones) . '
            WHERE pedido_id = :pedido_id
            RETURNING *
        ';

        $stmt = $this->pdo->prepare($sql);

        foreach ($campos as $campo => $valor) {
            $tipo = match (true) {
                $valor === null => PDO::PARAM_NULL,
                is_bool($valor) => PDO::PARAM_BOOL,
                is_int($valor) => PDO::PARAM_INT,
                default => PDO::PARAM_STR
            };

            $stmt->bindValue(
                ':' . $campo,
                $valor,
                $tipo
            );
        }

        $stmt->bindValue(
            ':pedido_id',
            $pedidoId,
            PDO::PARAM_INT
        );

        $stmt->execute();

        $operacion = $stmt->fetch();

        if ($operacion === false) {
            throw new ApiException(
                404,
                'CHECKOUT_NO_ENCONTRADO',
                'No existe la operacion de checkout'
            );
        }

        return $this->formatear($operacion);
    }

    public function registrarIntento(
        int $pedidoId
    ): void {
        $stmt = $this->pdo->prepare('
            UPDATE checkout_operaciones
            SET intentos = intentos + 1
            WHERE pedido_id = :pedido_id
        ');

        $stmt->execute([
            'pedido_id' => $pedidoId
        ]);
    }

    public function registrarPago(
        int $pedidoId,
        array $pago
    ): array {
        if (!in_array(
            $pago['estado'] ?? null,
            ['aprobado', 'rechazado'],
            true
        )) {
            throw new ApiException(
                503,
                'RESPUESTA_PAGO_INVALIDA',
                'El estado del pago no es valido'
            );
        }

        return $this->actualizar(
            $pedidoId,
            [
                'pago_id' => (int) $pago['id'],
                'pago_estado' => $pago['estado'],
                'pago_referencia' =>
                    $pago['referencia'],
                'estado' =>
                    $pago['estado'] === 'aprobado'
                        ? 'pago_aprobado'
                        : 'pago_rechazado',
                'ultimo_error' => null
            ]
        );
    }

    public function registrarError(
        int $pedidoId,
        string $mensaje
    ): array {
        return $this->actualizar(
            $pedidoId,
            [
                'ultimo_error' =>
                    mb_substr($mensaje, 0, 1000)
            ]
        );
    }

    public function completar(
        int $pedidoId
    ): array {
        $stmt = $this->pdo->prepare('
            UPDATE checkout_operaciones
            SET
                estado = \'completado\',
                completado_en = COALESCE(
                    completado_en,
                    CURRENT_TIMESTAMP
                ),
                ultimo_error = NULL
            WHERE pedido_id = :pedido_id
              AND (
                  (
                      pago_estado = \'aprobado\'
                      AND inventario_confirmado = TRUE
                      AND envio_generado = TRUE
                      AND carrito_vaciado = TRUE
                  )
                  OR
                  (
                      pago_estado = \'rechazado\'
                      AND inventario_liberado = TRUE
                  )
              )
            RETURNING *
        ');

        $stmt->execute([
            'pedido_id' => $pedidoId
        ]);

        $operacion = $stmt->fetch();

        if ($operacion === false) {
            throw new ApiException(
                409,
                'CHECKOUT_INCOMPLETO',
                'Todavia existen operaciones pendientes'
            );
        }

        return $this->formatear($operacion);
    }

    public function adquirirBloqueo(
        int $pedidoId
    ): bool {
        $stmt = $this->pdo->prepare('
            SELECT pg_try_advisory_lock(
                CAST(:pedido_id AS BIGINT)
            )
        ');

        $stmt->execute([
            'pedido_id' => $pedidoId
        ]);

        return $this->aBooleano(
            $stmt->fetchColumn()
        );
    }

    public function liberarBloqueo(
        int $pedidoId
    ): void {
        $stmt = $this->pdo->prepare('
            SELECT pg_advisory_unlock(
                CAST(:pedido_id AS BIGINT)
            )
        ');

        $stmt->execute([
            'pedido_id' => $pedidoId
        ]);
    }

    private function formatear(
        array $operacion
    ): array {
        foreach (
            ['id', 'pedido_id', 'intentos']
            as $campo
        ) {
            $operacion[$campo] =
                (int) $operacion[$campo];
        }

        if ($operacion['pago_id'] !== null) {
            $operacion['pago_id'] =
                (int) $operacion['pago_id'];
        }

        foreach (
            [
                'inventario_confirmado',
                'inventario_liberado',
                'envio_generado',
                'carrito_vaciado'
            ] as $campo
        ) {
            $operacion[$campo] =
                $this->aBooleano(
                    $operacion[$campo]
                );
        }

        return $operacion;
    }

    private function aBooleano(
        mixed $valor
    ): bool {
        return in_array(
            $valor,
            [true, 1, '1', 't', 'true'],
            true
        );
    }
}