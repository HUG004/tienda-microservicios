<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Clients\HttpClient;
use App\Clients\ProductosClient;
use App\Clients\CarritoClient;
use App\Clients\InventarioClient;
use App\Clients\EnviosClient;
use App\Clients\PagosClient;
use App\Utils\ApiException;

$aprobadas = 0;
$fallidas = 0;

function probar(
    string $nombre,
    callable $operacion
): void {
    global $aprobadas, $fallidas;

    try {
        if ($operacion() !== true) {
            throw new RuntimeException(
                'Resultado inesperado'
            );
        }

        echo "[OK] {$nombre}\n";

        $aprobadas++;

    } catch (Throwable $exception) {
        echo "[ERROR] {$nombre}: "
            . $exception->getMessage()
            . PHP_EOL;

        $fallidas++;
    }
}

function probarError(
    string $nombre,
    int $status,
    string $codigo,
    callable $operacion
): void {
    probar(
        $nombre,
        function () use (
            $status,
            $codigo,
            $operacion
        ): bool {
            try {
                $operacion();

                return false;

            } catch (ApiException $exception) {
                return
                    $exception->getHttpStatus() === $status
                    && $exception->getErrorCode() === $codigo;
            }
        }
    );
}

$productos = new ProductosClient();
$carrito = new CarritoClient();
$inventario = new InventarioClient();
$envios = new EnviosClient();
$pagos = new PagosClient();

$http = new HttpClient('PRODUCTOS_API_URL');

echo "=====================================\n";
echo " PRUEBAS DE CLIENTES HTTP\n";
echo "=====================================\n";

// P01

probar(
    'Consulta de producto',
    fn() => $productos->obtener(1)['precio']
        === '899.00'
);

// P02

probarError(
    'Producto inexistente',
    404,
    'PRODUCTO_NO_ENCONTRADO',
    fn() => $productos->obtener(999)
);

// P03

probar(
    'Consulta de carrito',
    fn() => $carrito->obtener(1)['subtotal']
        === '1798.00'
);

// P04

probar(
    'Consulta de inventario',
    fn() => $inventario->consultar(1)['stock_disponible']
        === 20
);

// P05

probar(
    'Reserva de inventario',
    fn() => $inventario->reservar(
        1,
        [
            [
                'producto_id' => 1,
                'cantidad' => 2
            ]
        ]
    )['estado'] === 'pendiente'
);

// P06

probar(
    'Proteccion de reserva',
    fn() => $inventario->protegerReserva(1)['estado']
        === 'procesando_pago'
);

// P07

probar(
    'Confirmacion de inventario',
    fn() => $inventario->confirmar(1)['estado']
        === 'confirmada'
);

// P08

probar(
    'Liberacion de inventario',
    fn() => $inventario->liberar(1)['estado']
        === 'liberada'
);

// P09

probar(
    'Consulta de reserva',
    fn() => $inventario->consultarReserva(1)['pedido_id']
        === 1
);

// P10

probar(
    'Cotizacion de envio',
    fn() => $envios->cotizar('38000', 2)['costo_envio']
        === '120.00'
);

// P11

probar(
    'Generacion de guia',
    fn() => $envios->generar(
        1,
        '38000',
        'Direccion de prueba',
        2
    )['guia'] === 'ENV-00000001'
);

// P12

probar(
    'Rastreo de envio',
    fn() => $envios->rastrear(
        'ENV-00000001'
    )['estado'] === 'preparando'
);

// P13

probar(
    'Procesamiento de pago aprobado',
    fn() => $pagos->procesar(
        1,
        '1918.00',
        'tarjeta_simulada',
        'aprobar',
        'checkout-prueba-001'
    )['estado'] === 'aprobado'
);

// P14

probar(
    'Procesamiento de pago rechazado',
    fn() => $pagos->procesar(
        1,
        '1918.00',
        'tarjeta_simulada',
        'rechazar',
        'checkout-prueba-001'
    )['estado'] === 'rechazado'
);

// P15

probar(
    'Consulta de pago',
    fn() => $pagos->consultar(1)['referencia']
        === 'PAGO-00000001'
);

// P16

probar(
    'Vaciado de carrito',
    fn() => $carrito->vaciar(1)['productos']
        === []
);

// P17

probarError(
    'Manejo de error de microservicio',
    503,
    'SERVICIO_NO_DISPONIBLE',
    fn() => $http->request(
        'GET',
        '/prueba/error'
    )
);

// P18

probarError(
    'Manejo de respuesta JSON invalida',
    503,
    'RESPUESTA_API_INVALIDA',
    fn() => $http->request(
        'GET',
        '/prueba/json-invalido'
    )
);

// P19

probarError(
    'Rechazo de solicitud sin token interno',
    503,
    'ERROR_COMUNICACION_INTERNA',
    fn() => $http->data(
        'GET',
        '/api/carrito/1'
    )
);

// P20

probarError(
    'Rechazo de clave de idempotencia invalida',
    400,
    'CLAVE_IDEMPOTENCIA_INVALIDA',
    fn() => $pagos->procesar(
        1,
        '1918.00',
        'tarjeta_simulada',
        'aprobar',
        'clave invalida'
    )
);

echo "=====================================\n";
echo "RESULTADOS\n";
echo "Pruebas aprobadas: {$aprobadas}\n";
echo "Pruebas fallidas: {$fallidas}\n";
echo "=====================================\n";

exit($fallidas > 0 ? 1 : 0);