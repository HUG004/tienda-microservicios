# Microservicios de Carrito, Envíos y Pagos (Integrante 3)

Este módulo contiene la implementación de los microservicios de **Carrito**, **Envíos** y **Pagos** para la plataforma de comercio electrónico. Cada servicio está desarrollado en un lenguaje distinto, cuenta con su propia base de datos y está dockerizado para su fácil despliegue.

## 🛠️ Tecnologías Utilizadas
*   **API de Carrito**: Node.js + Express + PostgreSQL
*   **API de Envíos**: Python + FastAPI + PostgreSQL
*   **API de Pagos**: Java 21 + Spring Boot + PostgreSQL
*   **Infraestructura**: Docker y Docker Compose

## 🚀 Requisitos Previos
*   Tener instalado Docker y Docker Compose.
*   Contar con el archivo `.env` configurado en la raíz del proyecto (basado en `.env.example`).
*   Tener generadas las claves RSA (`secrets/jwt_public.pem` y `secrets/jwt_private.pem`) para la validación de tokens JWT.

## 📦 Cómo levantar los servicios

Para construir y levantar únicamente los servicios de este módulo (junto con las bases de datos), ejecuta el siguiente comando en la raíz del proyecto:

```bash
# Levantar las bases de datos primero
docker compose up -d postgres mongodb

# Construir y levantar las APIs del Integrante 3
docker compose up -d --build api-carrito api-envios api-pagos
```

Puedes verificar que todo esté corriendo correctamente con:

```bash
docker compose ps
```

## 🩺 Health Checks (Obligatorios)

Todos los servicios implementan los endpoints de salud requeridos por el contrato:

*   **Carrito**: GET http://localhost:8005/health y GET http://localhost:8005/ready


```bash
```
