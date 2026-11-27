<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Usuario;
use App\Utils\Response;
use PDOException;

final class AuthService
{
    public function __construct(
        private Usuario $usuarios,
        private JwtService $jwt
    ) {}

    public function registrar(array $datos): array
    {
        $nombre = $datos['nombre'] ?? null;
        $correo = $datos['correo'] ?? null;
        $contrasena = $datos['contrasena'] ?? null;

        if (
            !is_string($nombre) ||
            !is_string($correo) ||
            !is_string($contrasena)
        ) {
            Response::error(
                'DATOS_INVALIDOS',
                'Nombre, correo y contraseña son requeridos',
                400
            );
        }

        $nombre = trim($nombre);
        $correo = strtolower(trim($correo));

        if (
            $nombre === '' ||
            mb_strlen($nombre) > 150
        ) {
            Response::error(
                'NOMBRE_INVALIDO',
                'El nombre debe contener entre 1 y 150 caracteres',
                400
            );
        }

        if (
            strlen($correo) > 255 ||
            !filter_var($correo, FILTER_VALIDATE_EMAIL)
        ) {
            Response::error(
                'CORREO_INVALIDO',
                'El correo electrónico no es válido',
                400
            );
        }

        if (
            mb_strlen($contrasena) < 8 ||
            strlen($contrasena) > 72 ||
            str_contains($contrasena, "\0")
        ) {
            Response::error(
                'CONTRASENA_INVALIDA',
                'La contraseña debe tener al menos 8 caracteres y máximo 72 bytes válidos',
                400
            );
        }

        if ($this->usuarios->buscarPorCorreo($correo)) {
            Response::error(
                'CORREO_REGISTRADO',
                'El correo ya se encuentra registrado',
                409
            );
        }

        $hash = password_hash(
            $contrasena,
            PASSWORD_BCRYPT,
            ['cost' => 12]
        );

        try {
            return $this->usuarios->crear(
                $nombre,
                $correo,
                $hash
            );
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23505') {
                Response::error(
                    'CORREO_REGISTRADO',
                    'El correo ya se encuentra registrado',
                    409
                );
            }

            throw $e;
        }
    }

    public function login(array $datos): array
    {
        $correo = $datos['correo'] ?? null;
        $contrasena = $datos['contrasena'] ?? null;

        if (
            !is_string($correo) ||
            !is_string($contrasena) ||
            trim($correo) === '' ||
            $contrasena === ''
        ) {
            Response::error(
                'DATOS_INVALIDOS',
                'Correo y contraseña son requeridos',
                400
            );
        }

        if (
            strlen($contrasena) > 72 ||
            str_contains($contrasena, "\0")
        ) {
            Response::error(
                'CREDENCIALES_INVALIDAS',
                'Correo o contraseña incorrectos',
                401
            );
        }

        $correo = strtolower(trim($correo));

        $usuario = $this->usuarios
            ->buscarPorCorreo($correo);

        // Hash auxiliar para reducir diferencias
        // de tiempo cuando el correo no existe.
        $hash = $usuario['contrasena_hash']
            ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

        $contrasenaValida = password_verify(
            $contrasena,
            $hash
        );

        if (
            !$usuario ||
            !$contrasenaValida ||
            !Usuario::estaActivo($usuario['activo'])
        ) {
            Response::error(
                'CREDENCIALES_INVALIDAS',
                'Correo o contraseña incorrectos',
                401
            );
        }

        $token = $this->jwt->generar($usuario);

        return [
            'token' => $token,
            'tipo_token' => 'Bearer',
            'expira_en' => $this->jwt->vigencia(),
            'usuario' => [
                'id' => (int) $usuario['id'],
                'nombre' => $usuario['nombre'],
                'correo' => $usuario['correo'],
                'rol' => $usuario['rol']
            ]
        ];
    }
}