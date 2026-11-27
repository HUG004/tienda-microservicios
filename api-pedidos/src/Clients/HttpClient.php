<?php

declare(strict_types=1);

namespace App\Clients;

use App\Utils\ApiException;
use JsonException;

final class HttpClient
{
    private string $baseUrl;

    public function __construct(
        string $environmentVariable
    ) {
        $url = getenv($environmentVariable);

        if (
            $url === false
            || !filter_var($url, FILTER_VALIDATE_URL)
            || !in_array(
                parse_url($url, PHP_URL_SCHEME),
                ['http', 'https'],
                true
            )
        ) {
            throw new ApiException(
                500,
                'CONFIGURACION_API_INVALIDA',
                'No se ha configurado correctamente una API'
            );
        }

        $this->baseUrl = rtrim($url, '/');
    }

    public function request(
        string $method,
        string $path,
        ?array $body = null,
        bool $internal = false,
        ?string $idempotencyKey = null
    ): array {
        $method = strtoupper($method);

        if (
            !in_array(
                $method,
                ['GET', 'POST', 'PATCH', 'DELETE'],
                true
            )
            || !str_starts_with($path, '/')
        ) {
            throw new ApiException(
                500,
                'SOLICITUD_INTERNA_INVALIDA',
                'Error al construir la solicitud interna'
            );
        }

        $headers = [
            'Accept: application/json'
        ];

        if ($internal) {
            $token = getenv('INTERNAL_API_TOKEN');

            if (
                $token === false
                || strlen($token) < 32
                || preg_match('/[\r\n]/', $token)
            ) {
                throw new ApiException(
                    500,
                    'CONFIGURACION_INTERNA_INVALIDA',
                    'Error de configuracion interna'
                );
            }

            $headers[] = 'X-Internal-Token: ' . $token;
        }

        if ($idempotencyKey !== null) {
            if (!preg_match(
                '/^[A-Za-z0-9_-]{1,100}$/D',
                $idempotencyKey
            )) {
                throw new ApiException(
                    400,
                    'CLAVE_IDEMPOTENCIA_INVALIDA',
                    'La clave de idempotencia es invalida'
                );
            }

            $headers[] =
                'Idempotency-Key: ' . $idempotencyKey;
        }

        $payload = null;

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';

            $payload = json_encode(
                $body,
                JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
            );
        }

        $curl = curl_init(
            $this->baseUrl . $path
        );

        if ($curl === false) {
            throw new ApiException(
                500,
                'CLIENTE_HTTP_ERROR',
                'No se pudo inicializar el cliente HTTP'
            );
        }

        try {
            $options = [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP
                    | CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FAILONERROR => false
            ];

            if ($payload !== null) {
                $options[CURLOPT_POSTFIELDS] = $payload;
            }

            curl_setopt_array(
                $curl,
                $options
            );

            $response = curl_exec($curl);

            $status = (int) curl_getinfo(
                $curl,
                CURLINFO_HTTP_CODE
            );

            if ($response === false || $status === 0) {
                throw new ApiException(
                    503,
                    'SERVICIO_NO_DISPONIBLE',
                    'No fue posible comunicarse con un microservicio'
                );
            }

        } finally {
            unset($curl);
        }

        if ($status >= 500) {
            throw new ApiException(
                503,
                'SERVICIO_NO_DISPONIBLE',
                'Un microservicio no esta disponible temporalmente'
            );
        }

        try {
            $data = json_decode(
                $response,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

        } catch (JsonException $exception) {
            throw new ApiException(
                503,
                'RESPUESTA_API_INVALIDA',
                'Un microservicio devolvio una respuesta invalida',
                $exception
            );
        }

        if (!is_array($data)) {
            throw new ApiException(
                503,
                'RESPUESTA_API_INVALIDA',
                'Formato de respuesta inesperado'
            );
        }

        if ($status >= 200 && $status < 300) {
            return $data;
        }

        if (in_array($status, [400, 404, 409], true)) {
            $code = $data['error']['codigo']
                ?? 'ERROR_MICROSERVICIO';

            $message = $data['error']['mensaje']
                ?? 'La operacion no pudo completarse';

            throw new ApiException(
                $status,
                is_string($code)
                    ? $code
                    : 'ERROR_MICROSERVICIO',
                is_string($message)
                    ? $message
                    : 'La operacion no pudo completarse'
            );
        }

        throw new ApiException(
            503,
            'ERROR_COMUNICACION_INTERNA',
            'No se pudo completar la comunicacion interna'
        );
    }

    public function data(
        string $method,
        string $path,
        ?array $body = null,
        bool $internal = false,
        ?string $idempotencyKey = null
    ): array {
        $response = $this->request(
            $method,
            $path,
            $body,
            $internal,
            $idempotencyKey
        );

        if (
            !isset($response['datos'])
            || !is_array($response['datos'])
        ) {
            throw new ApiException(
                503,
                'RESPUESTA_API_INVALIDA',
                'La respuesta no cumple el contrato establecido'
            );
        }

        return $response['datos'];
    }
}