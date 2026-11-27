<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Database;

try {
    $db = Database::connect();

    $archivos = glob(
        dirname(__DIR__) . '/database/*.sql'
    );

    sort($archivos);

    foreach ($archivos as $archivo) {
        echo 'Ejecutando: '
            . basename($archivo)
            . PHP_EOL;

        $sql = file_get_contents($archivo);

        if ($sql === false) {
            throw new RuntimeException(
                'No se pudo leer la migración'
            );
        }

        $db->exec($sql);
    }

    echo "Migraciones completadas correctamente."
        . PHP_EOL;

} catch (Throwable $e) {

    fwrite(
        STDERR,
        "Error al ejecutar las migraciones."
        . PHP_EOL
    );

    exit(1);
}