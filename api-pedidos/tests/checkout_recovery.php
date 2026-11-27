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

$archivoFallo = sys_get_temp_dir()
    . '/tienda-checkout-fallo.json';

$archivoRegistro = sys_get_temp_dir()
    . '/tienda-checkout-llamadas.log';

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

function activarFallo(
    string $ruta,
    int $status = 503,
    string $codigo = 'FALLO_SIMULADO'
): void {
    global $archivoFallo;

    file_put_contents(
        $archivoFallo,
        json_encode([
            'ruta' => $ruta,
            'status' => $status,
            'codigo' => $codigo
        ]),
        LOCK_EX
    );
}

function desactivarFallo(): void
{
    global $archivoFallo;

    if (is_file($archivoFallo)) {
        unlink($archivoFallo);
    }
}

function limpiarRegistro(): void
{
    global $archivoRegistro;

    file_put_contents(
        $archivoRegistro,
        '',
        LOCK_EX
    );
}

function contarLlamadas(
    string $ruta
): int {
    global $archivoRegistro;

    if (!is_file($archivoRegistro)) {
        return 0;
    }

    $lineas = file(
        $archivoRegistro,
        FILE_IGNORE_NEW_LINES
        | FILE_SKIP_EMPTY_LINES
    );

    return count(
        array_filter(
            $lineas,
            fn($linea) => $linea === $ruta
        )
    );
}

$pdo = Database::connect();

$pedidos = new PedidoService();
$operaciones = new CheckoutOperacion();

$cliente = [
    'id' => 1,
    'rol' => 'cliente'
];

$direccion = 'PRUEBA-RECOVERY-'
    . bin2hex(random_bytes(12));

$datosPedido = [
    'usuario_id' => 1,
    'direccion' => $direccion,
    'codigo_postal' => '38000'
];

$datosCheckout = [
    'metodo_pago' => 'tarjeta_simulada',
    'simulacion' => 'aprobar'
];

desactivarFallo();
limpiarRegistro();

echo "=====================================\n";
echo " PRUEBAS DE RECUPERACION CHECKOUT\n";
echo "=====================================\n";

