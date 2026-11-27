<?php

declare(strict_types=1);

namespace App\Services;

use App\Clients\CarritoClient;
use App\Clients\ProductosClient;
use App\Clients\InventarioClient;
use App\Clients\EnviosClient;
use App\Models\Pedido;
use App\Models\CheckoutOperacion;
use App\Middleware\AuthMiddleware;
use App\Utils\ApiException;
use App\Utils\Money;

final class PedidoService
{
    private Pedido $pedidos;
    private CheckoutOperacion $operaciones;

    public function __construct()
    {
        $this->pedidos = new Pedido();
        $this->operaciones = new CheckoutOperacion();
    }

    public function crear(
        array $usuario,
        array $datos
    ): array {
        AuthMiddleware::requireRole(
            $usuario,
            'cliente'
        );

        $usuarioId = $datos['usuario_id'] ?? null;
        $direccion = $datos['direccion'] ?? null;
        $codigoPostal = $datos['codigo_postal'] ?? null;

        if (
            !is_int($usuarioId)
            || $usuarioId <= 0
        ) {
            throw new ApiException(
                400,
                'USUARIO_INVALIDO',
                'El identificador del usuario es invalido'
            );
        }

        AuthMiddleware::requireOwner(
            $usuario,
            $usuarioId
        );

        if (
            !is_string($direccion)
            || trim($direccion) === ''
            || mb_strlen($direccion) > 500
        ) {
            throw new ApiException(
                400,
                'DIRECCION_INVALIDA',
                'La direccion es obligatoria'
            );
        }

        $direccion = trim($direccion);

        if (
            !is_string($codigoPostal)
            || !preg_match('/^[0-9]{5}$/D', $codigoPostal)
        ) {
            throw new ApiException(
                400,
                'CODIGO_POSTAL_INVALIDO',
                'El codigo postal debe contener cinco digitos'
            );
        }

        // Consultar el carrito del usuario.

        $carrito = (new CarritoClient())
            ->obtener($usuarioId);

        if (
            ($carrito['usuario_id'] ?? null)
                !== $usuarioId
            || !isset($carrito['productos'])
            || !is_array($carrito['productos'])
        ) {
            throw new ApiException(
                503,
                'RESPUESTA_CARRITO_INVALIDA',
                'La respuesta del carrito es invalida'
            );
        }

        if ($carrito['productos'] === []) {
            throw new ApiException(
                400,
                'CARRITO_VACIO',
                'El carrito no contiene productos'
            );
        }

        $productosClient = new ProductosClient();
        $inventarioClient = new InventarioClient();
        $enviosClient = new EnviosClient();

        $detalles = [];
        $productosReserva = [];
        $productosProcesados = [];

        $subtotal = '0.00';
        $cantidadTotal = 0;

        // Consultar precios oficiales y calcular importes.

        foreach ($carrito['productos'] as $item) {
            $productoId = $item['producto_id'] ?? null;
            $cantidad = $item['cantidad'] ?? null;

            if (
                !is_int($productoId)
                || $productoId <= 0
                || !is_int($cantidad)
                || $cantidad <= 0
                || $cantidad > 10000
                || isset($productosProcesados[$productoId])
            ) {
                throw new ApiException(
                    503,
                    'PRODUCTO_CARRITO_INVALIDO',
                    'El carrito contiene informacion invalida'
                );
            }

            $productosProcesados[$productoId] = true;

            $producto = $productosClient
                ->obtener($productoId);

            if (
                ($producto['id'] ?? null) !== $productoId
                || ($producto['activo'] ?? false) !== true
            ) {
                throw new ApiException(
                    409,
                    'PRODUCTO_NO_DISPONIBLE',
                    'Uno de los productos no esta disponible'
                );
            }

            if (
                !isset($producto['nombre'], $producto['precio'])
                || !is_string($producto['nombre'])
                || trim($producto['nombre']) === ''
                || mb_strlen($producto['nombre']) > 150
                || !is_string($producto['precio'])
            ) {
                throw new ApiException(
                    503,
                    'RESPUESTA_PRODUCTO_INVALIDA',
                    'La informacion del producto es invalida'
                );
            }

            $precio = Money::fromCents(
                Money::toCents($producto['precio'])
            );

            $subtotalProducto = Money::multiply(
                $precio,
                $cantidad
            );

            $subtotal = Money::add(
                $subtotal,
                $subtotalProducto
            );

            $cantidadTotal += $cantidad;

            if ($cantidadTotal > 10000) {
                throw new ApiException(
                    400,
                    'CANTIDAD_MAXIMA_EXCEDIDA',
                    'El pedido excede la cantidad permitida'
                );
            }

            $detalles[] = [
                'producto_id' => $productoId,
                'nombre_producto' => $producto['nombre'],
                'cantidad' => $cantidad,
                'precio_unitario' => $precio,
                'subtotal' => $subtotalProducto
            ];

            $productosReserva[] = [
                'producto_id' => $productoId,
                'cantidad' => $cantidad
            ];
        }

        // Solicitar cotizacion del envio.

        $cotizacion = $enviosClient->cotizar(
            $codigoPostal,
            $cantidadTotal
        );

        if (
            !isset($cotizacion['costo_envio'])
            || !is_string($cotizacion['costo_envio'])
            || ($cotizacion['moneda'] ?? null) !== 'MXN'
        ) {
            throw new ApiException(
                503,
                'COTIZACION_INVALIDA',
                'No se pudo obtener una cotizacion valida'
            );
        }

        $costoEnvio = Money::fromCents(
            Money::toCents($cotizacion['costo_envio'])
        );

        $total = Money::add(
            $subtotal,
            $costoEnvio
        );

        // Guardar pedido y detalles en PostgreSQL.

        $pedido = $this->pedidos->crear(
            [
                'usuario_id' => $usuarioId,
                'subtotal' => $subtotal,
                'costo_envio' => $costoEnvio,
                'total' => $total,
                'direccion' => $direccion,
                'codigo_postal' => $codigoPostal
            ],
            $detalles
        );

        $pedidoId = $pedido['id'];

        // Reservar inventario.

        try {
            $reserva = $inventarioClient->reservar(
                $pedidoId,
                $productosReserva
            );

            if (
                ($reserva['pedido_id'] ?? null) !== $pedidoId
                || ($reserva['estado'] ?? null) !== 'pendiente'
            ) {
                throw new ApiException(
                    503,
                    'RESPUESTA_RESERVA_INVALIDA',
                    'No fue posible verificar la reserva'
                );
            }

        } catch (ApiException $exception) {
            if (in_array(
                $exception->getHttpStatus(),
                [400, 404, 409],
                true
            )) {
                $this->pedidos->actualizarEstado(
                    $pedidoId,
                    'pendiente',
                    'cancelado'
                );

                throw $exception;
            }

            // Un timeout no significa que la reserva
            // haya sido rechazada.

            throw new ApiException(
                503,
                'RESERVA_NO_VERIFICADA',
                'No se pudo confirmar la reserva del pedido '
                    . $pedidoId,
                $exception
            );
        }

        return $pedido;
    }

