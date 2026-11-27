<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Utils\Response;
use App\Utils\ApiException;

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');

header('X-Content-Type-Options: nosniff');

header('Cache-Control: no-store');

try {
    require dirname(__DIR__) . '/routes/api.php';

} catch (ApiException $exception) {
    Response::fromException($exception);

} catch (Throwable $exception) {
    error_log(
        '[api-pedidos] Error interno: '
        . get_class($exception)
    );

    Response::error(
        'ERROR_INTERNO',
        'Ocurrio un error interno en el servidor',
        500
    );
}