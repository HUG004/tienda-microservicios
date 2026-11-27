<?php

declare(strict_types=1);

namespace App\Clients;

final class InventarioClient
{
    private HttpClient $http;

    public function __construct()
    {
        $this->http = new HttpClient(
            'INVENTARIO_API_URL'
        );
    }

    public function consultar(
        int $productoId
    ): array {
        return $this->http->data(
            'GET',
            "/api/inventario/{$productoId}"
        );
    }

    public function reservar(
        int $pedidoId,
        array $productos
    ): array {
        return $this->http->data(
            'POST',
            '/api/inventario/reservar',
            [
                'pedido_id' => $pedidoId,
                'productos' => $productos
            ],
            true
        );
    }

    public function protegerReserva(
        int $pedidoId
    ): array {
        return $this->http->data(
            'POST',
            '/api/inventario/proteger-reserva',
            [
                'pedido_id' => $pedidoId
            ],
            true
        );
    }

    public function confirmar(
        int $pedidoId
    ): array {
        return $this->http->data(
            'POST',
            '/api/inventario/confirmar',
            [
                'pedido_id' => $pedidoId
            ],
            true
        );
    }

    public function liberar(
        int $pedidoId
    ): array {
        return $this->http->data(
            'POST',
            '/api/inventario/liberar',
            [
                'pedido_id' => $pedidoId
            ],
            true
        );
    }

    public function consultarReserva(
        int $pedidoId
    ): array {
        return $this->http->data(
            'GET',
            "/api/inventario/reservas/{$pedidoId}",
            null,
            true
        );
    }
}