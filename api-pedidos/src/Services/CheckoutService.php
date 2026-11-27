<?php

declare(strict_types=1);

namespace App\Services;

use App\Clients\InventarioClient;
use App\Clients\PagosClient;
use App\Clients\EnviosClient;
use App\Clients\CarritoClient;
use App\Models\Pedido;
use App\Models\CheckoutOperacion;
use App\Middleware\AuthMiddleware;
use App\Utils\ApiException;
use App\Utils\Money;
use Throwable;

final class CheckoutService
{
    private Pedido $pedidos;
    private CheckoutOperacion $operaciones;

    public function __construct()
    {
        $this->pedidos = new Pedido();
        $this->operaciones = new CheckoutOperacion();
    }

    public function procesar(
        int $pedidoId,
        array $usuario,
        array $datos,
        string $claveIdempotencia
    ): array {
        AuthMiddleware::requireRole(
            $usuario,
            'cliente'
        );

        if ($pedidoId <= 0) {
            throw new ApiException(
                400,
                'PEDIDO_INVALIDO',
                'El identificador del pedido es invalido'
            );
        }

        if (!preg_match(
            '/^[a-zA-Z0-9_-]{16,100}$/D',
            $claveIdempotencia
        )) {
            throw new ApiException(
                400,
                'CLAVE_IDEMPOTENCIA_INVALIDA',
                'La clave de idempotencia es invalida'
            );
        }

        $metodoPago = $datos['metodo_pago'] ?? null;
        $simulacion = $datos['simulacion'] ?? null;

        if (!in_array(
            $metodoPago,
            [
                'tarjeta_simulada',
                'transferencia_simulada'
            ],
            true
        )) {
            throw new ApiException(
                400,
                'METODO_PAGO_INVALIDO',
                'El metodo de pago no es valido'
            );
        }

        if (!in_array(
            $simulacion,
            ['aprobar', 'rechazar'],
            true
        )) {
            throw new ApiException(
                400,
                'SIMULACION_INVALIDA',
                'La simulacion debe ser aprobar o rechazar'
            );
        }

        $pedido = $this->buscarPedido($pedidoId);

        AuthMiddleware::requireOwner(
            $usuario,
            $pedido['usuario_id']
        );

        // Impedir dos checkouts simultaneos.

        if (!$this->operaciones->adquirirBloqueo(
            $pedidoId
        )) {
            throw new ApiException(
                409,
                'CHECKOUT_EN_PROCESO',
                'El pedido esta siendo procesado'
            );
        }

        try {
            $pedido = $this->buscarPedido($pedidoId);

            $existente = $this->operaciones
                ->buscarPorPedido($pedidoId);

            if (
                $existente === null
                && $pedido['estado'] !== 'pendiente'
            ) {
                throw new ApiException(
                    409,
                    'PEDIDO_NO_PROCESABLE',
                    'El pedido no esta pendiente de pago'
                );
            }

            // Identificar de manera determinista
            // el contenido de la solicitud.

            $solicitudHash = hash(
                'sha256',
                json_encode(
                    [
                        'pedido_id' => $pedidoId,
                        'usuario_id' => $usuario['id'],
                        'metodo_pago' => $metodoPago,
                        'simulacion' => $simulacion
                    ],
                    JSON_THROW_ON_ERROR
                )
            );

            $operacion = $this->operaciones
                ->crearORecuperar(
                    $pedidoId,
                    $claveIdempotencia,
                    $solicitudHash
                );

            // Si ya termino, devolver el resultado
            // sin volver a llamar a los microservicios.

            if ($operacion['estado'] === 'completado') {
                return $this->construirResultado(
                    $pedido,
                    $operacion
                );
            }

            $this->operaciones->registrarIntento(
                $pedidoId
            );

            try {
                // ETAPA 1: Proteger la reserva.

                if ($operacion['estado'] === 'iniciado') {
                    $reserva = (new InventarioClient())
                        ->protegerReserva($pedidoId);

                    $this->validarRespuesta(
                        $reserva,
                        $pedidoId,
                        ['procesando_pago']
                    );

                    $operacion = $this->operaciones
                        ->actualizar(
                            $pedidoId,
                            [
                                'estado' => 'pago_en_proceso'
                            ]
                        );
                }

                // ETAPA 2: Procesar pago.
                // Reutilizar siempre la misma clave.

                if ($operacion['pago_estado'] === null) {
                    if (
                        $operacion['estado']
                        !== 'pago_en_proceso'
                    ) {
                        throw new ApiException(
                            503,
                            'CHECKOUT_INCONSISTENTE',
                            'No se puede verificar el progreso'
                        );
                    }

                    $pago = (new PagosClient())
                        ->procesar(
                            $pedidoId,
                            $pedido['total'],
                            $metodoPago,
                            $simulacion,
                            $claveIdempotencia
                        );

                    $this->validarRespuesta(
                        $pago,
                        $pedidoId,
                        ['aprobado', 'rechazado']
                    );

                    if (
                        !is_int($pago['id'] ?? null)
                        || $pago['id'] <= 0
                        || !is_string($pago['monto'] ?? null)
                        || !Money::equals(
                            $pago['monto'],
                            $pedido['total']
                        )
                        || ($pago['moneda'] ?? null) !== 'MXN'
                        || !is_string(
                            $pago['referencia'] ?? null
                        )
                        || trim($pago['referencia']) === ''
                        || mb_strlen($pago['referencia']) > 100
                    ) {
                        throw new ApiException(
                            503,
                            'RESPUESTA_PAGO_INVALIDA',
                            'No se pudo verificar el pago'
                        );
                    }

                    $operacion = $this->operaciones
                        ->registrarPago(
                            $pedidoId,
                            $pago
                        );
                }

                // ETAPA 3: Continuar segun el pago.

                if (
                    $operacion['pago_estado']
                    === 'rechazado'
                ) {
                    return $this->procesarRechazo(
                        $pedido,
                        $operacion
                    );
                }

                if (
                    $operacion['pago_estado']
                    === 'aprobado'
                ) {
                    return $this->procesarAprobacion(
                        $pedido,
                        $operacion
                    );
                }

                throw new ApiException(
                    503,
                    'ESTADO_PAGO_DESCONOCIDO',
                    'No se pudo determinar el estado del pago'
                );

            } catch (Throwable $exception) {
                // Conservar el progreso.
                // Un error de red no equivale a un rechazo.

                $this->operaciones->registrarError(
                    $pedidoId,
                    $exception instanceof ApiException
                        ? $exception->getErrorCode()
                        : 'ERROR_INTERNO_CHECKOUT'
                );

                // Si la reserva fue rechazada antes
                // de solicitar el pago, informar el conflicto.

                if (
                    $exception instanceof ApiException
                    && $operacion['estado'] === 'iniciado'
                    && in_array(
                        $exception->getHttpStatus(),
                        [404, 409],
                        true
                    )
                ) {
                    throw $exception;
                }

                throw new ApiException(
                    503,
                    'CHECKOUT_PENDIENTE',
                    'Reintenta utilizando la misma clave de idempotencia',
                    $exception
                );
            }

        } finally {
            $this->operaciones->liberarBloqueo(
                $pedidoId
            );
        }
    }

