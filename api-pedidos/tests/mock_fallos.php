<?php

declare(strict_types=1);

$archivo = sys_get_temp_dir()
    . '/tienda-checkout-fallo.json';

$configuracion = is_file($archivo)
    ? json_decode(
        file_get_contents($archivo),
        true
    )
    : [];

$method = $_SERVER['REQUEST_METHOD'];

$path = parse_url(
    $_SERVER['REQUEST_URI'],
    PHP_URL_PATH
);

$ruta = "{$method} {$path}";

// Registro de llamadas para verificar
// que no se repitan operaciones completadas.

$archivoRegistro = sys_get_temp_dir()
    . '/tienda-checkout-llamadas.log';

file_put_contents(
    $archivoRegistro,
    $ruta . PHP_EOL,
    FILE_APPEND | LOCK_EX
);

// Simular un fallo en la ruta seleccionada.

if (
    ($configuracion['ruta'] ?? null) === $ruta
) {
    header('Content-Type: application/json');

    http_response_code(
        $configuracion['status'] ?? 503
    );

    echo json_encode([
        'error' => [
            'codigo' =>
                $configuracion['codigo']
                ?? 'FALLO_SIMULADO',
            'mensaje' =>
                'Fallo controlado para pruebas'
        ]
    ]);

    exit;
}

// Si no hay fallo, utilizar el simulador original.

require __DIR__ . '/mock_api.php';