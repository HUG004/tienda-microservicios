<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\JwtService;
use App\Middleware\AuthMiddleware;
use App\Middleware\InternalAuthMiddleware;
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

$token = getenv('TEST_JWT');

$internalToken = getenv('INTERNAL_API_TOKEN');

if (
    $token === false
    || $token === ''
    || $internalToken === false
    || $internalToken === ''
) {
    fwrite(
        STDERR,
        "Faltan las variables de prueba\n"
    );

    exit(1);
}

echo "=====================================\n";
echo " PRUEBAS DE AUTENTICACION API PEDIDOS\n";
echo "=====================================\n";

$jwt = new JwtService();

$user = $jwt->validate($token);

// P01. Token real generado por API Usuarios.

probar(
    'JWT generado por API Usuarios',
    fn() => $user['id'] > 0
);

// P02. Validacion del rol.

probar(
    'Rol cliente reconocido',
    fn() => $user['rol'] === 'cliente'
);

// P03. Token invalido.

probarError(
    'Rechazo de token invalido',
    401,
    'TOKEN_INVALIDO',
    fn() => $jwt->validate('token-invalido')
);

// P04. Token manipulado.

$parts = explode('.', $token);

$payload = json_decode(
    base64_decode(
        strtr($parts[1], '-_', '+/')
    ),
    true,
    512,
    JSON_THROW_ON_ERROR
);

$payload['rol'] = 'admin';

$modifiedPayload = rtrim(
    strtr(
        base64_encode(
            json_encode(
                $payload,
                JSON_THROW_ON_ERROR
            )
        ),
        '+/',
        '-_'
    ),
    '='
);

$modifiedToken =
    $parts[0] . '.'
    . $modifiedPayload . '.'
    . $parts[2];

probarError(
    'Rechazo de JWT manipulado',
    401,
    'TOKEN_INVALIDO',
    fn() => $jwt->validate($modifiedToken)
);

// P05. Autenticacion sin encabezado.

unset(
    $_SERVER['HTTP_AUTHORIZATION'],
    $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
);

probarError(
    'Rechazo de solicitud sin token',
    401,
    'TOKEN_REQUERIDO',
    fn() => AuthMiddleware::authenticate()
);

// P06. Encabezado incorrecto.

$_SERVER['HTTP_AUTHORIZATION'] =
    'Basic credenciales';

probarError(
    'Rechazo de encabezado incorrecto',
    401,
    'TOKEN_INVALIDO',
    fn() => AuthMiddleware::authenticate()
);

// P07. Autenticacion Bearer.

$_SERVER['HTTP_AUTHORIZATION'] =
    'Bearer ' . $token;

probar(
    'Autenticacion mediante Bearer',
    fn() => AuthMiddleware::authenticate()['id']
        === $user['id']
);

// P08. Acceso a recurso propio.

probar(
    'Acceso a pedido propio',
    function () use ($user): bool {
        AuthMiddleware::requireOwner(
            $user,
            $user['id']
        );

        return true;
    }
);

// P09. Acceso a recurso ajeno.

probarError(
    'Rechazo de acceso a pedido ajeno',
    403,
    'ACCESO_DENEGADO',
    fn() => AuthMiddleware::requireOwner(
        $user,
        $user['id'] + 1
    )
);

// P10. Acceso con rol cliente.

probar(
    'Autorizacion de rol cliente',
    function () use ($user): bool {
        AuthMiddleware::requireRole(
            $user,
            'cliente'
        );

        return true;
    }
);

// P11. Acceso administrativo no permitido.

probarError(
    'Rechazo de privilegios administrativos',
    403,
    'ACCESO_DENEGADO',
    fn() => AuthMiddleware::requireRole(
        $user,
        'admin'
    )
);

// P12. Administrador consultando otro pedido.

$admin = [
    'id' => 999,
    'rol' => 'admin'
];

probar(
    'Administrador autorizado para consulta',
    function () use ($admin): bool {
        AuthMiddleware::requireOwner(
            $admin,
            1,
            true
        );

        return true;
    }
);

// P13. Administrador sin permiso explicito.

probarError(
    'Administrador sin permiso explicito',
    403,
    'ACCESO_DENEGADO',
    fn() => AuthMiddleware::requireOwner(
        $admin,
        1,
        false
    )
);

// P14. Solicitud interna sin token.

unset($_SERVER['HTTP_X_INTERNAL_TOKEN']);

probarError(
    'Solicitud interna sin credenciales',
    401,
    'TOKEN_INTERNO_REQUERIDO',
    fn() => InternalAuthMiddleware::authenticate()
);

// P15. Token interno incorrecto.

$_SERVER['HTTP_X_INTERNAL_TOKEN'] =
    'token-interno-incorrecto';

probarError(
    'Rechazo de token interno incorrecto',
    401,
    'TOKEN_INTERNO_INVALIDO',
    fn() => InternalAuthMiddleware::authenticate()
);

// P16. Token interno correcto.

$_SERVER['HTTP_X_INTERNAL_TOKEN'] =
    $internalToken;

probar(
    'Autenticacion interna correcta',
    function (): bool {
        InternalAuthMiddleware::authenticate();

        return true;
    }
);

echo "=====================================\n";
echo "RESULTADOS\n";
echo "Pruebas aprobadas: {$aprobadas}\n";
echo "Pruebas fallidas: {$fallidas}\n";
echo "=====================================\n";

exit($fallidas > 0 ? 1 : 0);