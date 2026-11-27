# API de Carrito (Juan Antonio)

Microservicio encargado de la gestión del carrito de compras de los usuarios. Desarrollado en Node.js con Express y conectado a PostgreSQL.

## 🛠️ Tecnologías
*   **Lenguaje:** Node.js
*   **Framework:** Express
*   **Base de Datos:** PostgreSQL (Base de datos: `carrito_db`)

## ⚙️ Variables de Entorno Requeridas
El servicio requiere las siguientes variables (definidas en el `docker-compose.yml`):
*   `CARRITO_DB_NAME`, `CARRITO_DB_USER`, `CARRITO_DB_PASSWORD`
*   `INTERNAL_API_TOKEN`: Token para comunicación entre microservicios.
*   `PRODUCTOS_API_URL`: URL de la API de Productos (ej. `http://api-productos:8002`).
*   `INVENTARIO_API_URL`: URL de la API de Inventario (ej. `http://api-inventario:8004`).

## 🚀 Cómo ejecutar
Para levantar este servicio de forma individual (junto con su base de datos):
```bash
docker compose up -d --build api-carrito
```

## 🛣️ Endpoints
### Health Checks
*   `GET /health`: Verifica que el proceso esté vivo.
*   `GET /ready`: Verifica la conexión a la base de datos.

### Carrito
*   `GET /api/carrito/{usuario_id}`: Obtiene el carrito completo (consulta precios e info a la API de Productos vía HTTP).
*   `POST /api/carrito/{usuario_id}/agregar`: Agrega un producto. Valida stock consultando a la API de Inventario.
*   `PATCH /api/carrito/{usuario_id}/productos/{producto_id}`: Actualiza la cantidad de un producto específico.
*   `DELETE /api/carrito/{usuario_id}/productos/{producto_id}`: Elimina un producto del carrito.
*   `DELETE /api/carrito/{usuario_id}/vaciar`: Elimina todos los productos del carrito.

## 🔒 Seguridad
*   Requiere JWT válido (RS256) en el header Authorization para rutas de cliente.
*   Permite acceso interno mediante el header X-Internal-Token.
