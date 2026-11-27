<?php
declare(strict_types=1);

const SERVICIO = 'api-productos';

function responder(int $codigo, array $cuerpo): never
{
    http_response_code($codigo);
    header('Content-Type: application/json');
    echo json_encode($cuerpo);
    exit;
}

$ruta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$metodo = $_SERVER['REQUEST_METHOD'];

if ($metodo === 'GET' && $ruta === '/health') {
    responder(200, ['status' => 'ok', 'servicio' => SERVICIO]);
}

if ($metodo === 'GET' && $ruta === '/ready') {
    $host = getenv('DB_HOST') ?: 'postgres';
    $puerto = (int) (getenv('DB_PORT') ?: 5432);
    $con = @fsockopen($host, $puerto, $errno, $errstr, 2);
    if ($con === false) {
        responder(503, ['status' => 'not_ready', 'servicio' => SERVICIO]);
    }
    fclose($con);
    responder(200, ['status' => 'ready', 'servicio' => SERVICIO]);
}

responder(404, ['error' => [
    'codigo' => 'RUTA_NO_ENCONTRADA',
    'mensaje' => 'Ruta no encontrada',
]]);