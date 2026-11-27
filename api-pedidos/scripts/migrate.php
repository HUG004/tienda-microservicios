<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Database;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

try {
    echo "=====================================\n";
    echo " MIGRACIONES API PEDIDOS\n";
    echo "=====================================\n";

    $pdo = Database::connect();

    $directory = dirname(__DIR__) . '/database';

    $files = glob($directory . '/*.sql');

    if ($files === false || count($files) === 0) {
        throw new RuntimeException(
            'No se encontraron archivos de migracion'
        );
    }

    sort($files, SORT_STRING);

    // Evitar migraciones simultaneas.
    $pdo->query(
        'SELECT pg_advisory_lock(8006, 1)'
    );

    try {
        foreach ($files as $file) {
            $name = basename($file);

            echo "Ejecutando: {$name}\n";

            $sql = file_get_contents($file);

            if ($sql === false || trim($sql) === '') {
                throw new RuntimeException(
                    "No se pudo leer la migracion {$name}"
                );
            }

            $pdo->exec($sql);

            echo "[OK] {$name}\n";
        }
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $pdo->query(
            'SELECT pg_advisory_unlock(8006, 1)'
        );
    }

    echo "=====================================\n";
    echo "Migraciones completadas correctamente\n";
    echo "=====================================\n";

    exit(0);

} catch (Throwable $exception) {
    fwrite(
        STDERR,
        "[ERROR] " . $exception->getMessage() . PHP_EOL
    );

    exit(1);
}