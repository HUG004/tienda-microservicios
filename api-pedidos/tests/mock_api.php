<?php

declare(strict_types=1);

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

$path = parse_url(
    $_SERVER['REQUEST_URI'],
    PHP_URL_PATH
);

$body = json_decode(
    file_get_contents('php://input'),
    true
);

function responder(
    array $data,
    int $status = 200
): never {
    http_response_code($status);

    echo json_encode(
        [
            'mensaje' => 'Operacion simulada',
            'datos' => $data
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

function error(
    int $status,
    string $code
): never {
    http_response_code($status);

    echo json_encode([
        'error' => [
            'codigo' => $code,
            'mensaje' => 'Error simulado'
        ]
    ]);

    exit;
}

if ($path === '/health') {
    echo json_encode([
        'status' => 'ok',
        'servicio' => 'mock-api'
    ]);

    exit;
}

if (
    $method === 'GET'
    && $path === '/api/productos/1'
) {
    responder([
        'id' => 1,
        'nombre' => 'Teclado mecanico',
        'precio' => '899.00',
        'activo' => true
    ]);
}

if (
    $method === 'GET'
    && $path === '/api/productos/999'
) {
    error(404, 'PRODUCTO_NO_ENCONTRADO');
}

if (
    $method === 'GET'
    && $path === '/api/inventario/1'
) {
    responder([
        'producto_id' => 1,
        'stock_total' => 20,
        'stock_reservado' => 0,
        'stock_disponible' => 20,
        'disponible' => true
    ]);
}

if (
    $method === 'GET'
    && $path === '/api/envios/rastreo/ENV-00000001'
) {
    responder([
        'pedido_id' => 1,
        'guia' => 'ENV-00000001',
        'estado' => 'preparando'
    ]);
}

if ($path === '/prueba/error') {
    error(500, 'ERROR_SIMULADO');
}

if ($path === '/prueba/json-invalido') {
    echo 'Esto no es JSON';

    exit;
}

// Validar autenticacion interna.

$expected = getenv('INTERNAL_API_TOKEN');

$received =
    $_SERVER['HTTP_X_INTERNAL_TOKEN'] ?? '';

if (
    $expected === false
    || $expected === ''
    || !hash_equals($expected, $received)
) {
    error(401, 'TOKEN_INTERNO_INVALIDO');
}

switch ("{$method} {$path}") {

    case 'GET /api/carrito/1':

        responder([
            'usuario_id' => 1,
            'productos' => [
                [
                    'producto_id' => 1,
                    'nombre' => 'Teclado mecanico',
                    'precio_unitario' => '899.00',
                    'cantidad' => 2,
                    'subtotal' => '1798.00'
                ]
            ],
            'cantidad_total' => 2,
            'subtotal' => '1798.00'
        ]);

    case 'DELETE /api/carrito/1/vaciar':

        responder([
            'usuario_id' => 1,
            'productos' => [],
            'cantidad_total' => 0,
            'subtotal' => '0.00'
        ]);

    case 'POST /api/inventario/reservar':

    if (
        !is_int($body['pedido_id'] ?? null)
        || $body['pedido_id'] <= 0
        || ($body['productos'][0]['producto_id'] ?? null) !== 1
        || ($body['productos'][0]['cantidad'] ?? null) !== 2
    ) {
        error(400, 'SOLICITUD_INVALIDA');
    }

    responder([
        'pedido_id' => $body['pedido_id'],
        'estado' => 'pendiente'
    ], 201);

        if (
            !is_int($body['pedido_id'] ?? null)
            || $body['pedido_id'] <= 0
            || ($body['productos'][0]['producto_id'] ?? null) !== 1
            || ($body['productos'][0]['cantidad'] ?? null) !== 2
        ) {
            error(400, 'SOLICITUD_INVALIDA');
        }

        responder([
            'pedido_id' => $body['pedido_id'],
            'estado' => 'pendiente'
        ], 201);
        if (
            ($body['pedido_id'] ?? null) !== 1
            || ($body['productos'][0]['producto_id'] ?? null) !== 1
            || ($body['productos'][0]['cantidad'] ?? null) !== 2
        ) {
            error(400, 'SOLICITUD_INVALIDA');
        }

        responder([
            'pedido_id' => 1,
            'estado' => 'pendiente'
        ], 201);

    case 'POST /api/inventario/proteger-reserva':

        responder([
            'pedido_id' => $body['pedido_id'],
            'estado' => 'procesando_pago'
        ]);

    case 'POST /api/inventario/confirmar':

        responder([
            'pedido_id' => $body['pedido_id'],
            'estado' => 'confirmada'
        ]);

    case 'POST /api/inventario/liberar':

        responder([
            'pedido_id' => $body['pedido_id'],
            'estado' => 'liberada'
        ]);

    case 'GET /api/inventario/reservas/1':

        responder([
            'pedido_id' => 1,
            'estado' => 'pendiente',
            'productos' => [
                [
                    'producto_id' => 1,
                    'cantidad' => 2
                ]
            ]
        ]);

    case 'POST /api/envios/cotizar':

        if (
            ($body['codigo_postal'] ?? null) !== '38000'
            || ($body['cantidad'] ?? null) !== 2
        ) {
            error(400, 'SOLICITUD_INVALIDA');
        }

        responder([
            'codigo_postal' => '38000',
            'cantidad' => 2,
            'peso' => '2.000',
            'costo_envio' => '120.00',
            'dias_estimados' => 3,
            'moneda' => 'MXN'
        ]);

    case 'POST /api/envios/generar':

    if (
        !is_int($body['pedido_id'] ?? null)
        || $body['pedido_id'] <= 0
        || ($body['codigo_postal'] ?? null) !== '38000'
        || ($body['cantidad'] ?? null) !== 2
        || empty($body['direccion'])
    ) {
        error(400, 'SOLICITUD_INVALIDA');
    }

    $pedidoId = $body['pedido_id'];

    responder([
        'id' => $pedidoId,
        'pedido_id' => $pedidoId,
        'guia' => sprintf('ENV-%08d', $pedidoId),
        'estado' => 'preparando'
    ], 201);

    case 'POST /api/pagos/procesar':

    $clave = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';

    if (
        !preg_match('/^[a-zA-Z0-9_-]{16,100}$/D', $clave)
        || !is_int($body['pedido_id'] ?? null)
        || $body['pedido_id'] <= 0
        || ($body['monto'] ?? null) !== '1918.00'
        || !in_array(
            $body['metodo_pago'] ?? null,
            [
                'tarjeta_simulada',
                'transferencia_simulada'
            ],
            true
        )
        || !in_array(
            $body['simulacion'] ?? null,
            ['aprobar', 'rechazar'],
            true
        )
    ) {
        error(400, 'SOLICITUD_INVALIDA');
    }

    $pedidoId = $body['pedido_id'];

    responder([
        'id' => $pedidoId,
        'pedido_id' => $pedidoId,
        'monto' => '1918.00',
        'moneda' => 'MXN',
        'estado' =>
            $body['simulacion'] === 'aprobar'
                ? 'aprobado'
                : 'rechazado',
        'referencia' => sprintf(
            'PAGO-%08d',
            $pedidoId
        )
    ], 201);

    case 'GET /api/pagos/pedido/1':

        responder([
            'id' => 1,
            'pedido_id' => 1,
            'monto' => '1918.00',
            'estado' => 'aprobado',
            'referencia' => 'PAGO-00000001'
        ]);

    default:

        error(404, 'RUTA_NO_ENCONTRADA');
}