<?php

declare(strict_types=1);

namespace App\Clients;

final class CarritoClient
{
    private HttpClient $http;

    public function __construct()
    {
        $this->http = new HttpClient(
            'CARRITO_API_URL'
        );
    }

    public function obtener(int $usuarioId): array
    {
        return $this->http->data(
            'GET',
            "/api/carrito/{$usuarioId}",
            null,
            true
        );
    }

    public function vaciar(int $usuarioId): array
    {
        return $this->http->data(
            'DELETE',
            "/api/carrito/{$usuarioId}/vaciar",
            null,
            true
        );
    }
}