    private function procesarAprobacion(
        array $pedido,
        array $operacion
    ): array {
        $pedidoId = $pedido['id'];

        // ETAPA 4: Confirmar inventario.

        if (!$operacion['inventario_confirmado']) {
            $reserva = (new InventarioClient())
                ->confirmar($pedidoId);

            $this->validarRespuesta(
                $reserva,
                $pedidoId,
                ['confirmada']
            );

            $operacion = $this->operaciones
                ->actualizar(
                    $pedidoId,
                    [
                        'estado' => 'inventario_confirmado',
                        'inventario_confirmado' => true
                    ]
                );
        }

        // ETAPA 5: Marcar pedido como pagado.

        $pedido = $this->buscarPedido($pedidoId);

        if ($pedido['estado'] === 'pendiente') {
            $actualizado = $this->pedidos
                ->actualizarEstado(
                    $pedidoId,
                    'pendiente',
                    'pagado'
                );

            if (!$actualizado) {
                throw new ApiException(
                    503,
                    'PEDIDO_NO_ACTUALIZADO',
                    'No se pudo actualizar el pedido'
                );
            }

            $pedido = $this->buscarPedido($pedidoId);
        }

        if (!in_array(
            $pedido['estado'],
            ['pagado', 'enviado'],
            true
        )) {
            throw new ApiException(
                503,
                'ESTADO_PEDIDO_INCONSISTENTE',
                'El estado del pedido no es valido'
            );
        }

        if (
            $operacion['estado']
            === 'inventario_confirmado'
        ) {
            $operacion = $this->operaciones
                ->actualizar(
                    $pedidoId,
                    ['estado' => 'pedido_pagado']
                );
        }

        // ETAPA 6: Generar guia de envio.

        if (!$operacion['envio_generado']) {
            $envio = (new EnviosClient())
                ->generar(
                    $pedidoId,
                    $pedido['codigo_postal'],
                    $pedido['direccion'],
                    $this->pedidos->obtenerCantidadTotal(
                        $pedidoId
                    )
                );

            $this->validarRespuesta(
                $envio,
                $pedidoId,
                [
                    'preparando',
                    'en_transito',
                    'entregado'
                ]
            );

            $guia = $envio['guia'] ?? null;

            if (
                !is_string($guia)
                || trim($guia) === ''
                || mb_strlen($guia) > 50
            ) {
                throw new ApiException(
                    503,
                    'GUIA_INVALIDA',
                    'No se pudo verificar la guia'
                );
            }

            if (!$this->pedidos->guardarGuia(
                $pedidoId,
                $guia
            )) {
                throw new ApiException(
                    503,
                    'GUIA_NO_GUARDADA',
                    'No se pudo almacenar la guia'
                );
            }

            $operacion = $this->operaciones
                ->actualizar(
                    $pedidoId,
                    [
                        'estado' => 'envio_generado',
                        'envio_generado' => true
                    ]
                );
        }

        // ETAPA 7: Vaciar carrito.

        if (!$operacion['carrito_vaciado']) {
            $carrito = (new CarritoClient())
                ->vaciar($pedido['usuario_id']);

            if (
                ($carrito['usuario_id'] ?? null)
                    !== $pedido['usuario_id']
                || ($carrito['productos'] ?? null) !== []
            ) {
                throw new ApiException(
                    503,
                    'CARRITO_NO_VERIFICADO',
                    'No se pudo verificar el vaciado'
                );
            }

            $operacion = $this->operaciones
                ->actualizar(
                    $pedidoId,
                    [
                        'estado' => 'carrito_vaciado',
                        'carrito_vaciado' => true
                    ]
                );
        }

        // ETAPA 8: Finalizar operacion.

        $operacion = $this->operaciones
            ->completar($pedidoId);

        return $this->construirResultado(
            $this->buscarPedido($pedidoId),
            $operacion
        );
    }

