# API Pedidos — Tienda Microservicios

Microservicio responsable de la gestión de pedidos y la coordinación del proceso de compra dentro del proyecto Arquitectura de Microservicios Multi-Stack.

## 1. Descripción

API Pedidos administra la creación, consulta y actualización de pedidos. También funciona como coordinador del proceso de compra mediante el patrón Saga con orquestación.

El microservicio se comunica con Productos, Carrito, Inventario, Envíos y Pagos mediante solicitudes HTTP REST.

Su responsabilidad principal es mantener la consistencia del proceso de compra, registrar su progreso y permitir la recuperación de operaciones interrumpidas.

## 2. Tecnologías utilizadas

- PHP 8.5.
- PostgreSQL 16.
- Composer.
- Firebase PHP-JWT.
- PDO.
- cURL.
- Docker.
- Docker Compose.
- API REST con JSON.
- JWT con algoritmo RS256.
- Patrón Saga con orquestación.
- Claves de idempotencia.
- Bloqueos consultivos de PostgreSQL.

## 3. Información del servicio

| Propiedad | Valor |
|---|---|
| Nombre | api-pedidos |
| Puerto | 8006 |
| Base de datos | pedidos_db |
| Usuario de base de datos | pedidos_app |
| Imagen Docker | tienda/api-pedidos:1.0.0 |
| Red Docker | red-tienda |
| URL local | http://localhost:8006 |
| URL interna | http://api-pedidos:8006 |

## 4. Estructura del proyecto

```text
api-pedidos/
├── public/
│   └── index.php
├── routes/
│   └── api.php
├── src/
│   ├── Clients/
│   │   ├── HttpClient.php
│   │   ├── CarritoClient.php
│   │   ├── ProductosClient.php
│   │   ├── InventarioClient.php
│   │   ├── EnviosClient.php
│   │   └── PagosClient.php
│   ├── Config/
│   │   └── Database.php
│   ├── Controllers/
│   │   └── PedidoController.php
│   ├── Middleware/
│   │   ├── AuthMiddleware.php
│   │   └── InternalAuthMiddleware.php
│   ├── Models/
│   │   ├── Pedido.php
│   │   └── CheckoutOperacion.php
│   ├── Services/
│   │   ├── PedidoService.php
│   │   ├── CheckoutService.php
│   │   └── JwtService.php
│   └── Utils/
│       ├── ApiException.php
│       ├── Money.php
│       └── Response.php
├── database/
│   ├── 001_crear_pedidos.sql
│   └── 002_crear_checkout_operaciones.sql
├── scripts/
│   └── migrate.php
├── tests/
│   ├── money.php
│   ├── auth.php
│   ├── clients.php
│   ├── models.php
│   ├── pedido_service.php
│   ├── checkout_service.php
│   ├── checkout_recovery.php
│   ├── mock_api.php
│   └── mock_fallos.php
├── composer.json
├── composer.lock
├── Dockerfile
├── .dockerignore
└── README.md
```

## 5. Arquitectura interna

El microservicio utiliza una arquitectura organizada por capas.

- Controllers: reciben solicitudes HTTP y devuelven respuestas JSON.
- Services: implementan las reglas de negocio y coordinan las operaciones.
- Models: administran la persistencia en PostgreSQL.
- Clients: realizan solicitudes HTTP a otros microservicios.
- Middleware: valida autenticación, roles y permisos.
- Utils: proporciona utilidades monetarias, respuestas y manejo de excepciones.
- Routes: relaciona los métodos HTTP y endpoints con sus controladores.

## 6. Base de datos

API Pedidos utiliza una base de datos independiente denominada pedidos_db.

### pedidos

Almacena la información general de cada pedido:

- Identificador del pedido.
- Identificador del usuario.
- Subtotal.
- Costo de envío.
- Total.
- Dirección.
- Código postal.
- Estado.
- Guía de envío.
- Fechas de creación y actualización.

### pedido_detalles

Almacena los productos asociados a cada pedido, incluyendo nombre, cantidad, precio unitario y subtotal.

Los precios se conservan como una copia histórica para evitar que futuras modificaciones en el catálogo alteren pedidos anteriores.

### checkout_operaciones

Almacena el progreso del proceso de compra:

- Clave de idempotencia.
- Hash de la solicitud.
- Estado de la operación.
- Resultado y referencia del pago.
- Confirmación o liberación del inventario.
- Generación del envío.
- Vaciado del carrito.
- Número de intentos.
- Último error.
- Fecha de finalización.

