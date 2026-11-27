# Autenticación y Seguridad

## 1. Objetivo

Implementar un mecanismo de autenticación distribuida
que permita identificar a los usuarios y proteger los
recursos de los ocho microservicios.

La API de Usuarios será responsable de autenticar
a los usuarios y emitir tokens JWT.

Los demás microservicios validarán los tokens de
manera independiente.

## 2. Tecnologías

- JSON Web Token (JWT).
- Algoritmo RS256.
- Claves RSA de 2048 bits.
- Authorization Bearer.
- Control de acceso basado en roles.
- Autenticación interna entre microservicios.

## 3. Registro de usuarios

Endpoint:

POST /api/usuarios/registro

La API recibirá nombre, correo y contraseña.

Las contraseñas serán almacenadas utilizando
password_hash de PHP con un algoritmo seguro.

Para comprobar las contraseñas se utilizará
password_verify.

Nunca se almacenarán contraseñas en texto plano.

El rol inicial de todos los usuarios registrados
será cliente.

Los administradores se crearán mediante un
procedimiento controlado de inicialización.

## 4. Inicio de sesión

Endpoint:

POST /api/usuarios/login

Proceso:

1. Recibir correo y contraseña.
2. Buscar el usuario en PostgreSQL.
3. Comprobar la contraseña.
4. Verificar que el usuario esté activo.
5. Generar un JWT firmado mediante RS256.
6. Devolver el token y los datos públicos del usuario.

El token tendrá una duración de 3600 segundos.

## 5. Estructura del JWT

Encabezado:

{
  "alg": "RS256",
  "typ": "JWT"
}

Payload:

{
  "iss": "api-usuarios",
  "aud": "tienda-microservicios",
  "sub": "1",
  "rol": "cliente",
  "iat": 1795723200,
  "exp": 1795726800
}

Significado de los campos:

- iss: emisor del token.
- aud: audiencia autorizada.
- sub: identificador del usuario como cadena.
- rol: permisos del usuario.
- iat: fecha de emisión en segundos Unix.
- exp: fecha de expiración en segundos Unix.

Los valores de tiempo anteriores son ejemplos.

El JWT está firmado, no cifrado.

No se incluirán contraseñas, credenciales ni
información personal sensible dentro del token.

## 6. Claves RSA

Se utilizará un par de claves:

- jwt_private.pem
- jwt_public.pem

La clave privada será utilizada exclusivamente
por api-usuarios para firmar los tokens.

La clave pública será utilizada por los
microservicios para verificar los tokens.

Ningún otro servicio tendrá acceso a la clave privada.

Las claves serán montadas como archivos de solo lectura
dentro de los contenedores.

Rutas internas:

/run/secrets/jwt_private.pem
/run/secrets/jwt_public.pem

La clave privada no deberá publicarse en GitHub.

Cada entorno de desarrollo podrá generar su propio
par de claves.

Todos los servicios de un mismo entorno deberán
utilizar la clave pública correspondiente.

## 7. Validación de tokens

Cada microservicio deberá verificar:

- Existencia del encabezado Authorization.
- Formato Bearer.
- Firma criptográfica.
- Algoritmo RS256.
- Emisor api-usuarios.
- Audiencia tienda-microservicios.
- Fecha de expiración.
- Validez del identificador sub.
- Rol autorizado.
- Propiedad del recurso solicitado.

No será suficiente decodificar el JWT.

Un token inválido o expirado devolverá HTTP 401.

Un usuario autenticado sin permisos recibirá HTTP 403.

## 8. Roles

### Cliente

Permisos:

- Consultar productos.
- Consultar inventario.
- Administrar su propio carrito.
- Crear sus propios pedidos.
- Procesar sus compras.
- Consultar sus propios comprobantes.
- Publicar reseñas.
- Consultar su perfil.

### Admin

Permisos:

- Crear productos.
- Reabastecer inventario.
- Consultar perfiles autorizados.
- Consultar pedidos.
- Administrar estados de envíos.

El administrador no podrá marcar directamente
un pedido como pagado sin una transacción aprobada.

## 9. Autenticación entre microservicios

Las operaciones internas utilizarán:

X-Internal-Token: TOKEN_INTERNO

La credencial se obtendrá de la variable:

INTERNAL_API_TOKEN

Esta credencial será generada aleatoriamente.

No se almacenará en el código fuente ni se enviará
al navegador.

Los servicios deberán comparar el valor recibido
con la credencial configurada en su entorno.

Las rutas internas no serán publicadas por el
proxy del frontend.

El proxy deberá eliminar cualquier encabezado
X-Internal-Token recibido desde el navegador.

Esta credencial compartida es una simplificación
para el entorno académico.

En producción se recomienda utilizar identidades
individuales de servicio, permisos específicos
y comunicación cifrada mediante TLS o mTLS.

## 10. Bibliotecas recomendadas

PHP:
- firebase/php-jwt

Python:
- PyJWT
- cryptography

Go:
- github.com/golang-jwt/jwt/v5

Node.js:
- jsonwebtoken

Java:
- Spring Security
- Spring Security OAuth2 Resource Server

Cada integrante podrá utilizar las bibliotecas
compatibles con su tecnología.

## 11. Frontend Angular

El frontend utilizará un servicio de autenticación
para administrar el usuario y el JWT.

Se implementará un interceptor HTTP para agregar:

Authorization: Bearer TOKEN_JWT

El token se conservará en memoria.

Las rutas protegidas utilizarán guards de Angular.

Los guards solamente controlan la navegación.

La autorización definitiva siempre será realizada
por los microservicios.

Cuando el token expire, el usuario deberá iniciar
sesión nuevamente.

No se implementarán refresh tokens en esta versión.

Cerrar sesión eliminará el token del frontend.

Un token previamente emitido continuará siendo
válido hasta su expiración.

## 12. Variables de entorno

JWT_ISSUER=api-usuarios
JWT_AUDIENCE=tienda-microservicios
JWT_TTL_SECONDS=3600
JWT_PUBLIC_KEY_PATH=/run/secrets/jwt_public.pem
JWT_PRIVATE_KEY_PATH=/run/secrets/jwt_private.pem
INTERNAL_API_TOKEN=VALOR_GENERADO_LOCALMENTE

JWT_PRIVATE_KEY_PATH será exclusivo de Usuarios.

## 13. Docker Compose y Kubernetes

Docker Compose montará las claves mediante secrets
o archivos de solo lectura.

Kubernetes utilizará recursos Secret para almacenar
la clave privada y las credenciales internas.

La clave pública podrá distribuirse mediante
ConfigMap o Secret.

Los manifiestos con credenciales reales no se
publicarán en GitHub.

Los Secrets de Kubernetes no equivalen por sí solos
a cifrado seguro de los datos almacenados.

## 14. Pruebas de seguridad

- Registro correcto.
- Registro con correo duplicado.
- Inicio de sesión correcto.
- Contraseña incorrecta.
- Solicitud sin token.
- Solicitud con token modificado.
- Solicitud con token expirado.
- Acceso con rol incorrecto.
- Acceso a recursos de otro usuario.
- Solicitud interna sin credencial.
- Solicitud interna con credencial incorrecta.

## 15. Alcance

La implementación está diseñada para demostrar
autenticación distribuida en microservicios.

No constituye una infraestructura de seguridad
completa para un entorno de producción.