try {
    // ESCENARIO 1: Reserva expirada.

    $pedido = $pedidos->crear(
        $cliente,
        $datosPedido
    );

    $id = $pedido['id'];

    $clave = 'checkout-'
        . bin2hex(random_bytes(16));

    limpiarRegistro();

    activarFallo(
        'POST /api/inventario/proteger-reserva',
        409,
        'RESERVA_EXPIRADA'
    );

    probarError(
        'Rechazo de reserva expirada',
        409,
        fn() => (new CheckoutService())->procesar(
            $id,
            $cliente,
            $datosCheckout,
            $clave
        )
    );

    probar(
        'No solicitar pago con reserva expirada',
        fn() => contarLlamadas(
            'POST /api/pagos/procesar'
        ) === 0
    );

    probar(
        'Reserva expirada sin pago registrado',
        fn() => $operaciones
            ->buscarPorPedido($id)['pago_estado']
                === null
    );

    desactivarFallo();

    // PRUEBAS ADICIONALES:
    // Cancelacion segura antes de solicitar el pago.

    $admin = [
        'id' => 3,
        'rol' => 'admin'
    ];

    probar(
        'Cancelar pedido con reserva expirada',
        fn() => $pedidos->cambiarEstado(
            $id,
            'cancelado',
            $admin
        )['estado'] === 'cancelado'
    );

    probar(
        'Verificar persistencia de la cancelacion',
        fn() => $pedidos->obtenerPorId(
            $id,
            $cliente
        )['estado'] === 'cancelado'
    );

    probar(
        'Repetir cancelacion sin errores',
        fn() => $pedidos->cambiarEstado(
            $id,
            'cancelado',
            $admin
        )['estado'] === 'cancelado'
    );

    limpiarRegistro();

    probarError(
        'Impedir checkout de pedido cancelado',
        409,
        fn() => (new CheckoutService())->procesar(
            $id,
            $cliente,
            $datosCheckout,
            $clave
        )
    );

    probar(
        'No proteger reserva de pedido cancelado',
        fn() => contarLlamadas(
            'POST /api/inventario/proteger-reserva'
        ) === 0
    );

    probar(
        'No solicitar pago de pedido cancelado',
        fn() => contarLlamadas(
            'POST /api/pagos/procesar'
        ) === 0
    );

    // ESCENARIO 2: API Pagos no disponible.

    $pedido = $pedidos->crear(
        $cliente,
        $datosPedido
    );

    $id = $pedido['id'];

    $clave = 'checkout-'
        . bin2hex(random_bytes(16));

    limpiarRegistro();

    activarFallo(
        'POST /api/pagos/procesar'
    );

    probarError(
        'Fallo temporal de API Pagos',
        503,
        fn() => (new CheckoutService())->procesar(
            $id,
            $cliente,
            $datosCheckout,
            $clave
        )
    );

    probar(
        'Persistencia de pago en proceso',
        fn() => $operaciones
            ->buscarPorPedido($id)['estado']
                === 'pago_en_proceso'
    );

    probar(
        'Fallo de red no equivale a rechazo',
        fn() => $operaciones
            ->buscarPorPedido($id)['pago_estado']
                === null
    );

    probar(
        'Pedido pendiente tras fallo de pagos',
        fn() => $pedidos->obtenerPorId(
            $id,
            $cliente
        )['estado'] === 'pendiente'
    );

    probarError(
        'Impedir cancelacion con pago en proceso',
        409,
        fn() => $pedidos->cambiarEstado(
            $id,
            'cancelado',
            $admin
        )
    );

    probar(
        'No liberar inventario con pago desconocido',
        fn() => contarLlamadas(
            'POST /api/inventario/liberar'
        ) === 0
    );

    probar(
        'Conservar pedido pendiente tras cancelar indebidamente',
        fn() => $pedidos->obtenerPorId(
            $id,
            $cliente
        )['estado'] === 'pendiente'
    );

    desactivarFallo();

    limpiarRegistro();

    $resultado = (new CheckoutService())->procesar(
        $id,
        $cliente,
        $datosCheckout,
        $clave
    );

    probar(
        'Recuperacion tras fallo de pagos',
        fn() => $resultado['estado'] === 'pagado'
    );

    probar(
        'No repetir proteccion de reserva',
        fn() => contarLlamadas(
            'POST /api/inventario/proteger-reserva'
        ) === 0
    );

    probar(
        'Reintento de solicitud de pago',
        fn() => contarLlamadas(
            'POST /api/pagos/procesar'
        ) === 1
    );

    // ESCENARIOS 3, 4 y 5:
    // Fallos posteriores a la aprobacion del pago.

    $escenarios = [
        [
            'nombre' => 'Inventario',
            'ruta' =>
                'POST /api/inventario/confirmar',
            'estado' => 'pago_aprobado',
            'pedido' => 'pendiente'
        ],
        [
            'nombre' => 'Envios',
            'ruta' =>
                'POST /api/envios/generar',
            'estado' => 'pedido_pagado',
            'pedido' => 'pagado'
        ],
        [
            'nombre' => 'Carrito',
            'ruta' =>
                'DELETE /api/carrito/1/vaciar',
            'estado' => 'envio_generado',
            'pedido' => 'pagado'
        ]
    ];

    foreach ($escenarios as $escenario) {
        $nombre = $escenario['nombre'];

        $pedido = $pedidos->crear(
            $cliente,
            $datosPedido
        );

        $id = $pedido['id'];

        $clave = 'checkout-'
            . bin2hex(random_bytes(16));

        limpiarRegistro();

        activarFallo(
            $escenario['ruta']
        );

        probarError(
            "Fallo controlado de {$nombre}",
            503,
            fn() => (new CheckoutService())->procesar(
                $id,
                $cliente,
                $datosCheckout,
                $clave
            )
        );

        $operacion = $operaciones
            ->buscarPorPedido($id);

        probar(
            "Pago conservado tras fallo de {$nombre}",
            fn() => $operacion['pago_estado']
                === 'aprobado'
        );

        probar(
            "Progreso persistido en {$nombre}",
            fn() => $operacion['estado']
                === $escenario['estado']
        );

        probar(
            "Estado del pedido tras fallo de {$nombre}",
            fn() => $pedidos->obtenerPorId(
                $id,
                $cliente
            )['estado'] === $escenario['pedido']
        );

        probar(
            "Error registrado en {$nombre}",
            fn() => !empty(
                $operacion['ultimo_error']
            )
        );

        $referencia = $operacion['pago_referencia'];

        desactivarFallo();

        limpiarRegistro();

        // Crear otra instancia para recuperar
        // el progreso almacenado en PostgreSQL.

        $resultado = (new CheckoutService())->procesar(
            $id,
            $cliente,
            $datosCheckout,
            $clave
        );

        probar(
            "Recuperacion exitosa de {$nombre}",
            fn() => $resultado['estado']
                === 'pagado'
        );

        probar(
            "Sin repetir pago tras fallo de {$nombre}",
            fn() => contarLlamadas(
                'POST /api/pagos/procesar'
            ) === 0
        );

        probar(
            "Misma referencia de pago en {$nombre}",
            fn() => $resultado['pago']['referencia']
                === $referencia
        );

        probar(
            "Checkout completado tras fallo de {$nombre}",
            fn() => $operaciones
                ->buscarPorPedido($id)['estado']
                    === 'completado'
        );

        probar(
            "Error eliminado tras recuperar {$nombre}",
            fn() => $operaciones
                ->buscarPorPedido($id)['ultimo_error']
                    === null
        );

        if ($nombre !== 'Inventario') {
            probar(
                "Sin repetir inventario en {$nombre}",
                fn() => contarLlamadas(
                    'POST /api/inventario/confirmar'
                ) === 0
            );
        }

        if ($nombre === 'Carrito') {
            probar(
                'Sin generar otra guia de envio',
                fn() => contarLlamadas(
                    'POST /api/envios/generar'
                ) === 0
            );
        }
    }

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
    desactivarFallo();

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

    if (is_file($archivoRegistro)) {
        unlink($archivoRegistro);
    }
}

echo "=====================================\n";
echo "RESULTADOS\n";
echo "Pruebas aprobadas: {$aprobadas}\n";
echo "Pruebas fallidas: {$fallidas}\n";
echo "=====================================\n";

exit($fallidas > 0 ? 1 : 0);