<?php

declare(strict_types=1);

namespace App\Clients;

final class EnviosClient
{
    private HttpClient $http;

    public function __construct()
    {
        $this->http = new HttpClient(
            'ENVIOS_API_URL'
        );
    }

    public function cotizar(
        string $codigoPostal,
        int $cantidad,
        ?string $peso = null
    ): array {
        $body = [
            'codigo_postal' => $codigoPostal,
            'cantidad' => $cantidad
        ];

        if ($peso !== null) {
            $body['peso'] = $peso;
        }

        return $this->http->data(
            'POST',
            '/api/envios/cotizar',
            $body,
            true
        );
    }

    public function generar(
        int $pedidoId,
        string $codigoPostal,
        string $direccion,
        int $cantidad
    ): array {
        return $this->http->data(
            'POST',
            '/api/envios/generar',
            [
                'pedido_id' => $pedidoId,
                'codigo_postal' => $codigoPostal,
                'direccion' => $direccion,
                'cantidad' => $cantidad
            ],
            true
        );
    }

    public function rastrear(
        string $guia
    ): array {
        $guia = rawurlencode($guia);

        return $this->http->data(
            'GET',
            "/api/envios/rastreo/{$guia}"
        );
    }
}