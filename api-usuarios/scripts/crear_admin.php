<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Database;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

try {
    echo "=== CREACION DE ADMINISTRADOR ===\n";

    echo "Nombre: ";
    $nombre = trim((string) fgets(STDIN));

    echo "Correo: ";
    $correo = strtolower(
        trim((string) fgets(STDIN))
    );

    echo "Contrasena: ";

    $terminal = function_exists('stream_isatty')
        && stream_isatty(STDIN);

    if ($terminal) {
        system('stty -echo');
    }

    try {
        $contrasena = rtrim(
            (string) fgets(STDIN),
            "\r\n"
        );
    } finally {
        if ($terminal) {
            system('stty echo');
            echo PHP_EOL;
        }
    }

    if (
        $nombre === '' ||
        mb_strlen($nombre) > 150
    ) {
        throw new RuntimeException(
            'Nombre invalido'
        );
    }

    if (
        strlen($correo) > 255 ||
        !filter_var($correo, FILTER_VALIDATE_EMAIL)
    ) {
        throw new RuntimeException(
            'Correo invalido'
        );
    }

    if (
        mb_strlen($contrasena) < 8 ||
        strlen($contrasena) > 72 ||
        str_contains($contrasena, "\0")
    ) {
        throw new RuntimeException(
            'La contrasena debe tener al menos 8 caracteres y maximo 72 bytes'
        );
    }

    $db = Database::connect();

    $hash = password_hash(
        $contrasena,
        PASSWORD_BCRYPT,
        ['cost' => 12]
    );

    $stmt = $db->prepare("
        INSERT INTO usuarios (
            nombre,
            correo,
            contrasena_hash,
            rol
        )
        VALUES (
            :nombre,
            :correo,
            :hash,
            'admin'
        )
        RETURNING id, nombre, correo, rol
    ");

    $stmt->execute([
        'nombre' => $nombre,
        'correo' => $correo,
        'hash' => $hash
    ]);

    $usuario = $stmt->fetch();

    echo "\nAdministrador creado correctamente.\n";

    echo json_encode(
        $usuario,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE
    ) . PHP_EOL;

} catch (PDOException $e) {

    if ((string) $e->getCode() === '23505') {
        fwrite(
            STDERR,
            "El correo ya se encuentra registrado.\n"
        );
    } else {
        fwrite(
            STDERR,
            "Error de base de datos.\n"
        );
    }

    exit(1);

} catch (RuntimeException $e) {

    fwrite(
        STDERR,
        $e->getMessage() . PHP_EOL
    );

    exit(1);

} catch (Throwable $e) {

    fwrite(
        STDERR,
        "No fue posible crear el administrador.\n"
    );

    exit(1);
}