    private function procesarRechazo(
        array $pedido,
        array $operacion
    ): array {
        $pedidoId = $pedido['id'];

        // Compensacion: liberar la reserva.

        if (!$operacion['inventario_liberado']) {
            $reserva = (new InventarioClient())
                ->liberar($pedidoId);

            $this->validarRespuesta(
                $reserva,
                $pedidoId,
                ['liberada', 'expirada']
            );

            $operacion = $this->operaciones
                ->actualizar(
                    $pedidoId,
                    [
                        'inventario_liberado' => true
                    ]
                );
        }

        // Cancelar el pedido rechazado.

        $pedido = $this->buscarPedido($pedidoId);

        if ($pedido['estado'] === 'pendiente') {
            if (!$this->pedidos->actualizarEstado(
                $pedidoId,
                'pendiente',
                'cancelado'
            )) {
                throw new ApiException(
                    503,
                    'PEDIDO_NO_CANCELADO',
                    'No se pudo cancelar el pedido'
                );
            }
        }

        $pedido = $this->buscarPedido($pedidoId);

        if ($pedido['estado'] !== 'cancelado') {
            throw new ApiException(
                503,
                'ESTADO_PEDIDO_INCONSISTENTE',
                'No se pudo verificar la cancelacion'
            );
        }

        // El carrito se conserva para otra compra.

        $operacion = $this->operaciones
            ->completar($pedidoId);

        return $this->construirResultado(
            $pedido,
            $operacion
        );
    }

    private function validarRespuesta(
        array $respuesta,
        int $pedidoId,
        array $estados
    ): void {
        if (
            ($respuesta['pedido_id'] ?? null)
                !== $pedidoId
            || !in_array(
                $respuesta['estado'] ?? null,
                $estados,
                true
            )
        ) {
            throw new ApiException(
                503,
                'RESPUESTA_MICROSERVICIO_INVALIDA',
                'No se pudo verificar la operacion remota'
            );
        }
    }

    private function buscarPedido(
        int $pedidoId
    ): array {
        $pedido = $this->pedidos
            ->buscarPorId($pedidoId);

        if ($pedido === null) {
            throw new ApiException(
                404,
                'PEDIDO_NO_ENCONTRADO',
                'El pedido no existe'
            );
        }

        return $pedido;
    }

    private function construirResultado(
        array $pedido,
        array $operacion
    ): array {
        $aprobado =
            $operacion['pago_estado'] === 'aprobado';

        return [
            'pedido_id' => $pedido['id'],
            'estado' => $aprobado
                ? 'pagado'
                : 'cancelado',
            'total' => $pedido['total'],
            'pago' => [
                'estado' => $operacion['pago_estado'],
                'referencia' =>
                    $operacion['pago_referencia']
            ],
            'envio' => $aprobado
                ? [
                    'guia' => $pedido['guia'],
                    'estado' => 'preparando'
                ]
                : null
        ];
    }
}