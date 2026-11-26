# Arquitectura de Microservicios Multi-Stack

## 1. Descripción general

El proyecto consiste en una plataforma de comercio electrónico
implementada mediante ocho microservicios REST independientes
y una aplicación frontend desarrollada en Angular.

Cada microservicio administra un dominio específico del negocio,
utilizando tecnologías y almacenamiento independientes.

El sistema podrá ejecutarse mediante Docker Compose y Kubernetes.

## 2. Servicios

| Servicio | Tecnología | Puerto | Base de datos |
|---|---|---|---|
| api-usuarios | PHP + JWT | 8001 | usuarios_db |
| api-productos | PHP | 8002 | productos_db |
| api-envios | Python + FastAPI | 8003 | envios_db |
| api-inventario | Go + Gin | 8004 | inventario_db |
| api-carrito | Node.js + Express | 8005 | carrito_db |
| api-pedidos | PHP | 8006 | pedidos_db |
| api-pagos | Java 21 + Spring Boot | 8007 | pagos_db |
| api-resenas | Python + FastAPI | 8008 | resenas_db |
| frontend-tienda | Angular 21 | 4200 | No aplica |

## 3. Estándares de desarrollo

- Todas las APIs utilizarán REST y JSON.
- Los nombres de los campos utilizarán snake_case.
- Los identificadores de usuarios, productos y pedidos serán enteros.
- Las fechas utilizarán el formato ISO 8601 en UTC.
- Los importes monetarios se almacenarán con precisión decimal.
- La moneda utilizada será MXN.
- Las contraseñas nunca se almacenarán en texto plano.
- Las credenciales se configurarán mediante variables de entorno.
- Cada microservicio tendrá su propio Dockerfile.
- Cada microservicio administrará exclusivamente sus datos.
- La comunicación entre dominios se realizará mediante APIs REST.

## 4. Comunicación entre servicios

En Docker Compose se utilizará la red interna red-tienda.

Los servicios se comunicarán mediante sus nombres DNS internos:

- http://api-usuarios:8001
- http://api-productos:8002
- http://api-envios:8003
- http://api-inventario:8004
- http://api-carrito:8005
- http://api-pedidos:8006
- http://api-pagos:8007
- http://api-resenas:8008

Los servicios deberán escuchar en 0.0.0.0 dentro del contenedor.

Las URLs de comunicación serán configurables mediante variables
de entorno y no estarán escritas directamente en la lógica de negocio.

El frontend utilizará rutas relativas /api mediante un proxy HTTP.
Los nombres DNS internos no serán utilizados directamente por
el navegador.

## 5. Almacenamiento

Se utilizará PostgreSQL 16 para siete microservicios y MongoDB 8
para el microservicio de reseñas.

Cada servicio tendrá su propia base de datos y credenciales.

No se permiten consultas directas a bases de datos pertenecientes
a otros microservicios.

Los identificadores externos no tendrán claves foráneas entre
bases de datos independientes.

## 6. Autenticación

La API de Usuarios será responsable de generar tokens JWT.

Las rutas protegidas recibirán el token mediante:

Authorization: Bearer TOKEN

Los servicios validarán la firma, expiración, emisor y audiencia
del token antes de permitir operaciones protegidas.

Los roles iniciales serán:

- cliente
- admin

## 7. Health Check

Todos los microservicios deberán implementar:

GET /health

Respuesta HTTP 200:

{
  "status": "ok",
  "servicio": "nombre-del-servicio"
}

También implementarán GET /ready para verificar que sus
dependencias necesarias estén disponibles.

Estos endpoints serán utilizados por Docker y Kubernetes.

## 8. Manejo de errores

Los servicios deberán utilizar códigos HTTP estándar:

- 200: Operación exitosa.
- 201: Recurso creado.
- 400: Solicitud incorrecta.
- 401: Autenticación requerida o inválida.
- 403: Acceso no autorizado.
- 404: Recurso no encontrado.
- 409: Conflicto con el estado actual.
- 500: Error interno.
- 503: Servicio temporalmente no disponible.

Formato general de error:

{
  "error": {
    "codigo": "CODIGO_ERROR",
    "mensaje": "Descripción del error"
  }
}

## 9. Orquestación

Docker Compose permitirá ejecutar el sistema completo
mediante contenedores conectados a una red interna.

Kubernetes permitirá administrar:

- Pods.
- Deployments.
- Services.
- Namespaces.
- ConfigMaps.
- Secrets.
- PersistentVolumeClaims.
- Escalamiento horizontal.
- Recuperación automática.

Se utilizará Kubernetes integrado en Docker Desktop,
con kind y un nodo local.

El namespace del proyecto será tienda.

## 10. Control de versiones

El proyecto utilizará un monorepo en GitHub.

Ramas:

- main
- develop
- feature/usuarios-pedidos
- feature/productos-inventario-resenas
- feature/carrito-envios-pagos
- feature/frontend
- feature/kubernetes (se creará posteriormente)

Los cambios se integrarán mediante Pull Requests hacia develop.

La rama main estará reservada para versiones estables.

## 11. Seguridad

- No subir archivos .env al repositorio.
- No publicar contraseñas ni claves privadas.
- Validar los datos recibidos por las APIs.
- Utilizar consultas parametrizadas.
- Validar permisos y propiedad de los recursos.
- No almacenar información bancaria real.
- No exponer las bases de datos innecesariamente.
- No registrar tokens ni contraseñas en los logs.

## 12. Pruebas

Cada integrante será responsable de comprobar:

- Funcionamiento individual de sus servicios.
- Endpoints REST.
- Validaciones.
- Manejo de errores.
- Persistencia de datos.
- Construcción de imágenes Docker.
- Funcionamiento de los contenedores.

Posteriormente se realizarán pruebas de integración,
escalabilidad y recuperación automática en Kubernetes.