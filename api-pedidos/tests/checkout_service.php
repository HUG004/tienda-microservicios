<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Database;
use App\Services\PedidoService;
use App\Services\CheckoutService;
use App\Models\CheckoutOperacion;
use App\Utils\ApiException;

$aprobadas = 0;
$fallidas = 0;

function probar(
    string $nombre,
    callable $funcion
): void {
    global $aprobadas, $fallidas;

    try {
        if ($funcion() !== true) {
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
    callable $funcion
): void {
    probar(
        $nombre,
        function () use (
            $status,
            $funcion
        ): bool {
            try {
                $funcion();

                return false;

            } catch (ApiException $exception) {
                return $exception->getHttpStatus()
                    === $status;
            }
        }
    );
}

$pdo = Database::connect();

$pedidos = new PedidoService();
$checkout = new CheckoutService();
$operaciones = new CheckoutOperacion();

$cliente = [
    'id' => 1,
    'rol' => 'cliente'
];

$otroCliente = [
    'id' => 2,
    'rol' => 'cliente'
];

$direccion = 'PRUEBA-CHECKOUT-'
    . bin2hex(random_bytes(12));

$datosPedido = [
    'usuario_id' => 1,
    'direccion' => $direccion,
    'codigo_postal' => '38000'
];

$aprobar = [
    'metodo_pago' => 'tarjeta_simulada',
    'simulacion' => 'aprobar'
];

$rechazar = [
    'metodo_pago' => 'tarjeta_simulada',
    'simulacion' => 'rechazar'
];

$clave = 'checkout-' . bin2hex(random_bytes(16));

$claveRechazo = 'checkout-'
    . bin2hex(random_bytes(16));

echo "=====================================\n";
echo " PRUEBAS DE CHECKOUT SERVICE\n";
echo "=====================================\n";

try {
    $pedido = $pedidos->crear(
        $cliente,
        $datosPedido
    );

    $id = $pedido['id'];

    probarError(
        'Rechazo de pedido ajeno',
        403,
        fn() => $checkout->procesar(
            $id,
            $otroCliente,
            $aprobar,
            $clave
        )
    );

    probarError(
        'Rechazo de clave invalida',
        400,
        fn() => $checkout->procesar(
            $id,
            $cliente,
            $aprobar,
            'abc'
        )
    );

    probarError(
        'Rechazo de metodo de pago invalido',
        400,
        fn() => $checkout->procesar(
            $id,
            $cliente,
            [
                'metodo_pago' => 'invalido',
                'simulacion' => 'aprobar'
            ],
            $clave
        )
    );

    probarError(
        'Rechazo de simulacion invalida',
        400,
        fn() => $checkout->procesar(
            $id,
            $cliente,
            [
                'metodo_pago' => 'tarjeta_simulada',
                'simulacion' => 'invalida'
            ],
            $clave
        )
    );

    $resultado = $checkout->procesar(
        $id,
        $cliente,
        $aprobar,
        $clave
    );

    probar(
        'Checkout aprobado',
        fn() => $resultado['estado'] === 'pagado'
    );

    probar(
        'Monto correcto del checkout',
        fn() => $resultado['total'] === '1918.00'
    );

    probar(
        'Pago aprobado registrado',
        fn() => $resultado['pago']['estado']
            === 'aprobado'
    );

    probar(
        'Guia de envio generada',
        fn() => $resultado['envio']['guia']
            === sprintf('ENV-%08d', $id)
    );

    $operacion = $operaciones
        ->buscarPorPedido($id);

    probar(
        'Inventario confirmado',
        fn() => $operacion['inventario_confirmado']
            === true
    );

    probar(
        'Envio registrado',
        fn() => $operacion['envio_generado']
            === true
    );

    probar(
        'Carrito vaciado',
        fn() => $operacion['carrito_vaciado']
            === true
    );

    probar(
        'Operacion completada',
        fn() => $operacion['estado']
            === 'completado'
    );

    probar(
        'Fecha de finalizacion registrada',
        fn() => !empty($operacion['completado_en'])
    );

    probar(
        'Persistencia del pedido pagado',
        fn() => $pedidos->obtenerPorId(
            $id,
            $cliente
        )['estado'] === 'pagado'
    );

    $repetido = $checkout->procesar(
        $id,
        $cliente,
        $aprobar,
        $clave
    );

    probar(
        'Respuesta idempotente del checkout',
        fn() => $resultado === $repetido
    );

    probar(
        'Sin nuevos intentos al repetir checkout',
        fn() => $operaciones
            ->buscarPorPedido($id)['intentos']
                === $operacion['intentos']
    );

    probarError(
        'Rechazo de segunda clave',
        409,
        fn() => $checkout->procesar(
            $id,
            $cliente,
            $aprobar,
            'checkout-' . bin2hex(random_bytes(16))
        )
    );

    probarError(
        'Rechazo de solicitud modificada',
        409,
        fn() => $checkout->procesar(
            $id,
            $cliente,
            $rechazar,
            $clave
        )
    );

    // Segundo pedido: pago rechazado.

    $pedidoRechazado = $pedidos->crear(
        $cliente,
        $datosPedido
    );

    $idRechazo = $pedidoRechazado['id'];

    $resultadoRechazo = $checkout->procesar(
        $idRechazo,
        $cliente,
        $rechazar,
        $claveRechazo
    );

    probar(
        'Checkout rechazado',
        fn() => $resultadoRechazo['pago']['estado']
            === 'rechazado'
    );

    probar(
        'Pedido cancelado por rechazo',
        fn() => $resultadoRechazo['estado']
            === 'cancelado'
    );

    probar(
        'Sin guia para pedido rechazado',
        fn() => $resultadoRechazo['envio'] === null
    );

    $operacionRechazo = $operaciones
        ->buscarPorPedido($idRechazo);

    probar(
        'Inventario liberado',
        fn() => $operacionRechazo['inventario_liberado']
            === true
    );

    probar(
        'Inventario no confirmado tras rechazo',
        fn() => $operacionRechazo['inventario_confirmado']
            === false
    );

    probar(
        'Carrito no vaciado tras rechazo',
        fn() => $operacionRechazo['carrito_vaciado']
            === false
    );

    probar(
        'Operacion rechazada finalizada',
        fn() => $operacionRechazo['estado']
            === 'completado'
    );

    probar(
        'Rechazo idempotente',
        fn() => $checkout->procesar(
            $idRechazo,
            $cliente,
            $rechazar,
            $claveRechazo
        ) === $resultadoRechazo
    );

    probar(
        'Persistencia del pedido cancelado',
        fn() => $pedidos->obtenerPorId(
            $idRechazo,
            $cliente
        )['estado'] === 'cancelado'
    );

} catch (Throwable $exception) {
    echo "[ERROR GENERAL] "
        . $exception->getMessage()
        . PHP_EOL;

    if ($exception->getPrevious() !== null) {
        echo "[CAUSA] "
            . $exception->getPrevious()->getMessage()
            . PHP_EOL;
    }

    $fallidas++;

} finally {
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('
            DELETE FROM checkout_operaciones
            WHERE pedido_id IN (
                SELECT id FROM pedidos
                WHERE usuario_id = :usuario_id
                  AND direccion = :direccion
            )
        ');

        $stmt->execute([
            'usuario_id' => 1,
            'direccion' => $direccion
        ]);

        $stmt = $pdo->prepare('
            DELETE FROM pedidos
            WHERE usuario_id = :usuario_id
              AND direccion = :direccion
        ');

        $stmt->execute([
            'usuario_id' => 1,
            'direccion' => $direccion
        ]);

        $pdo->commit();

        echo "[INFO] Datos de prueba eliminados\n";

    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

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