<?php

declare(strict_types=1);

use App\Config\Database;
use App\Models\Usuario;
use App\Services\AuthService;
use App\Services\JwtService;
use App\Middleware\AuthMiddleware;
use App\Controllers\UsuarioController;
use App\Utils\Response;

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$ruta = parse_url(
    $_SERVER['REQUEST_URI'] ?? '/',
    PHP_URL_PATH
);

$ruta = rtrim($ruta ?: '/', '/') ?: '/';

// Liveness: comprueba que la aplicación responde.
if ($metodo === 'GET' && $ruta === '/health') {
    Response::json([
        'status' => 'ok',
        'servicio' => 'api-usuarios'
    ]);
}

// Readiness: comprueba las dependencias necesarias.
if ($metodo === 'GET' && $ruta === '/ready') {
    try {
        Database::connect()->query(
            'SELECT 1 FROM usuarios LIMIT 1'
        );

        foreach ([
            'JWT_PRIVATE_KEY_PATH',
            'JWT_PUBLIC_KEY_PATH'
        ] as $variable) {
            $archivo = getenv($variable);

            if (!$archivo || !is_readable($archivo)) {
                throw new RuntimeException(
                    'Clave RSA no disponible'
                );
            }
        }

        $jwtPrueba = new JwtService();

        $tokenPrueba = $jwtPrueba->generar([
            'id' => 1,
            'rol' => 'cliente'
        ]);

        $jwtPrueba->validar($tokenPrueba);

        Response::json([
            'status' => 'ready',
            'servicio' => 'api-usuarios'
        ]);
    } catch (Throwable $e) {
        Response::json([
            'status' => 'not_ready',
            'servicio' => 'api-usuarios'
        ], 503);
    }
}

$rutas = [
    '/api/usuarios/registro' => 'POST',
    '/api/usuarios/login' => 'POST',
    '/api/usuarios/me' => 'GET',
    '/health' => 'GET',
    '/ready' => 'GET'
];

$esPerfil = preg_match(
    '#^/api/usuarios/([1-9][0-9]*)$#',
    $ruta,
    $matches
);

if (!isset($rutas[$ruta]) && !$esPerfil) {
    Response::error(
        'RUTA_NO_ENCONTRADA',
        'El endpoint solicitado no existe',
        404
    );
}

$metodoPermitido = $rutas[$ruta] ?? 'GET';

if ($metodo !== $metodoPermitido) {
    header('Allow: ' . $metodoPermitido);

    Response::error(
        'METODO_NO_PERMITIDO',
        'El método HTTP no está permitido',
        405
    );
}

$db = Database::connect();

$usuarios = new Usuario($db);

$jwt = new JwtService();

$auth = new AuthService(
    $usuarios,
    $jwt
);

$middleware = new AuthMiddleware($jwt);

$controller = new UsuarioController(
    $usuarios,
    $auth,
    $middleware
);

if ($ruta === '/api/usuarios/registro') {
    $controller->registro();
}

if ($ruta === '/api/usuarios/login') {
    $controller->login();
}

if ($ruta === '/api/usuarios/me') {
    $controller->me();
}

if ($esPerfil) {
    $id = filter_var(
        $matches[1],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($id === false) {
        Response::error(
            'ID_INVALIDO',
            'El identificador no es válido',
            400
        );
    }

    $controller->mostrar($id);
}