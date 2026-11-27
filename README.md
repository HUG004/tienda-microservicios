# Tienda de Microservicios Multi-Stack

## Descripción

Plataforma de comercio electrónico desarrollada mediante
una arquitectura de microservicios independientes.

## Tecnologías

- PHP
- Python y FastAPI
- Go y Gin
- Node.js y Express
- Java y Spring Boot
- Angular
- PostgreSQL
- MongoDB
- Docker
- Docker Compose
- Kubernetes
- kubectl

## Arquitectura

El sistema está compuesto por ocho microservicios REST,
una aplicación frontend y bases de datos independientes
por dominio lógico.

## Objetivo

Implementar una aplicación distribuida multi-stack,
demostrando la comunicación entre microservicios,
contenerización, orquestación y escalabilidad.

# api-resenas

Microservicio de reseñas y calificaciones de productos de la plataforma `tienda-microservicios`.

| | |
|---|---|
| Tecnología | Python + FastAPI |
| Base de datos | MongoDB (`resenas_db`, colección `resenas`) |
| Puerto | `8008` |
| Autenticación | JWT RS256 (solo para crear, editar y borrar) |

## Estructura

```text
api-resenas/
├── Dockerfile
├── .dockerignore
├── .gitignore
├── requirements.txt
└── app/
    ├── __init__.py
    ├── main.py        # app, /health, /ready e índices al arrancar
    ├── database.py    # conexión a MongoDB
    ├── auth.py        # validación del JWT RS256
    ├── productos.py   # consulta a api-productos
    ├── resenas.py     # endpoints de reseñas
    └── errores.py     # formato común de errores
```

## Variables de entorno

| Variable | Descripción |
|---|---|
| `MONGO_APP_USER` | Usuario de la base `resenas_db` |
| `MONGO_APP_PASSWORD` | Contraseña de ese usuario |
| `MONGO_DB` | Nombre de la base (`resenas_db`) |
| `MONGO_HOST` | Host de Mongo dentro de Docker (`mongodb`). Sin esta variable se conecta a `localhost` |
| `MONGO_PORT` | Solo en local: puerto publicado en tu máquina (`27018` por defecto) |
| `MONGO_URI` | Opcional. Si existe, se usa tal cual y se ignoran las demás de Mongo |
| `JWT_ISSUER` | Emisor esperado (`api-usuarios`) |
| `JWT_AUDIENCE` | Audiencia esperada (`tienda-microservicios`) |
| `JWT_PUBLIC_KEY_PATH` | Ruta de la llave pública (`/run/secrets/jwt_public.pem`) |
| `PRODUCTOS_API_URL` | URL de api-productos (`http://api-productos:8002`) |

En local, `database.py` lee el `.env` de la raíz del repositorio. Dentro de Docker las variables llegan desde `docker-compose.yml`. No se suben `.env`, llaves ni la carpeta `secrets/`.

## Datos

Colección `resenas`:

| Campo | Tipo | Notas |
|---|---|---|
| `_id` | ObjectId | Se devuelve como cadena en el campo `id` |
| `producto_id` | entero | Producto reseñado |
| `usuario_id` | entero | Autor (debe coincidir con el token) |
| `calificacion` | entero | De 1 a 5 |
| `comentario` | texto | Obligatorio, máximo 1000 caracteres |
| `creado_en` | fecha | ISO 8601 en UTC |

Índices (se crean al arrancar y también antes de la primera inserción):

- `producto_id`
- Único compuesto `producto_id` + `usuario_id` (una reseña por usuario y producto)

## Endpoints

| Método | Ruta | Acceso | Descripción |
|---|---|---|---|
| GET | `/health` | Público | El proceso está funcionando |
| GET | `/ready` | Público | Hay conexión con MongoDB (200 o 503) |
| GET | `/api/resenas/producto/{producto_id}` | Público | Reseñas de un producto con promedio |
| POST | `/api/resenas` | Cliente | Crear reseña |
| POST | `/api/resenas/promedios` | Público | Promedios de hasta 100 productos |
| GET | `/api/resenas/{resena_id}` | Público | Consultar una reseña (extra) |
| PATCH | `/api/resenas/{resena_id}` | Autor | Editar calificación y/o comentario (extra) |
| DELETE | `/api/resenas/{resena_id}` | Autor | Eliminar una reseña (extra) |

