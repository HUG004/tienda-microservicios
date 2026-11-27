<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Usuario;
use App\Services\AuthService;
use App\Middleware\AuthMiddleware;
use App\Utils\Response;
use JsonException;

final class UsuarioController
{
    public function __construct(
        private Usuario $usuarios,
        private AuthService $auth,
        private AuthMiddleware $middleware
    ) {}

    public function registro(): never
    {
        $datos = $this->leerJson();

        $usuario = $this->auth
            ->registrar($datos);

        Response::success(
            'Usuario registrado correctamente',
            $usuario,
            201
        );
    }

    public function login(): never
    {
        $datos = $this->leerJson();

        $resultado = $this->auth
            ->login($datos);

        Response::success(
            'Inicio de sesión exitoso',
            $resultado
        );
    }

    public function me(): never
    {
        $autenticado = $this->usuarioAutenticado();

        unset($autenticado['creado_en']);

        Response::success(
            'Perfil obtenido correctamente',
            $autenticado
        );
    }

    public function mostrar(int $id): never
    {
        $autenticado = $this->usuarioAutenticado();

        $this->middleware->exigirPropietarioOAdmin(
            [
                'sub' => (string) $autenticado['id'],
                'rol' => $autenticado['rol']
            ],
            $id
        );

        $usuario = $this->usuarios
            ->buscarPorId($id);

        if (!$usuario) {
            Response::error(
                'USUARIO_NO_ENCONTRADO',
                'El usuario no existe',
                404
            );
        }

        Response::success(
            'Usuario obtenido correctamente',
            $usuario
        );
    }

    private function usuarioAutenticado(): array
    {
        $payload = $this->middleware
            ->autenticar();

        $usuario = $this->usuarios
            ->buscarPorId((int) $payload['sub']);

        if (!$usuario || !$usuario['activo']) {
            Response::error(
                'USUARIO_NO_AUTORIZADO',
                'La cuenta no está disponible',
                401
            );
        }

        return $usuario;
    }

    private function leerJson(): array
    {
        $contenido = file_get_contents('php://input');

        try {
            $objeto = json_decode(
                $contenido ?: '',
                false,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            Response::error(
                'JSON_INVALIDO',
                'El cuerpo de la solicitud no contiene JSON válido',
                400
            );
        }

        if (!$objeto instanceof \stdClass) {
            Response::error(
                'DATOS_INVALIDOS',
                'Se esperaba un objeto JSON',
                400
            );
        }

        return (array) $objeto;
    }
}