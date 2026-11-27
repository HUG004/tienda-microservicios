<?php

declare(strict_types=1);

use App\Controllers\PedidoController;
use App\Config\Database;
use App\Utils\Response;
use App\Utils\ApiException;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$path = parse_url(
    $_SERVER['REQUEST_URI'] ?? '/',
    PHP_URL_PATH
);

if (!is_string($path)) {
    throw new ApiException(
        400,
        'RUTA_INVALIDA',
        'La ruta solicitada es invalida'
    );
}

$path = rtrim($path, '/');

if ($path === '') {
    $path = '/';
}

// Liveness probe.

if ($method === 'GET' && $path === '/health') {
    Response::json([
        'status' => 'ok',
        'servicio' => 'api-pedidos'
    ]);
}

// Readiness probe.

if ($method === 'GET' && $path === '/ready') {
    try {
        $pdo = Database::connect();

        $pdo->query('SELECT 1');

        $pdo->query(
            'SELECT 1 FROM pedidos LIMIT 1'
        );

        $pdo->query(
            'SELECT 1 FROM pedido_detalles LIMIT 1'
        );

        $pdo->query(
            'SELECT 1 FROM checkout_operaciones LIMIT 1'
        );

    } catch (Throwable $exception) {
        Response::json([
            'status' => 'not_ready',
            'servicio' => 'api-pedidos'
        ], 503);
    }

    Response::json([
        'status' => 'ready',
        'servicio' => 'api-pedidos'
    ]);
}

$controller = new PedidoController();

// Validar identificadores positivos.

$validarId = static function (
    string $valor
): int {
    $id = filter_var(
        $valor,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1
            ]
        ]
    );

    if ($id === false) {
        throw new ApiException(
            400,
            'ID_INVALIDO',
            'El identificador es invalido'
        );
    }

    return $id;
};

// Crear pedido.

if ($path === '/api/pedidos') {
    if ($method !== 'POST') {
        header('Allow: POST');

        Response::error(
            'METODO_NO_PERMITIDO',
            'Metodo HTTP no permitido',
            405
        );
    }

    $controller->crear();
}

// Historial de pedidos.

if (preg_match(
    '#^/api/pedidos/usuario/([0-9]+)$#D',
    $path,
    $matches
)) {
    if ($method !== 'GET') {
        header('Allow: GET');

        Response::error(
            'METODO_NO_PERMITIDO',
            'Metodo HTTP no permitido',
            405
        );
    }

    $controller->obtenerPorUsuario(
        $validarId($matches[1])
    );
}

// Procesar checkout.

if (preg_match(
    '#^/api/pedidos/([0-9]+)/checkout$#D',
    $path,
    $matches
)) {
    if ($method !== 'POST') {
        header('Allow: POST');

        Response::error(
            'METODO_NO_PERMITIDO',
            'Metodo HTTP no permitido',
            405
        );
    }

    $controller->checkout(
        $validarId($matches[1])
    );
}

// Cambiar estado.

if (preg_match(
    '#^/api/pedidos/([0-9]+)/estado$#D',
    $path,
    $matches
)) {
    if ($method !== 'PATCH') {
        header('Allow: PATCH');

        Response::error(
            'METODO_NO_PERMITIDO',
            'Metodo HTTP no permitido',
            405
        );
    }

    $controller->cambiarEstado(
        $validarId($matches[1])
    );
}

// Consultar pedido por ID.

if (preg_match(
    '#^/api/pedidos/([0-9]+)$#D',
    $path,
    $matches
)) {
    if ($method !== 'GET') {
        header('Allow: GET');

        Response::error(
            'METODO_NO_PERMITIDO',
            'Metodo HTTP no permitido',
            405
        );
    }

    $controller->obtenerPorId(
        $validarId($matches[1])
    );
}

// Ruta inexistente.

Response::error(
    'RUTA_NO_ENCONTRADA',
    'El endpoint solicitado no existe',
    404
);