Esta tabla permite recuperar operaciones interrumpidas sin repetir pasos que ya fueron registrados como completados.

## 7. Configuración

Las variables de entorno son proporcionadas mediante el archivo .env principal y Docker Compose.

| Variable del contenedor | Descripción |
|---|---|
| DB_HOST | Servidor PostgreSQL |
| DB_PORT | Puerto PostgreSQL |
| DB_NAME | Nombre de la base de datos |
| DB_USER | Usuario de PostgreSQL |
| DB_PASSWORD | Contraseña de PostgreSQL |
| JWT_ISSUER | Emisor autorizado del JWT |
| JWT_AUDIENCE | Audiencia autorizada |
| JWT_PUBLIC_KEY_PATH | Ruta de la clave pública RSA |
| INTERNAL_API_TOKEN | Credencial de comunicación interna |
| USUARIOS_API_URL | URL interna de Usuarios |
| PRODUCTOS_API_URL | URL interna de Productos |
| INVENTARIO_API_URL | URL interna de Inventario |
| CARRITO_API_URL | URL interna de Carrito |
| ENVIOS_API_URL | URL interna de Envíos |
| PAGOS_API_URL | URL interna de Pagos |

Las credenciales reales no deben almacenarse en el repositorio.

## 8. Instalación y ejecución

Desde la raíz del proyecto:

```bash
docker compose build api-pedidos
docker compose up -d api-pedidos
```

Ejecutar las migraciones:

```bash
docker compose exec -T api-pedidos php scripts/migrate.php
```

Verificar el estado:

```bash
docker compose ps
curl http://localhost:8006/health
curl http://localhost:8006/ready
```

Para ejecutar compras completas deben estar disponibles los microservicios dependientes.

## 9. Endpoints REST

| Método | Endpoint | Acceso |
|---|---|---|
| GET | /health | Público |
| GET | /ready | Público |
| POST | /api/pedidos | Cliente |
| GET | /api/pedidos/usuario/{usuario_id} | Propietario o administrador |
| GET | /api/pedidos/{id} | Propietario, administrador o interno |
| PATCH | /api/pedidos/{id}/estado | Administrador o interno |
| POST | /api/pedidos/{id}/checkout | Cliente propietario |

### 9.1 Health check

GET /health

Comprueba que el proceso del microservicio responde.

```json
{
  "status": "ok",
  "servicio": "api-pedidos"
}
```

### 9.2 Readiness check

GET /ready

Comprueba la disponibilidad de PostgreSQL y las tablas requeridas.

```json
{
  "status": "ready",
  "servicio": "api-pedidos"
}
```

Devuelve HTTP 503 cuando la base de datos no está disponible o no se encuentra preparada.

### 9.3 Crear pedido

POST /api/pedidos

Encabezados:

```http
Authorization: Bearer TOKEN_JWT
Content-Type: application/json
```

Solicitud:

```json
{
  "usuario_id": 1,
  "direccion": "Direccion de entrega",
  "codigo_postal": "38000"
}
```

El servicio consulta el carrito, obtiene los precios oficiales de Productos, calcula los importes, solicita la cotización del envío y reserva el inventario.

El frontend no debe calcular ni establecer el importe definitivo del pedido.

Respuesta de ejemplo — HTTP 201:

```json
{
  "mensaje": "Pedido creado correctamente",
  "datos": {
    "id": 10,
    "usuario_id": 1,
    "subtotal": "1798.00",
    "costo_envio": "120.00",
    "total": "1918.00",
    "direccion": "Direccion de entrega",
    "codigo_postal": "38000",
    "estado": "pendiente",
    "guia": null,
    "creado_en": "2026-11-27T05:45:09Z",
    "actualizado_en": "2026-11-27T05:45:09Z",
    "moneda": "MXN",
    "productos": [
      {
        "producto_id": 1,
        "nombre_producto": "Teclado mecanico",
        "cantidad": 2,
        "precio_unitario": "899.00",
        "subtotal": "1798.00"
      }
    ]
  }
}
```

### 9.4 Consultar pedido

GET /api/pedidos/{id}

Permite consultar los datos completos y productos de un pedido.

Los clientes solamente pueden consultar sus propios pedidos. Los administradores y servicios internos autorizados pueden consultar otros pedidos.

### 9.5 Historial de pedidos

GET /api/pedidos/usuario/{usuario_id}

