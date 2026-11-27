# API de Pagos (Juan Antonio)

Microservicio encargado de la simulación de la pasarela de pagos. Desarrollado en Java con Spring Boot y conectado a PostgreSQL.

## 🛠️ Tecnologías
*   **Lenguaje:** Java 21
*   **Framework:** Spring Boot (Spring Web, Spring Data JPA)
*   **Base de Datos:** PostgreSQL (Base de datos: `pagos_db`)

## ⚙️ Variables de Entorno Requeridas
*   `PAGOS_DB_NAME`, `PAGOS_DB_USER`, `PAGOS_DB_PASSWORD`
*   `INTERNAL_API_TOKEN`: Token para comunicación entre microservicios.
*   `PEDIDOS_API_URL`: URL de la API de Pedidos (ej. `http://api-pedidos:8006`).

## 🚀 Cómo ejecutar
Para levantar este servicio de forma individual:
```bash
docker compose up -d --build api-pagos
```

## 🛣️ Endpoints
### Health Checks
*   `GET /health`: Verifica que el proceso esté vivo.
*   `GET /ready`: Verifica la conexión a la base de datos.

### Envíos
*   `POST /api/pagos/procesar`: (Interno) Procesa un pago simulado. Valida el monto real consultando la API de Pedidos.
*   `GET /api/pagos/pedido/{pedido_id}`: Consulta el comprobante de pago de un pedido.

## 🔒 Seguridad e idempotencia
*   Valida JWT (RS256) para el endpoint de actualización de estado (requiere rol admin).
*   Las rutas internas (como generar guía) requieren el header X-Internal-Token.

## 🗄️ Inicialización de Tablas
Las tablas de este microservicio se crean automáticamente al levantar Docker por primera vez, gracias a los scripts SQL montados en el volumen de PostgreSQL (`/database/postgres`).
Para reiniciar las tablas desde cero, ejecuta:
```bash
docker compose down -v
docker compose up -d postgres
```
Los scripts SQL están diseñados con CREATE TABLE IF NOT EXISTS para ser repetibles.