    public function obtenerPorId(
        int $pedidoId,
        array $usuario,
        bool $interno = false
    ): array {
        $pedido = $this->buscarExistente($pedidoId);

        if (!$interno) {
            AuthMiddleware::requireOwner(
                $usuario,
                $pedido['usuario_id'],
                true
            );
        }

        return $pedido;
    }

    public function obtenerPorUsuario(
        int $usuarioId,
        array $usuario
    ): array {
        if ($usuarioId <= 0) {
            throw new ApiException(
                400,
                'USUARIO_INVALIDO',
                'El identificador del usuario es invalido'
            );
        }

        AuthMiddleware::requireOwner(
            $usuario,
            $usuarioId,
            true
        );

        return $this->pedidos->buscarPorUsuario(
            $usuarioId
        );
    }

    public function cambiarEstado(
        int $pedidoId,
        string $nuevoEstado,
        array $usuario,
        bool $interno = false
    ): array {
        if (!$interno) {
            AuthMiddleware::requireRole(
                $usuario,
                'admin'
            );
        }

        if (!in_array(
            $nuevoEstado,
            ['cancelado', 'enviado'],
            true
        )) {
            throw new ApiException(
                409,
                'TRANSICION_ESTADO_INVALIDA',
                'El estado solicitado no esta permitido'
            );
        }

        if (!$this->operaciones->adquirirBloqueo(
            $pedidoId
        )) {
            throw new ApiException(
                409,
                'PEDIDO_EN_PROCESO',
                'El pedido esta siendo procesado'
            );
        }

        try {
            $pedido = $this->buscarExistente(
                $pedidoId
            );

            if ($pedido['estado'] === $nuevoEstado) {
                return $this->resumenEstado($pedido);
            }

            if ($nuevoEstado === 'cancelado') {
                if ($pedido['estado'] !== 'pendiente') {
                    throw new ApiException(
                        409,
                        'PEDIDO_NO_CANCELABLE',
                        'El pedido no puede cancelarse'
                    );
                }

                $operacion = $this->operaciones
                    ->buscarPorPedido($pedidoId);

                // Solo permitir la cancelacion de un checkout
                // que todavia no ha solicitado el pago.

                if ($operacion !== null) {
                    $cancelacionSegura =
                        $operacion['estado'] === 'iniciado'
                        && $operacion['pago_estado'] === null
                        && $operacion['pago_id'] === null;

                    if (!$cancelacionSegura) {
                        throw new ApiException(
                            409,
                            'CHECKOUT_EXISTENTE',
                            'El pedido tiene un checkout en proceso'
                        );
                    }
                }

                // Liberar inventario antes de cancelar.

                try {
                    $reserva = (new InventarioClient())
                        ->liberar($pedidoId);

                    if (
                        ($reserva['pedido_id'] ?? null)
                            !== $pedidoId
                        || !in_array(
                            $reserva['estado'] ?? null,
                            ['liberada', 'expirada'],
                            true
                        )
                    ) {
                        throw new ApiException(
                            503,
                            'LIBERACION_NO_VERIFICADA',
                            'No se pudo verificar la liberacion'
                        );
                    }

                } catch (ApiException $exception) {
                    if (
                        $exception->getHttpStatus() !== 404
                        || $exception->getErrorCode()
                            !== 'RESERVA_NO_ENCONTRADA'
                    ) {
                        throw $exception;
                    }
                }

            } elseif (
                $pedido['estado'] !== 'pagado'
                || empty($pedido['guia'])
            ) {
                throw new ApiException(
                    409,
                    'PEDIDO_NO_ENVIABLE',
                    'El pedido debe estar pagado y tener guia'
                );
            }

            $actualizado = $this->pedidos
                ->actualizarEstado(
                    $pedidoId,
                    $pedido['estado'],
                    $nuevoEstado
                );

            if (!$actualizado) {
                throw new ApiException(
                    409,
                    'ESTADO_PEDIDO_MODIFICADO',
                    'El estado del pedido ha cambiado'
                );
            }

            return $this->resumenEstado(
                $this->buscarExistente($pedidoId)
            );

        } finally {
            $this->operaciones->liberarBloqueo(
                $pedidoId
            );
        }
    }

    private function buscarExistente(
        int $pedidoId
    ): array {
        if ($pedidoId <= 0) {
            throw new ApiException(
                400,
                'PEDIDO_INVALIDO',
                'El identificador del pedido es invalido'
            );
        }

        $pedido = $this->pedidos->buscarPorId(
            $pedidoId
        );

        if ($pedido === null) {
            throw new ApiException(
                404,
                'PEDIDO_NO_ENCONTRADO',
                'El pedido solicitado no existe'
            );
        }

        return $pedido;
    }

    private function resumenEstado(
        array $pedido
    ): array {
        return [
            'id' => $pedido['id'],
            'estado' => $pedido['estado'],
            'guia' => $pedido['guia']
        ];
    }
}