Devuelve los pedidos del usuario ordenados del más reciente al más antiguo.

Respuesta de ejemplo:

```json
{
  "mensaje": "Historial obtenido correctamente",
  "datos": [
    {
      "id": 10,
      "usuario_id": 1,
      "subtotal": "1798.00",
      "costo_envio": "120.00",
      "total": "1918.00",
      "estado": "pagado",
      "guia": "ENV-00000010",
      "creado_en": "2026-11-27T05:45:09Z",
      "moneda": "MXN"
    }
  ]
}
```

### 9.6 Actualizar estado

PATCH /api/pedidos/{id}/estado

Disponible para administradores y servicios internos autorizados.

```json
{
  "estado": "enviado"
}
```

El cambio directo a pagado no está permitido mediante este endpoint. La confirmación del pago corresponde exclusivamente al proceso de checkout.

La cancelación administrativa está limitada a pedidos pendientes sin una operación de checkout existente.

### 9.7 Procesar checkout

POST /api/pedidos/{id}/checkout

Encabezados obligatorios:

```http
Authorization: Bearer TOKEN_JWT
Content-Type: application/json
Idempotency-Key: UUID_UNICO
```

Solicitud:

```json
{
  "metodo_pago": "tarjeta_simulada",
  "simulacion": "aprobar"
}
```

Métodos de pago disponibles:

- tarjeta_simulada
- transferencia_simulada

Resultados de simulación:

- aprobar
- rechazar

No se utilizan datos bancarios reales.

Respuesta de compra aprobada:

```json
{
  "mensaje": "Compra completada correctamente",
  "datos": {
    "pedido_id": 10,
    "estado": "pagado",
    "total": "1918.00",
    "pago": {
      "estado": "aprobado",
      "referencia": "PAGO-00000010"
    },
    "envio": {
      "guia": "ENV-00000010",
      "estado": "preparando"
    }
  }
}
```

Respuesta de compra rechazada:

```json
{
  "mensaje": "Pago rechazado y pedido cancelado",
  "datos": {
    "pedido_id": 11,
    "estado": "cancelado",
    "total": "1918.00",
    "pago": {
      "estado": "rechazado",
      "referencia": "PAGO-00000011"
    },
    "envio": null
  }
}
```

Ambos resultados comerciales pueden devolver HTTP 200 porque la solicitud fue procesada correctamente.

## 10. Estados del pedido

| Estado | Descripción |
|---|---|
| pendiente | Pedido creado y pendiente de pago |
| pagado | Pago aprobado y pedido confirmado |
| enviado | Pedido enviado al cliente |
| cancelado | Pedido cancelado |

Transiciones:

```text
pendiente ──> pagado ──> enviado
    │
    └───────> cancelado
```

## 11. Patrón Saga

API Pedidos coordina las operaciones distribuidas del proceso de compra.

### Compra aprobada

1. Validar el usuario y el pedido.
2. Registrar o recuperar la operación de checkout.
3. Proteger la reserva de inventario.
4. Solicitar el procesamiento del pago.
5. Registrar el pago aprobado.
6. Confirmar el inventario.
7. Actualizar el pedido a pagado.
8. Generar y almacenar la guía de envío.
9. Vaciar el carrito.
10. Finalizar la operación.

### Compra rechazada

1. Registrar el pago rechazado.
2. Liberar la reserva de inventario.
3. Cancelar el pedido.
4. Conservar el carrito.
5. Finalizar la operación.

### Recuperación ante fallos

Cuando un servicio no está disponible, se registra el error y se conserva el progreso de la operación.

El cliente puede repetir el checkout utilizando la misma clave de idempotencia y el mismo cuerpo de solicitud.

Si el pago ya fue registrado como aprobado, el coordinador continúa con los pasos pendientes sin volver a solicitar ese pago.

Un error de comunicación con Pagos no debe interpretarse automáticamente como un pago rechazado.

## 12. Idempotencia y concurrencia

Cada checkout requiere el encabezado Idempotency-Key.

- Una misma clave con la misma solicitud permite recuperar o repetir la respuesta.
- Una clave diferente para un pedido que ya tiene checkout genera HTTP 409.
- Una modificación de la solicitud asociada a la misma clave genera un conflicto.
- Los bloqueos consultivos de PostgreSQL impiden que dos ejecuciones del checkout del mismo pedido progresen simultáneamente.

