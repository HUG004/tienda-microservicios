<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Utils\Money;
use App\Utils\ApiException;

$aprobadas = 0;
$fallidas = 0;

function probar(
    string $nombre,
    mixed $esperado,
    mixed $resultado
): void {
    global $aprobadas, $fallidas;

    if ($esperado === $resultado) {
        echo "[OK] {$nombre}\n";
        $aprobadas++;
    } else {
        echo "[ERROR] {$nombre}\n";
        echo "Esperado: " . var_export($esperado, true) . "\n";
        echo "Recibido: " . var_export($resultado, true) . "\n";
        $fallidas++;
    }
}

function probarError(
    string $nombre,
    string $codigo,
    callable $operacion
): void {
    global $aprobadas, $fallidas;

    try {
        $operacion();

        echo "[ERROR] {$nombre}: no genero excepcion\n";

        $fallidas++;

    } catch (ApiException $exception) {
        probar(
            $nombre,
            $codigo,
            $exception->getErrorCode()
        );
    }
}

echo "=====================================\n";
echo " PRUEBAS UNITARIAS MONEY\n";
echo "=====================================\n";

probar(
    'Conversion a centavos',
    89900,
    Money::toCents('899.00')
);

probar(
    'Conversion a decimal',
    '899.00',
    Money::fromCents(89900)
);

probar(
    'Multiplicacion por cantidad',
    '1798.00',
    Money::multiply('899.00', 2)
);

probar(
    'Suma de subtotal y envio',
    '1918.00',
    Money::add('1798.00', '120.00')
);

probar(
    'Precision decimal',
    '0.30',
    Money::add('0.10', '0.20')
);

probar(
    'Comparacion de montos',
    true,
    Money::equals('899.0', '899.00')
);

probar(
    'Comparacion de montos diferentes',
    false,
    Money::equals('899.00', '899.01')
);

probar(
    'Monto cero',
    '0.00',
    Money::fromCents(0)
);

probarError(
    'Rechazo de monto negativo',
    'MONTO_INVALIDO',
    fn() => Money::toCents('-100.00')
);

probarError(
    'Rechazo de monto no numerico',
    'MONTO_INVALIDO',
    fn() => Money::toCents('abc')
);

probarError(
    'Rechazo de mas de dos decimales',
    'MONTO_INVALIDO',
    fn() => Money::toCents('10.123')
);

probarError(
    'Rechazo de cantidad cero',
    'CANTIDAD_INVALIDA',
    fn() => Money::multiply('899.00', 0)
);

probarError(
    'Rechazo de cantidad negativa',
    'CANTIDAD_INVALIDA',
    fn() => Money::multiply('899.00', -2)
);

probarError(
    'Rechazo de multiplicacion fuera del limite',
    'MONTO_EXCEDIDO',
    fn() => Money::multiply('9999999999.99', 2)
);

probarError(
    'Rechazo de suma fuera del limite',
    'MONTO_EXCEDIDO',
    fn() => Money::add('9999999999.99', '0.01')
);

echo "=====================================\n";
echo "RESULTADOS\n";
echo "Pruebas aprobadas: {$aprobadas}\n";
echo "Pruebas fallidas: {$fallidas}\n";
echo "=====================================\n";

exit($fallidas > 0 ? 1 : 0);