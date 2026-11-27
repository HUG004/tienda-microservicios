<?php

declare(strict_types=1);

namespace App\Clients;

final class PagosClient
{
    private HttpClient $http;

    public function __construct()
    {
        $this->http = new HttpClient(
            'PAGOS_API_URL'
        );
    }

    public function procesar(
        int $pedidoId,
        string $monto,
        string $metodoPago,
        string $simulacion,
        string $idempotencyKey
    ): array {
        return $this->http->data(
            'POST',
            '/api/pagos/procesar',
            [
                'pedido_id' => $pedidoId,
                'monto' => $monto,
                'metodo_pago' => $metodoPago,
                'simulacion' => $simulacion
            ],
            true,
            $idempotencyKey
        );
    }

    public function consultar(
        int $pedidoId
    ): array {
        return $this->http->data(
            'GET',
            "/api/pagos/pedido/{$pedidoId}",
            null,
            true
        );
    }
}