La garantía distribuida depende también de que los microservicios participantes implementen correctamente sus operaciones idempotentes.

## 13. Seguridad

### JWT

API Pedidos verifica los tokens emitidos por API Usuarios mediante el algoritmo RS256.

Valida la firma, emisor, audiencia, expiración, identificador del usuario y rol.

Únicamente API Usuarios posee la clave privada. API Pedidos utiliza la clave pública RSA.

### Comunicación interna

Los servicios autorizados pueden utilizar:

```http
X-Internal-Token: TOKEN_INTERNO
```

Esta credencial es exclusiva del backend y no debe enviarse al frontend.

### Seguridad adicional

- Consultas SQL parametrizadas.
- Validación de datos de entrada.
- Control de acceso por propietario y rol.
- Manejo centralizado de excepciones.
- Credenciales mediante variables de entorno.
- Clave pública montada en modo de solo lectura.
- Importes monetarios calculados mediante centavos enteros.
- Respuestas sin información interna de excepciones o credenciales.

## 14. Comunicación con otros microservicios

| Servicio | URL interna | Responsabilidad |
|---|---|---|
| Usuarios | http://api-usuarios:8001 | Emisión de JWT |
| Productos | http://api-productos:8002 | Consulta de precios y productos |
| Envíos | http://api-envios:8003 | Cotización y generación de guías |
| Inventario | http://api-inventario:8004 | Reservar, proteger, confirmar y liberar stock |
| Carrito | http://api-carrito:8005 | Consulta y vaciado del carrito |
| Pagos | http://api-pagos:8007 | Procesamiento de pagos simulados |

## 15. Integración con Angular

El frontend debe utilizar las rutas relativas /api mediante el proxy configurado para el proyecto.

Flujo:

1. Obtener el JWT desde API Usuarios.
2. Agregar productos al carrito.
3. Consultar la cotización del envío.
4. Crear el pedido mediante POST /api/pedidos.
5. Guardar el identificador y total devueltos por el backend.
6. Generar una clave mediante crypto.randomUUID().
7. Enviar el checkout con el JWT y la clave de idempotencia.
8. Interpretar el resultado comercial del pago.
9. Mostrar la confirmación y guía de envío cuando corresponda.
10. Consultar el historial de pedidos.

Si ocurre un error temporal HTTP 503 durante el checkout, se debe conservar el identificador del pedido, la clave de idempotencia y el cuerpo original para reintentar la misma operación.

No se debe crear automáticamente otro pedido ni generar una nueva clave para recuperar un checkout interrumpido.

## 16. Códigos HTTP

| Código | Descripción |
|---|---|
| 200 | Solicitud procesada correctamente |
| 201 | Pedido creado |
| 400 | Solicitud inválida |
| 401 | Autenticación inválida o inexistente |
| 403 | Acceso denegado |
| 404 | Recurso inexistente |
| 405 | Método HTTP no permitido |
| 409 | Conflicto de estado o idempotencia |
| 500 | Error interno |
| 503 | Servicio temporalmente no disponible |

Formato de error:

```json
{
  "error": {
    "codigo": "CODIGO_ERROR",
    "mensaje": "Descripcion del error"
  }
}
```

## 17. Pruebas realizadas

| Conjunto de pruebas automatizadas | Aprobadas |
|---|---:|
| Cálculos monetarios | 15 |
| Autenticación y seguridad | 16 |
| Clientes HTTP | 20 |
| Modelos de datos | 23 |
| Lógica de pedidos | 23 |
| Checkout | 27 |
| Recuperación ante fallos | 43 |
| Total | 167 |

Adicionalmente se realizaron pruebas HTTP manuales, compras aprobadas y rechazadas, validaciones de idempotencia, consultas de PostgreSQL y comprobaciones dentro de Docker.

Las pruebas distribuidas de desarrollo utilizaron simuladores HTTP. La integración entre Usuarios, Pedidos y PostgreSQL se verificó utilizando servicios reales.

## 18. Alcance y consideraciones técnicas

El servidor integrado de PHP se utiliza para el entorno académico de desarrollo y demostración.

La integración completa requiere verificar los contratos con los demás microservicios del proyecto.

La recuperación de reservas expiradas, la idempotencia durante la creación inicial del pedido y las modificaciones concurrentes del carrito son aspectos que requieren validaciones adicionales.

Los contratos generales y decisiones de arquitectura se encuentran en documentacion/arquitectura/ dentro del repositorio principal.