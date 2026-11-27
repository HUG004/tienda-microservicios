<?php

declare(strict_types=1);

namespace App\Clients;

final class ProductosClient
{
    private HttpClient $http;

    public function __construct()
    {
        $this->http = new HttpClient(
            'PRODUCTOS_API_URL'
        );
    }

    public function obtener(int $productoId): array
    {
        return $this->http->data(
            'GET',
            "/api/productos/{$productoId}"
        );
    }
}