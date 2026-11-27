<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PedidoService;
use App\Services\CheckoutService;
use App\Middleware\AuthMiddleware;
use App\Middleware\InternalAuthMiddleware;
use App\Utils\Response;
use App\Utils\ApiException;

final class PedidoController
{
    public function crear(): never
    {
        $usuario = AuthMiddleware::authenticate();

        $datos = Response::readJson();

        $pedido = (new PedidoService())->crear(
            $usuario,
            $datos
        );

        Response::success(
            'Pedido creado correctamente',
            $pedido,
            201
        );
    }

    public function obtenerPorId(
        int $pedidoId
    ): never {
        $interno = false;
        $usuario = [];

        if (InternalAuthMiddleware::hasCredentials()) {
            InternalAuthMiddleware::authenticate();

            $interno = true;

        } else {
            $usuario = AuthMiddleware::authenticate();
        }

        $pedido = (new PedidoService())->obtenerPorId(
            $pedidoId,
            $usuario,
            $interno
        );

        Response::success(
            'Pedido obtenido correctamente',
            $pedido
        );
    }

    public function obtenerPorUsuario(
        int $usuarioId
    ): never {
        $usuario = AuthMiddleware::authenticate();

        $pedidos = (new PedidoService())
            ->obtenerPorUsuario(
                $usuarioId,
                $usuario
            );

        Response::success(
            'Historial obtenido correctamente',
            $pedidos
        );
    }

    public function cambiarEstado(
        int $pedidoId
    ): never {
        $interno = false;
        $usuario = [];

        if (InternalAuthMiddleware::hasCredentials()) {
            InternalAuthMiddleware::authenticate();

            $interno = true;

        } else {
            $usuario = AuthMiddleware::authenticate();

            AuthMiddleware::requireRole(
                $usuario,
                'admin'
            );
        }

        $datos = Response::readJson();

        $estado = $datos['estado'] ?? null;

        if (
            !is_string($estado)
            || trim($estado) === ''
        ) {
            throw new ApiException(
                400,
                'ESTADO_INVALIDO',
                'El estado es obligatorio'
            );
        }

        $pedido = (new PedidoService())
            ->cambiarEstado(
                $pedidoId,
                $estado,
                $usuario,
                $interno
            );

        Response::success(
            'Estado actualizado correctamente',
            $pedido
        );
    }

    public function checkout(
        int $pedidoId
    ): never {
        $usuario = AuthMiddleware::authenticate();

        AuthMiddleware::requireRole(
            $usuario,
            'cliente'
        );

        $claveIdempotencia =
            $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';

        if (
            !is_string($claveIdempotencia)
            || trim($claveIdempotencia) === ''
        ) {
            throw new ApiException(
                400,
                'CLAVE_IDEMPOTENCIA_REQUERIDA',
                'El encabezado Idempotency-Key es obligatorio'
            );
        }

        $datos = Response::readJson();

        $resultado = (new CheckoutService())
            ->procesar(
                $pedidoId,
                $usuario,
                $datos,
                $claveIdempotencia
            );

        $mensaje =
            $resultado['pago']['estado'] === 'aprobado'
                ? 'Compra completada correctamente'
                : 'Pago rechazado y pedido cancelado';

        Response::success(
            $mensaje,
            $resultado
        );
    }
}