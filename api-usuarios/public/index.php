<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Utils\Response;

ini_set('display_errors', '0');
ini_set('log_errors', '1');

date_default_timezone_set('UTC');

try {
    require dirname(__DIR__) . '/routes/api.php';

} catch (PDOException $e) {

    error_log(
        '[api-usuarios] Error PDO. SQLSTATE: '
        . $e->getCode()
    );

    if (
        str_starts_with(
            (string) $e->getCode(),
            '08'
        )
    ) {
        Response::error(
            'SERVICIO_NO_DISPONIBLE',
            'La base de datos no está disponible',
            503
        );
    }

    Response::error(
        'ERROR_BASE_DATOS',
        'No fue posible completar la operación',
        500
    );

} catch (Throwable $e) {

    error_log(
        '[api-usuarios] Error: '
        . get_class($e)
    );

    Response::error(
        'ERROR_INTERNO',
        'Ocurrió un error interno en el servidor',
        500
    );
}