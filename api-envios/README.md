# API de Envíos (Juan Antonio)

Microservicio encargado de la cotización, generación de guías y rastreo de envíos. Desarrollado en Python con FastAPI y conectado a PostgreSQL.

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

### Envíos
*   `POST /api/envios/cotizar`: Calcula el costo de envío basado en código postal y cantidad/peso.
*   `POST /api/envios/generar`: (Interno) Genera una guía única por pedido.
*   `GET /api/envios/rastreo/{guia}`: Consulta pública del estado del envío.
*   `PATCH /api/envios/{guia}/estado`: (Admin) Actualiza el estado del envío. Valida transiciones (preparando -> en_transito -> entregado).

## 🔒 Seguridad
*   Valida JWT (RS256) para el endpoint de actualización de estado (requiere rol admin).
*   Las rutas internas (como generar guía) requieren el header X-Internal-Token.
