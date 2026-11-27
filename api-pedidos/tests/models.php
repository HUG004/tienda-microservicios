<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Database;
use App\Models\Pedido;
use App\Models\CheckoutOperacion;
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

$pdo = Database::connect();

$pedidos = new Pedido($pdo);

$checkout = new CheckoutOperacion($pdo);

$pedidoId = null;

$usuarioPrueba = random_int(
    100000000,
    999999999
);

$clave = 'prueba-' . bin2hex(
    random_bytes(16)
);

$hash = hash(
    'sha256',
    'tarjeta_simulada:aprobar'
);

echo "=====================================\n";
echo " PRUEBAS DE MODELOS API PEDIDOS\n";
echo "=====================================\n";

try {
    // P01. Crear pedido.

    $pedido = $pedidos->crear(
        [
            'usuario_id' => $usuarioPrueba,
            'subtotal' => '1798.00',
            'costo_envio' => '120.00',
            'total' => '1918.00',
            'direccion' => 'Direccion de prueba',
            'codigo_postal' => '38000'
        ],
        [
            [
                'producto_id' => 1,
                'nombre_producto' =>
                    'Teclado mecanico',
                'cantidad' => 2,
                'precio_unitario' => '899.00',
                'subtotal' => '1798.00'
            ]
        ]
    );

    $pedidoId = $pedido['id'];

    probar(
        'Creacion de pedido',
        fn() => $pedidoId > 0
            && $pedido['estado'] === 'pendiente'
    );

    // P02. Consultar pedido.

    probar(
        'Consulta de pedido por ID',
        fn() => $pedidos
            ->buscarPorId($pedidoId)['total']
                === '1918.00'
    );

    // P03. Consultar detalles.

    probar(
        'Persistencia de productos',
        fn() => $pedidos
            ->obtenerDetalles($pedidoId)[0]['cantidad']
                === 2
    );

    // P04. Historial.

    probar(
        'Historial de pedidos del usuario',
        fn() => count(
            $pedidos->buscarPorUsuario(
                $usuarioPrueba
            )
        ) === 1
    );

    // P05. Cantidad total.

    probar(
        'Cantidad total de productos',
        fn() => $pedidos
            ->obtenerCantidadTotal($pedidoId) === 2
    );

    // P06. Pedido inexistente.

    probar(
        'Consulta de pedido inexistente',
        fn() => $pedidos
            ->buscarPorId(0) === null
    );

    // P07. Crear checkout.

    $operacion = $checkout->crearORecuperar(
        $pedidoId,
        $clave,
        $hash
    );

    probar(
        'Creacion de operacion checkout',
        fn() => $operacion['estado'] === 'iniciado'
    );

    // P08. Repetir la misma solicitud.

    probar(
        'Recuperacion idempotente del checkout',
        fn() => $checkout->crearORecuperar(
            $pedidoId,
            $clave,
            $hash
        )['id'] === $operacion['id']
    );

    // P09. Cambiar contenido de la solicitud.

    probarError(
        'Rechazo de solicitud modificada',
        409,
        'IDEMPOTENCIA_CONFLICTO',
        fn() => $checkout->crearORecuperar(
            $pedidoId,
            $clave,
            hash('sha256', 'otra-solicitud')
        )
    );

    // P10. Otra clave para el mismo pedido.

    probarError(
        'Rechazo de segundo checkout',
        409,
        'CHECKOUT_EXISTENTE',
        fn() => $checkout->crearORecuperar(
            $pedidoId,
            $clave . '-otra',
            $hash
        )
    );

    // P11. Consulta por clave.

    probar(
        'Consulta por clave de idempotencia',
        fn() => $checkout
            ->buscarPorClave($clave)['pedido_id']
                === $pedidoId
    );

    // P12. Registrar intento.

    $checkout->registrarIntento($pedidoId);

    probar(
        'Registro de intento de checkout',
        fn() => $checkout
            ->buscarPorPedido($pedidoId)['intentos']
                === 1
    );

    // P13. Registrar error temporal.

    $checkout->registrarError(
        $pedidoId,
        'Error temporal simulado'
    );

    probar(
        'Persistencia de error temporal',
        fn() => $checkout
            ->buscarPorPedido($pedidoId)['ultimo_error']
                === 'Error temporal simulado'
    );

    // P14. No completar checkout incompleto.

    probarError(
        'Rechazo de checkout incompleto',
        409,
        'CHECKOUT_INCOMPLETO',
        fn() => $checkout->completar($pedidoId)
    );

    // P15. Transicion de estado invalida.

    probarError(
        'Rechazo de transicion invalida',
        409,
        'TRANSICION_ESTADO_INVALIDA',
        fn() => $pedidos->actualizarEstado(
            $pedidoId,
            'pendiente',
            'enviado'
        )
    );

    // P16. Registrar pago simulado.

    $checkout->registrarPago(
        $pedidoId,
        [
            'id' => 1,
            'estado' => 'aprobado',
            'referencia' => 'PAGO-PRUEBA'
        ]
    );

    probar(
        'Persistencia de pago aprobado',
        fn() => $checkout
            ->buscarPorPedido($pedidoId)['pago_estado']
                === 'aprobado'
    );

    // P17. Confirmar inventario simulado.

    $checkout->actualizar(
        $pedidoId,
        [
            'inventario_confirmado' => true,
            'estado' => 'inventario_confirmado'
        ]
    );

    probar(
        'Persistencia de inventario confirmado',
        fn() => $checkout
            ->buscarPorPedido($pedidoId)
                ['inventario_confirmado'] === true
    );

    // P18. Marcar pedido pagado.

    probar(
        'Actualizacion de pedido a pagado',
        fn() => $pedidos->actualizarEstado(
            $pedidoId,
            'pendiente',
            'pagado'
        )
    );

    // P19. Guardar guia.

    $guia = 'ENV-PRUEBA-' . $pedidoId;

    probar(
        'Almacenamiento de guia',
        fn() => $pedidos->guardarGuia(
            $pedidoId,
            $guia
        )
    );

    // P20. Completar operaciones.

    $checkout->actualizar(
        $pedidoId,
        [
            'envio_generado' => true,
            'carrito_vaciado' => true,
            'estado' => 'carrito_vaciado'
        ]
    );

    $final = $checkout->completar(
        $pedidoId
    );

    probar(
        'Finalizacion de checkout',
        fn() => $final['estado'] === 'completado'
            && $final['completado_en'] !== null
    );

    // P21. Verificar estado y guia.

    probar(
        'Consulta de pedido finalizado',
        function () use (
            $pedidos,
            $pedidoId,
            $guia
        ): bool {
            $resultado = $pedidos->buscarPorId(
                $pedidoId
            );

            return
                $resultado['estado'] === 'pagado'
                && $resultado['guia'] === $guia;
        }
    );

    // P22. Cambiar a enviado.

    probar(
        'Actualizacion de pedido a enviado',
        fn() => $pedidos->actualizarEstado(
            $pedidoId,
            'pagado',
            'enviado'
        )
    );

    // P23. Verificar bloqueo concurrente.

    probar(
        'Bloqueo concurrente de checkout',
        function () use (
            $checkout,
            $pedidoId
        ): bool {
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                getenv('DB_HOST'),
                getenv('DB_PORT') ?: '5432',
                getenv('DB_NAME')
            );

            $pdo2 = new PDO(
                $dsn,
                getenv('DB_USER'),
                getenv('DB_PASSWORD'),
                [
                    PDO::ATTR_ERRMODE =>
                        PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_PERSISTENT => false
                ]
            );

            $checkout2 = new CheckoutOperacion(
                $pdo2
            );

            $primero = $checkout
                ->adquirirBloqueo($pedidoId);

            if (!$primero) {
                return false;
            }

            try {
                $segundo = $checkout2
                    ->adquirirBloqueo($pedidoId);

                if ($segundo) {
                    $checkout2->liberarBloqueo(
                        $pedidoId
                    );
                }

                return $segundo === false;

            } finally {
                $checkout->liberarBloqueo(
                    $pedidoId
                );
            }
        }
    );

} catch (Throwable $exception) {
    echo "[ERROR GENERAL] "
        . $exception->getMessage()
        . PHP_EOL;

    $fallidas++;

} finally {
    // Eliminar exclusivamente el pedido de prueba.

    if ($pedidoId !== null) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('
                DELETE FROM checkout_operaciones
                WHERE pedido_id = :id
            ');

            $stmt->execute([
                'id' => $pedidoId
            ]);

            $stmt = $pdo->prepare('
                DELETE FROM pedidos
                WHERE id = :id
                  AND usuario_id = :usuario_id
            ');

            $stmt->execute([
                'id' => $pedidoId,
                'usuario_id' => $usuarioPrueba
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
}

echo "=====================================\n";
echo "RESULTADOS\n";
echo "Pruebas aprobadas: {$aprobadas}\n";
echo "Pruebas fallidas: {$fallidas}\n";
echo "=====================================\n";

exit($fallidas > 0 ? 1 : 0);