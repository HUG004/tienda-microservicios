<?php

declare(strict_types=1);

namespace App\Models;

use App\Config\Database;
use App\Utils\ApiException;
use PDO;
use Throwable;

final class Pedido
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connect();
    }

    public function crear(
        array $datos,
        array $productos
    ): array {
        $this->pdo->beginTransaction();

        try {
            $sql = '
                INSERT INTO pedidos (
                    usuario_id,
                    subtotal,
                    costo_envio,
                    total,
                    direccion,
                    codigo_postal,
                    estado
                )
                VALUES (
                    :usuario_id,
                    :subtotal,
                    :costo_envio,
                    :total,
                    :direccion,
                    :codigo_postal,
                    :estado
                )
                RETURNING id
            ';

            $stmt = $this->pdo->prepare($sql);

            $stmt->execute([
                'usuario_id' => $datos['usuario_id'],
                'subtotal' => $datos['subtotal'],
                'costo_envio' => $datos['costo_envio'],
                'total' => $datos['total'],
                'direccion' => $datos['direccion'],
                'codigo_postal' => $datos['codigo_postal'],
                'estado' => 'pendiente'
            ]);

            $pedidoId = (int) $stmt->fetchColumn();

            $sqlDetalle = '
                INSERT INTO pedido_detalles (
                    pedido_id,
                    producto_id,
                    nombre_producto,
                    cantidad,
                    precio_unitario,
                    subtotal
                )
                VALUES (
                    :pedido_id,
                    :producto_id,
                    :nombre_producto,
                    :cantidad,
                    :precio_unitario,
                    :subtotal
                )
            ';

            $stmtDetalle = $this->pdo->prepare(
                $sqlDetalle
            );

            foreach ($productos as $producto) {
                $stmtDetalle->execute([
                    'pedido_id' => $pedidoId,
                    'producto_id' =>
                        $producto['producto_id'],
                    'nombre_producto' =>
                        $producto['nombre_producto'],
                    'cantidad' =>
                        $producto['cantidad'],
                    'precio_unitario' =>
                        $producto['precio_unitario'],
                    'subtotal' =>
                        $producto['subtotal']
                ]);
            }

            $pedido = $this->buscarPorId($pedidoId);

            if ($pedido === null) {
                throw new ApiException(
                    500,
                    'ERROR_CREAR_PEDIDO',
                    'No se pudo recuperar el pedido creado'
                );
            }

            $this->pdo->commit();

            return $pedido;

        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function buscarPorId(
        int $pedidoId
    ): ?array {
        $stmt = $this->pdo->prepare('
            SELECT *
            FROM pedidos
            WHERE id = :id
        ');

        $stmt->execute([
            'id' => $pedidoId
        ]);

        $pedido = $stmt->fetch();

        if ($pedido === false) {
            return null;
        }

        $pedido = $this->formatearPedido(
            $pedido
        );

        $pedido['productos'] =
            $this->obtenerDetalles($pedidoId);

        return $pedido;
    }

    public function buscarPorUsuario(
        int $usuarioId
    ): array {
        $stmt = $this->pdo->prepare('
            SELECT
                id,
                usuario_id,
                subtotal,
                costo_envio,
                total,
                estado,
                guia,
                creado_en
            FROM pedidos
            WHERE usuario_id = :usuario_id
            ORDER BY creado_en DESC, id DESC
        ');

        $stmt->execute([
            'usuario_id' => $usuarioId
        ]);

        $pedidos = $stmt->fetchAll();

        return array_map(
            fn(array $pedido): array =>
                $this->formatearPedido($pedido),
            $pedidos
        );
    }

    public function obtenerDetalles(
        int $pedidoId
    ): array {
        $stmt = $this->pdo->prepare('
            SELECT
                producto_id,
                nombre_producto,
                cantidad,
                precio_unitario,
                subtotal
            FROM pedido_detalles
            WHERE pedido_id = :pedido_id
            ORDER BY id ASC
        ');

        $stmt->execute([
            'pedido_id' => $pedidoId
        ]);

        $productos = $stmt->fetchAll();

        foreach ($productos as &$producto) {
            $producto['producto_id'] =
                (int) $producto['producto_id'];

            $producto['cantidad'] =
                (int) $producto['cantidad'];

            $producto['precio_unitario'] =
                (string) $producto['precio_unitario'];

            $producto['subtotal'] =
                (string) $producto['subtotal'];
        }

        unset($producto);

        return $productos;
    }

    public function actualizarEstado(
        int $pedidoId,
        string $estadoEsperado,
        string $nuevoEstado
    ): bool {
        $transiciones = [
            'pendiente' => [
                'pagado',
                'cancelado'
            ],
            'pagado' => [
                'enviado'
            ],
            'enviado' => [],
            'cancelado' => []
        ];

        if (!in_array(
            $nuevoEstado,
            $transiciones[$estadoEsperado] ?? [],
            true
        )) {
            throw new ApiException(
                409,
                'TRANSICION_ESTADO_INVALIDA',
                'El cambio de estado no esta permitido'
            );
        }

        $stmt = $this->pdo->prepare('
            UPDATE pedidos
            SET estado = :nuevo_estado
            WHERE id = :id
              AND estado = :estado_esperado
        ');

        $stmt->execute([
            'id' => $pedidoId,
            'nuevo_estado' => $nuevoEstado,
            'estado_esperado' => $estadoEsperado
        ]);

        return $stmt->rowCount() === 1;
    }

    public function guardarGuia(
        int $pedidoId,
        string $guia
    ): bool {
        $stmt = $this->pdo->prepare('
            UPDATE pedidos
            SET guia = :guia
            WHERE id = :id
              AND estado IN (\'pagado\', \'enviado\')
              AND (
                  guia IS NULL
                  OR guia = :guia_existente
              )
        ');

        $stmt->execute([
            'id' => $pedidoId,
            'guia' => $guia,
            'guia_existente' => $guia
        ]);

        return $stmt->rowCount() === 1;
    }

    public function obtenerCantidadTotal(
        int $pedidoId
    ): int {
        $stmt = $this->pdo->prepare('
            SELECT COALESCE(SUM(cantidad), 0)
            FROM pedido_detalles
            WHERE pedido_id = :pedido_id
        ');

        $stmt->execute([
            'pedido_id' => $pedidoId
        ]);

        return (int) $stmt->fetchColumn();
    }

    private function formatearPedido(
        array $pedido
    ): array {
        $pedido['id'] = (int) $pedido['id'];

        $pedido['usuario_id'] =
            (int) $pedido['usuario_id'];

        foreach (
            ['subtotal', 'costo_envio', 'total']
            as $campo
        ) {
            $pedido[$campo] =
                (string) $pedido[$campo];
        }

        foreach (
            ['creado_en', 'actualizado_en']
            as $campo
        ) {
            if (isset($pedido[$campo])) {
                $fecha = new \DateTimeImmutable(
                    $pedido[$campo]
                );

                $pedido[$campo] = $fecha
                    ->setTimezone(
                        new \DateTimeZone('UTC')
                    )
                    ->format('Y-m-d\TH:i:s\Z');
            }
        }

        $pedido['moneda'] = 'MXN';

        return $pedido;
    }
}