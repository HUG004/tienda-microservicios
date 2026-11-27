<?php
declare(strict_types=1);

const SERVICIO = 'api-productos';

function responder(int $codigo, array $cuerpo): never
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function conectarBD(): PDO
{
    $host = getenv('DB_HOST') ?: 'postgres';
    $puerto = getenv('DB_PORT') ?: '5432';
    $nombre = getenv('DB_NAME') ?: '';
    $usuario = getenv('DB_USER') ?: '';
    $password = getenv('DB_PASSWORD') ?: '';

    if ($nombre === '' || $usuario === '') {
        throw new RuntimeException('Configuración de base de datos incompleta');
    }

    $dsn = "pgsql:host={$host};port={$puerto};dbname={$nombre}";

    return new PDO($dsn, $usuario, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function leerJSON(): array
{
    $contenido = file_get_contents('php://input');
    $datos = json_decode($contenido ?: '', true);

    if (!is_array($datos)) {
        responder(400, [
            'error' => [
                'codigo' => 'JSON_INVALIDO',
                'mensaje' => 'El cuerpo debe contener un JSON válido',
            ],
        ]);
    }

    return $datos;
}

function decodificarBase64Url(string $valor): string|false
{
    $valor = strtr($valor, '-_', '+/');
    $relleno = strlen($valor) % 4;

    if ($relleno !== 0) {
        $valor .= str_repeat('=', 4 - $relleno);
    }

    return base64_decode($valor, true);
}

function autenticarAdmin(): void
{
    // Obtener encabezado Authorization
    $autorizacion = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if (!preg_match('/^Bearer\s+(\S+)$/i', $autorizacion, $coincidencias)) {
        responder(401, [
            'error' => [
                'codigo' => 'TOKEN_REQUERIDO',
                'mensaje' => 'Se requiere un token Bearer válido',
            ],
        ]);
    }

    $token = $coincidencias[1];
    $partes = explode('.', $token);

    if (count($partes) !== 3) {
        responder(401, [
            'error' => [
                'codigo' => 'TOKEN_INVALIDO',
                'mensaje' => 'El token no es válido',
            ],
        ]);
    }

    [$encabezado, $contenido, $firma] = $partes;

    $jsonEncabezado = decodificarBase64Url($encabezado);
    $jsonContenido = decodificarBase64Url($contenido);
    $firmaBinaria = decodificarBase64Url($firma);

    if ($jsonEncabezado === false ||
        $jsonContenido === false ||
        $firmaBinaria === false) {
        responder(401, [
            'error' => [
                'codigo' => 'TOKEN_INVALIDO',
                'mensaje' => 'El token no es válido',
            ],
        ]);
    }

    $datosEncabezado = json_decode($jsonEncabezado, true);
    $claims = json_decode($jsonContenido, true);

    if (!is_array($datosEncabezado) ||
        !is_array($claims) ||
        ($datosEncabezado['alg'] ?? '') !== 'RS256') {
        responder(401, [
            'error' => [
                'codigo' => 'TOKEN_INVALIDO',
                'mensaje' => 'El token no es válido',
            ],
        ]);
    }

    // Cargar configuración JWT
    $issuer = getenv('JWT_ISSUER') ?: '';
    $audience = getenv('JWT_AUDIENCE') ?: '';
    $rutaClave = getenv('JWT_PUBLIC_KEY_PATH') ?: '';

    if ($issuer === '' || $audience === '' || $rutaClave === '') {
        error_log('Configuración JWT incompleta en api-productos');

        responder(500, [
            'error' => [
                'codigo' => 'ERROR_CONFIGURACION',
                'mensaje' => 'No fue posible validar la autenticación',
            ],
        ]);
    }

    $clavePublica = file_get_contents($rutaClave);

    if ($clavePublica === false) {
        error_log('No fue posible leer la clave pública JWT');

        responder(500, [
            'error' => [
                'codigo' => 'ERROR_CONFIGURACION',
                'mensaje' => 'No fue posible validar la autenticación',
            ],
        ]);
    }

    // Verificar firma RS256
    $resultadoFirma = openssl_verify(
        $encabezado . '.' . $contenido,
        $firmaBinaria,
        $clavePublica,
        OPENSSL_ALGO_SHA256
    );

    if ($resultadoFirma !== 1) {
        responder(401, [
            'error' => [
                'codigo' => 'TOKEN_INVALIDO',
                'mensaje' => 'El token no es válido',
            ],
        ]);
    }

    // Validar claims del JWT
    $ahora = time();

    $sub = $claims['sub'] ?? null;
    $iat = $claims['iat'] ?? null;
    $exp = $claims['exp'] ?? null;

    $claimsValidos =
        ($claims['iss'] ?? null) === $issuer &&
        ($claims['aud'] ?? null) === $audience &&
        is_string($sub) &&
        ctype_digit($sub) &&
        (int) $sub > 0 &&
        is_int($iat) &&
        is_int($exp) &&
        $iat <= $ahora &&
        $exp > $ahora &&
        $exp > $iat &&
        in_array($claims['rol'] ?? null, ['cliente', 'admin'], true);

    if (!$claimsValidos) {
        responder(401, [
            'error' => [
                'codigo' => 'TOKEN_INVALIDO',
                'mensaje' => 'El token no es válido o ha expirado',
            ],
        ]);
    }

    // Solo los administradores pueden crear productos
    if ($claims['rol'] !== 'admin') {
        responder(403, [
            'error' => [
                'codigo' => 'ACCESO_DENEGADO',
                'mensaje' => 'No tienes permisos para realizar esta operación',
            ],
        ]);
    }
}

function productoPorId(PDO $db, int $id): array|false
{
    $sql = "
        SELECT
            p.id,
            p.categoria_id,
            c.nombre AS categoria,
            p.nombre,
            p.descripcion,
            p.precio,
            p.imagen_url,
            p.activo,
            p.creado_en,
            p.actualizado_en
        FROM productos p
        INNER JOIN categorias c ON c.id = p.categoria_id
        WHERE p.id = :id
          AND p.activo = TRUE
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute(['id' => $id]);

    return $stmt->fetch();
}

try {
    $ruta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $metodo = $_SERVER['REQUEST_METHOD'];

    if ($metodo === 'GET' && $ruta === '/health') {
        responder(200, [
            'status' => 'ok',
            'servicio' => SERVICIO,
        ]);
    }

    if ($metodo === 'GET' && $ruta === '/ready') {
        $db = conectarBD();
        $db->query('SELECT 1');

        responder(200, [
            'status' => 'ready',
            'servicio' => SERVICIO,
        ]);
    }

    $db = conectarBD();

    // GET /api/productos
    if ($metodo === 'GET' && $ruta === '/api/productos') {
        $sql = "
            SELECT
                p.id,
                p.categoria_id,
                c.nombre AS categoria,
                p.nombre,
                p.descripcion,
                p.precio,
                p.imagen_url,
                p.activo,
                p.creado_en,
                p.actualizado_en
            FROM productos p
            INNER JOIN categorias c ON c.id = p.categoria_id
            WHERE p.activo = TRUE
            ORDER BY p.id ASC
        ";

        $productos = $db->query($sql)->fetchAll();

        responder(200, [
            'mensaje' => 'Productos obtenidos correctamente',
            'datos' => $productos,
        ]);
    }

    // GET /api/productos/{id}
    if ($metodo === 'GET' &&
        preg_match('#^/api/productos/([0-9]+)$#', $ruta, $coincidencias)) {

        $id = (int) $coincidencias[1];

        if ($id <= 0) {
            responder(400, [
                'error' => [
                    'codigo' => 'ID_INVALIDO',
                    'mensaje' => 'El ID debe ser mayor que cero',
                ],
            ]);
        }

        $producto = productoPorId($db, $id);

        if (!$producto) {
            responder(404, [
                'error' => [
                    'codigo' => 'PRODUCTO_NO_ENCONTRADO',
                    'mensaje' => 'Producto no encontrado',
                ],
            ]);
        }

        responder(200, [
            'mensaje' => 'Producto obtenido correctamente',
            'datos' => $producto,
        ]);
    }

    // POST /api/productos
    if ($metodo === 'POST' && $ruta === '/api/productos') {
        autenticarAdmin();

        $datos = leerJSON();

        $categoriaValor = $datos['categoria_id'] ?? $datos['categoria'] ?? null;

        $categoriaId = filter_var(
            $categoriaValor,
            FILTER_VALIDATE_INT
        );

        $nombre = trim($datos['nombre'] ?? '');
        $descripcion = trim($datos['descripcion'] ?? '');
        $precio = $datos['precio'] ?? null;
        $imagenUrl = isset($datos['imagen_url'])
            ? trim($datos['imagen_url'])
            : null;

        if (!$categoriaId || $categoriaId <= 0 ||
            $nombre === '' || $descripcion === '' ||
            !is_numeric($precio) || !is_finite((float) $precio) ||
            (float) $precio <= 0 ||
            ($imagenUrl !== null && $imagenUrl === '')) {

            responder(400, [
                'error' => [
                    'codigo' => 'DATOS_INVALIDOS',
                    'mensaje' => 'Verifica categoria_id, nombre, descripcion y precio',
                ],
            ]);
        }

        $categoria = $db->prepare(
            'SELECT id FROM categorias WHERE id = :id'
        );
        $categoria->execute(['id' => $categoriaId]);

        if (!$categoria->fetch()) {
            responder(400, [
                'error' => [
                    'codigo' => 'CATEGORIA_NO_ENCONTRADA',
                    'mensaje' => 'La categoría indicada no existe',
                ],
            ]);
        }

        $sql = "
            INSERT INTO productos (
                categoria_id,
                nombre,
                descripcion,
                precio,
                imagen_url
            )
            VALUES (
                :categoria_id,
                :nombre,
                :descripcion,
                :precio,
                :imagen_url
            )
            RETURNING id
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            'categoria_id' => $categoriaId,
            'nombre' => $nombre,
            'descripcion' => $descripcion,
            'precio' => $precio,
            'imagen_url' => $imagenUrl,
        ]);

        $id = (int) $stmt->fetchColumn();
        $producto = productoPorId($db, $id);

        responder(201, [
            'mensaje' => 'Producto creado correctamente',
            'datos' => $producto,
        ]);
    }

    responder(404, [
        'error' => [
            'codigo' => 'RUTA_NO_ENCONTRADA',
            'mensaje' => 'Ruta no encontrada',
        ],
    ]);

} catch (PDOException $e) {
    error_log('Error de base de datos en api-productos: ' . $e->getMessage());

    responder(500, [
        'error' => [
            'codigo' => 'ERROR_BASE_DATOS',
            'mensaje' => 'No fue posible procesar la operación',
        ],
    ]);

} catch (Throwable $e) {
    error_log('Error interno en api-productos: ' . $e->getMessage());

    responder(500, [
        'error' => [
            'codigo' => 'ERROR_INTERNO',
            'mensaje' => 'Error interno del servidor',
        ],
    ]);
}
