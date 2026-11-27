<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Database;
use App\Services\PedidoService;
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
    callable $operacion
): void {
    probar(
        $nombre,
        function () use (
            $status,
            $operacion
        ): bool {
            try {
                $operacion();

                return false;

            } catch (ApiException $exception) {
                return $exception->getHttpStatus()
                    === $status;
            }
        }
    );
}

$pdo = Database::connect();
$servicio = new PedidoService();

$cliente = [
    'id' => 1,
    'rol' => 'cliente'
];

$otroCliente = [
    'id' => 2,
    'rol' => 'cliente'
];

$admin = [
    'id' => 3,
    'rol' => 'admin'
];

$direccionPrueba = 'PRUEBA-SERVICE-'
    . bin2hex(random_bytes(12));

$datos = [
    'usuario_id' => 1,
    'direccion' => $direccionPrueba,
    'codigo_postal' => '38000'
];

echo "=====================================\n";
echo " PRUEBAS DE PEDIDO SERVICE\n";
echo "=====================================\n";

try {
    // P01. Rechazar usuario invalido.

    probarError(
        'Rechazo de usuario invalido',
        400,
        fn() => $servicio->crear(
            $cliente,
            array_replace(
                $datos,
                ['usuario_id' => 0]
            )
        )
    );

    // P02. Rechazar pedido de otro usuario.

    probarError(
        'Rechazo de pedido para otro usuario',
        403,
        fn() => $servicio->crear(
            $otroCliente,
            $datos
        )
    );

    // P03. Rechazar direccion vacia.

    probarError(
        'Rechazo de direccion vacia',
        400,
        fn() => $servicio->crear(
            $cliente,
            array_replace(
                $datos,
                ['direccion' => '']
            )
        )
    );

    // P04. Rechazar codigo postal invalido.

    probarError(
        'Rechazo de codigo postal invalido',
        400,
        fn() => $servicio->crear(
            $cliente,
            array_replace(
                $datos,
                ['codigo_postal' => '123']
            )
        )
    );

    // P05. Rechazar creacion por administrador.

    probarError(
        'Rechazo de creacion por administrador',
        403,
        fn() => $servicio->crear(
            $admin,
            $datos
        )
    );

    // P06. Crear pedido con precios manipulados
    // en la solicitud.

    $pedido = $servicio->crear(
        $cliente,
        array_replace(
            $datos,
            [
                'subtotal' => '1.00',
                'total' => '1.00'
            ]
        )
    );

    $pedidoId = $pedido['id'];

    probar(
        'Creacion de pedido y reserva',
        fn() => $pedidoId > 0
            && $pedido['estado'] === 'pendiente'
    );

    // P07. Validar precio oficial.

    probar(
        'Uso del precio oficial del producto',
        fn() => $pedido['productos'][0]
            ['precio_unitario'] === '899.00'
    );

    // P08. Validar subtotal.

    probar(
        'Calculo correcto del subtotal',
        fn() => $pedido['subtotal'] === '1798.00'
    );

    // P09. Validar costo de envio.

    probar(
        'Cotizacion correcta del envio',
        fn() => $pedido['costo_envio'] === '120.00'
    );

    // P10. Validar total.

    probar(
        'Calculo correcto del total',
        fn() => $pedido['total'] === '1918.00'
    );

    // P11. Ignorar precios enviados por frontend.

    probar(
        'Proteccion contra manipulacion de precios',
        fn() => $pedido['total'] !== '1.00'
    );

    // P12. Consultar pedido propio.

    probar(
        'Consulta de pedido propio',
        fn() => $servicio->obtenerPorId(
            $pedidoId,
            $cliente
        )['id'] === $pedidoId
    );

    // P13. Consultar pedido ajeno.

    probarError(
        'Rechazo de consulta de pedido ajeno',
        403,
        fn() => $servicio->obtenerPorId(
            $pedidoId,
            $otroCliente
        )
    );

    // P14. Consulta administrativa.

    probar(
        'Consulta administrativa autorizada',
        fn() => $servicio->obtenerPorId(
            $pedidoId,
            $admin
        )['id'] === $pedidoId
    );

    // P15. Consulta interna.

    probar(
        'Consulta interna autorizada',
        fn() => $servicio->obtenerPorId(
            $pedidoId,
            [],
            true
        )['id'] === $pedidoId
    );

    // P16. Historial del cliente.

    probar(
        'Consulta de historial de pedidos',
        function () use (
            $servicio,
            $cliente,
            $pedidoId
        ): bool {
            $historial = $servicio
                ->obtenerPorUsuario(
                    1,
                    $cliente
                );

            return in_array(
                $pedidoId,
                array_column($historial, 'id'),
                true
            );
        }
    );

    // P17. Rechazar historial ajeno.

    probarError(
        'Rechazo de historial ajeno',
        403,
        fn() => $servicio->obtenerPorUsuario(
            1,
            $otroCliente
        )
    );

    // P18. Rechazar cambio de estado por cliente.

    probarError(
        'Rechazo de cambio de estado por cliente',
        403,
        fn() => $servicio->cambiarEstado(
            $pedidoId,
            'cancelado',
            $cliente
        )
    );

    // P19. Impedir marcar como pagado directamente.

    probarError(
        'Rechazo de pago sin checkout',
        409,
        fn() => $servicio->cambiarEstado(
            $pedidoId,
            'pagado',
            $admin
        )
    );

    // P20. Impedir envio de pedido pendiente.

    probarError(
        'Rechazo de envio sin pago',
        409,
        fn() => $servicio->cambiarEstado(
            $pedidoId,
            'enviado',
            $admin
        )
    );

    // P21. Cancelacion administrativa.

    probar(
        'Cancelacion administrativa',
        fn() => $servicio->cambiarEstado(
            $pedidoId,
            'cancelado',
            $admin
        )['estado'] === 'cancelado'
    );

    // P22. Repetir cancelacion.

    probar(
        'Cancelacion idempotente',
        fn() => $servicio->cambiarEstado(
            $pedidoId,
            'cancelado',
            $admin
        )['estado'] === 'cancelado'
    );

    // P23. Verificar estado persistido.

    probar(
        'Persistencia del estado cancelado',
        fn() => $servicio->obtenerPorId(
            $pedidoId,
            $cliente
        )['estado'] === 'cancelado'
    );

} catch (Throwable $exception) {
    echo "[ERROR GENERAL] "
        . $exception->getMessage()
        . PHP_EOL;

    $fallidas++;

} finally {
    try {
        // Eliminar exclusivamente los datos
        // identificados por esta prueba.

        $stmt = $pdo->prepare('
            DELETE FROM pedidos
            WHERE usuario_id = :usuario_id
              AND direccion = :direccion
        ');

        $stmt->execute([
            'usuario_id' => 1,
            'direccion' => $direccionPrueba
        ]);

        echo "[INFO] Datos de prueba eliminados\n";

    } catch (Throwable $exception) {
        echo "[ERROR] Limpieza de datos: "
            . $exception->getMessage()
            . PHP_EOL;

        $fallidas++;
    }
}

echo "=====================================\n";
echo "RESULTADOS\n";
echo "Pruebas aprobadas: {$aprobadas}\n";
echo "Pruebas fallidas: {$fallidas}\n";
echo "=====================================\n";

exit($fallidas > 0 ? 1 : 0);