Los tres últimos no están en `CONTRATOS_API.md`. Solo el autor puede editar o borrar su reseña.

### Crear reseña

`POST /api/resenas` con `Authorization: Bearer TOKEN`:

```json
{
  "producto_id": 1,
  "usuario_id": 1,
  "calificacion": 5,
  "comentario": "Excelente producto"
}
```

Respuesta `201`:

```json
{
  "mensaje": "Resena creada correctamente",
  "datos": {
    "id": "674620b12f8b4c1234567890",
    "producto_id": 1,
    "usuario_id": 1,
    "calificacion": 5,
    "comentario": "Excelente producto",
    "creado_en": "2026-11-27T11:03:09Z"
  }
}
```

### Consultar reseñas de un producto

`GET /api/resenas/producto/1` devuelve `promedio` (máximo dos decimales), `total_resenas` y la lista `resenas`. Si no hay reseñas devuelve `promedio: 0`, `total_resenas: 0` y `resenas: []`.

### Promedios de varios productos

`POST /api/resenas/promedios` con `{"producto_ids": [1, 2, 3]}`. Los productos sin reseñas aparecen con promedio 0 y total 0.

## Reglas

- La calificación es un entero entre 1 y 5.
- El comentario es obligatorio y admite hasta 1000 caracteres.
- `usuario_id` debe coincidir con el `sub` del token.
- El producto debe existir: se consulta a `api-productos`.
- Solo el rol `cliente` puede crear; un `admin` recibe 403.
- Una reseña por usuario y producto.
- No se exige una compra previa.

## Errores

Formato: `{"error": {"codigo": "...", "mensaje": "..."}}`

| HTTP | Código | Cuándo |
|---|---|---|
| 400 | `DATOS_INVALIDOS` | Datos faltantes, mal formados o fuera de rango |
| 400 | `CALIFICACION_INVALIDA` | Calificación fuera de 1 a 5 |
| 401 | `TOKEN_INVALIDO` | Token ausente, inválido o expirado |
| 403 | `ACCESO_DENEGADO` | Rol incorrecto, usuario distinto al del token o reseña ajena |
| 404 | `PRODUCTO_NO_ENCONTRADO` | El producto no existe |
| 404 | `RESENA_NO_ENCONTRADA` | La reseña no existe |
| 404 | `RUTA_NO_ENCONTRADA` | Ruta inexistente |
| 409 | `RESENA_DUPLICADA` | El usuario ya reseñó ese producto |
| 500 | `ERROR_INTERNO` | Error no controlado |
| 503 | `SERVICIO_NO_DISPONIBLE` | MongoDB o api-productos no responden |

## Ejecución

Con Docker Compose, desde la raíz del repositorio:

```bash
docker compose up -d --build mongodb api-productos api-resenas
docker compose ps
curl -s localhost:8008/ready
```

En local, sin Docker (con Mongo publicado en `localhost:27018`):

```bash
cd api-resenas
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
uvicorn app.main:app --port 8008 --reload
```

## Pruebas

Los tokens de prueba se firman con tu llave privada local (`secrets/jwt_private.pem`), que no se sube a GitHub:

```bash
token() { api-resenas/.venv/bin/python -c "import jwt,time;k=open('secrets/jwt_private.pem').read();n=int(time.time());print(jwt.encode({'iss':'api-usuarios','aud':'tienda-microservicios','sub':'$1','rol':'$2','iat':n,'exp':n+3600},k,algorithm='RS256'))"; }
T1=$(token 1 cliente)
H='Content-Type: application/json'

curl -s localhost:8008/api/resenas/producto/1
curl -s -X POST localhost:8008/api/resenas -H "Authorization: Bearer $T1" -H "$H" \
  -d '{"producto_id":1,"usuario_id":1,"calificacion":5,"comentario":"Excelente producto"}'
curl -s -X POST localhost:8008/api/resenas/promedios -H "$H" -d '{"producto_ids":[1,2,3]}'
```

Casos de error comprobados: reseña duplicada (409), calificación 6 (400), comentario vacío (400), `usuario_id` distinto al del token (403), producto inexistente (404), sin token (401) y token de admin (403).