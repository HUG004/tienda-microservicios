# Contratos de API REST
## Tienda de Microservicios Multi-Stack

Documento técnico de referencia para todos los integrantes
del equipo de desarrollo.

Este documento establece los endpoints, estructuras JSON,
validaciones, autenticación y reglas de comunicación entre
los ocho microservicios y el frontend Angular.

---

# 1. Estándares generales

## 1.1. Servicios y puertos

| Servicio | Tecnología | Puerto |
|---|---|---|
| api-usuarios | PHP + JWT | 8001 |
| api-productos | PHP | 8002 |
| api-envios | Python + FastAPI | 8003 |
| api-inventario | Go + Gin | 8004 |
| api-carrito | Node.js + Express | 8005 |
| api-pedidos | PHP + PostgreSQL | 8006 |
| api-pagos | Java + Spring Boot | 8007 |
| api-resenas | Python + FastAPI + MongoDB | 8008 |
| frontend-tienda | Angular | 4200 |

## 1.2. Formato de comunicación

Todas las APIs utilizarán HTTP REST y JSON.

Encabezados generales:

Content-Type: application/json
Accept: application/json

Para endpoints protegidos:

Authorization: Bearer TOKEN_JWT

## 1.3. Convenciones de datos

- Los nombres de campos utilizarán snake_case.
- Los campos estarán escritos en español.
- Los identificadores serán enteros positivos.
- Los identificadores MongoDB se devolverán como cadenas.
- Las fechas utilizarán ISO 8601 en UTC.
- Los códigos postales serán cadenas de cinco dígitos.
- Los importes monetarios se devolverán como cadenas decimales
  de dos posiciones, por ejemplo "899.00".
- La moneda será MXN.
- Las cantidades serán números enteros positivos.
- Los valores booleanos serán true o false.
- Los campos opcionales sin valor podrán ser null.
- Las listas vacías se devolverán como [].

Los importes deben procesarse utilizando aritmética decimal
o cantidades enteras en centavos, evitando errores de precisión.

## 1.4. Formato de respuesta exitosa

{
  "mensaje": "Operación realizada correctamente",
  "datos": {}
}

Cuando se devuelva una colección:

{
  "mensaje": "Consulta realizada correctamente",
  "datos": []
}

## 1.5. Formato de errores

{
  "error": {
    "codigo": "DATOS_INVALIDOS",
    "mensaje": "Los datos proporcionados no son válidos"
  }
}

No devolver contraseñas, consultas SQL, trazas internas
ni información sensible en las respuestas.

## 1.6. Códigos HTTP

| Código | Significado |
|---|---|
| 200 | Operación exitosa |
| 201 | Recurso creado |
| 400 | Datos incorrectos |
| 401 | Token ausente, inválido o expirado |
| 403 | Permisos insuficientes |
| 404 | Recurso no encontrado |
| 409 | Conflicto con el estado actual |
| 500 | Error interno |
| 503 | Dependencia temporalmente no disponible |

## 1.7. Tipos de acceso

- Público: no requiere autenticación.
- Cliente: requiere JWT válido y propiedad del recurso.
- Admin: requiere JWT válido con rol admin.
- Interno: requiere autenticación entre microservicios.

Las operaciones internas utilizarán una credencial de servicio
configurada mediante variables de entorno.

Encabezado:

X-Internal-Token: TOKEN_INTERNO

Esta credencial nunca será enviada al frontend.

## 1.8. Health checks

Todos los microservicios deberán implementar:

GET /health

Acceso: público.

HTTP 200:

{
  "status": "ok",
  "servicio": "api-usuarios"
}

Cada servicio devolverá su propio nombre.

También deberán implementar:

GET /ready

HTTP 200 cuando sus dependencias estén disponibles:

{
  "status": "ready",
  "servicio": "api-usuarios"
}

HTTP 503 cuando no esté preparado:

{
  "status": "not_ready",
  "servicio": "api-usuarios"
}

Estos endpoints no utilizarán el formato general de respuestas.

GET /health comprobará que el proceso está funcionando.

GET /ready comprobará las dependencias indispensables,
principalmente la conexión con su propia base de datos.

---

# 2. API de Usuarios

Servicio: api-usuarios
Puerto: 8001
Tecnología: PHP + JWT
Base de datos: usuarios_db
Responsable: Integrante 1

## 2.1. Registro de usuario

POST /api/usuarios/registro

Acceso: público.

Solicitud:

{
  "nombre": "Juan Perez",
  "correo": "juan@example.com",
  "contrasena": "EjemploSeguro123!"
}

Respuesta HTTP 201:

{
  "mensaje": "Usuario registrado correctamente",
  "datos": {
    "id": 1,
    "nombre": "Juan Perez",
    "correo": "juan@example.com",
    "rol": "cliente",
    "activo": true,
    "creado_en": "2026-11-26T20:00:00Z"
  }
}

Validaciones:

- Nombre obligatorio, máximo 150 caracteres.
- Correo válido, normalizado a minúsculas y único.
- Contraseña de mínimo 8 caracteres.
- La contraseña se almacenará mediante hash seguro.
- El rol asignado será cliente.
- No se permitirá elegir el rol durante el registro.

Errores:

- 400 DATOS_INVALIDOS
- 409 CORREO_REGISTRADO

## 2.2. Inicio de sesión

POST /api/usuarios/login

Acceso: público.

Solicitud:

{
  "correo": "juan@example.com",
  "contrasena": "EjemploSeguro123!"
}

Respuesta HTTP 200:

{
  "mensaje": "Inicio de sesión exitoso",
  "datos": {
    "token": "TOKEN_JWT",
    "tipo_token": "Bearer",
    "expira_en": 3600,
    "usuario": {
      "id": 1,
      "nombre": "Juan Perez",
      "correo": "juan@example.com",
      "rol": "cliente"
    }
  }
}

expira_en representa la duración del token en segundos.

Validaciones:

- Comprobar correo y contraseña.
- Comprobar que el usuario esté activo.
- Generar un JWT firmado.
- No devolver la contraseña ni su hash.

Errores:

- 400 DATOS_INVALIDOS
- 401 CREDENCIALES_INVALIDAS

## 2.3. Consultar perfil

GET /api/usuarios/{id}

Acceso: propietario del perfil o admin.

Ejemplo:

GET /api/usuarios/1

Respuesta HTTP 200:

{
  "mensaje": "Perfil encontrado",
  "datos": {
    "id": 1,
    "nombre": "Juan Perez",
    "correo": "juan@example.com",
    "rol": "cliente",
    "activo": true,
    "creado_en": "2026-11-26T20:00:00Z"
  }
}

Errores:

- 401 TOKEN_INVALIDO
- 403 ACCESO_DENEGADO
- 404 USUARIO_NO_ENCONTRADO

## 2.4. Consultar usuario autenticado

Endpoint complementario.

GET /api/usuarios/me

Acceso: cliente o admin.

Respuesta HTTP 200:

{
  "mensaje": "Usuario autenticado",
  "datos": {
    "id": 1,
    "nombre": "Juan Perez",
    "correo": "juan@example.com",
    "rol": "cliente",
    "activo": true
  }
}

El identificador se obtiene del token JWT.

La ruta /me deberá declararse antes de la ruta dinámica /{id}
cuando el framework utilizado lo requiera.

---

# 3. API de Productos

Servicio: api-productos
Puerto: 8002
Tecnología: PHP
Base de datos: productos_db
Responsable: Integrante 2

## 3.1. Listar productos

GET /api/productos

Acceso: público.

Parámetros opcionales:

- buscar: texto de búsqueda.
- categoria: identificador de categoría.

Ejemplo:

GET /api/productos?buscar=teclado&categoria=1

Respuesta HTTP 200:

{
  "mensaje": "Productos encontrados",
  "datos": [
    {
      "id": 1,
      "nombre": "Teclado mecanico",
      "descripcion": "Teclado mecanico RGB",
      "precio": "899.00",
      "categoria_id": 1,
      "categoria": "Computacion",
      "imagen_url": "/imagenes/teclado.jpg",
      "activo": true
    }
  ]
}

Solo se devolverán productos activos en el catálogo público.

Si no existen coincidencias se devolverá datos: [].

## 3.2. Consultar producto

GET /api/productos/{id}

Acceso: público.

Ejemplo:

GET /api/productos/1

Respuesta HTTP 200:

{
  "mensaje": "Producto encontrado",
  "datos": {
    "id": 1,
    "nombre": "Teclado mecanico",
    "descripcion": "Teclado mecanico RGB",
    "precio": "899.00",
    "categoria_id": 1,
    "categoria": "Computacion",
    "imagen_url": "/imagenes/teclado.jpg",
    "activo": true
  }
}

Errores:

- 404 PRODUCTO_NO_ENCONTRADO

## 3.3. Crear producto

POST /api/productos

Acceso: admin.

Solicitud:

{
  "nombre": "Teclado mecanico",
  "descripcion": "Teclado mecanico RGB",
  "precio": "899.00",
  "categoria": "Computacion",
  "imagen_url": "/imagenes/teclado.jpg"
}

Respuesta HTTP 201:

{
  "mensaje": "Producto creado correctamente",
  "datos": {
    "id": 1,
    "nombre": "Teclado mecanico",
    "descripcion": "Teclado mecanico RGB",
    "precio": "899.00",
    "categoria_id": 1,
    "categoria": "Computacion",
    "imagen_url": "/imagenes/teclado.jpg",
    "activo": true
  }
}

Validaciones:

- Nombre obligatorio.
- Descripción obligatoria.
- Precio mayor que cero.
- Categoría obligatoria.
- imagen_url debe ser una URL HTTP/HTTPS válida
  o una ruta local controlada de imágenes.

La categoría se buscará por nombre. Si no existe,
se podrá crear dentro de la misma transacción.

La creación de productos no asignará stock automáticamente.

El inventario inicial se registrará mediante la API
de Inventario.

## 3.4. Listar categorías

Endpoint complementario.

GET /api/productos/categorias

Acceso: público.

Respuesta HTTP 200:

{
  "mensaje": "Categorias encontradas",
  "datos": [
    {
      "id": 1,
      "nombre": "Computacion",
      "descripcion": "Accesorios y equipos"
    }
  ]
}

Declarar esta ruta antes de /api/productos/{id}.

---

# 4. API de Envíos

Servicio: api-envios
Puerto: 8003
Tecnología: Python + FastAPI
Base de datos: envios_db
Responsable: Integrante 3

## 4.1. Cotizar envío

POST /api/envios/cotizar

Acceso: cliente, admin o interno.

Solicitud:

{
  "codigo_postal": "38000",
  "cantidad": 2
}

Opcionalmente se podrá proporcionar peso en kilogramos:

{
  "codigo_postal": "38000",
  "cantidad": 2,
  "peso": "2.000"
}

Si no se proporciona peso, se estimará un kilogramo
por unidad.

Respuesta HTTP 200:

{
  "mensaje": "Envio cotizado correctamente",
  "datos": {
    "codigo_postal": "38000",
    "cantidad": 2,
    "peso": "2.000",
    "costo_envio": "120.00",
    "dias_estimados": 3,
    "moneda": "MXN"
  }
}

Regla de simulación:

- Tarifa base: 80 MXN.
- Cargo adicional: 20 MXN por kilogramo.
- Tiempo estimado: 3 días.
- Peso estimado: cantidad total de unidades.

La tarifa es simulada para fines académicos.

Validaciones:

- Código postal de cinco dígitos.
- Cantidad mayor que cero.
- Peso mayor que cero cuando se proporcione.

Errores:

- 400 DATOS_INVALIDOS

## 4.2. Generar guía

POST /api/envios/generar

Acceso: interno.

Solicitud:

{
  "pedido_id": 1,
  "codigo_postal": "38000",
  "direccion": "Calle Ejemplo 123, Celaya, Guanajuato",
  "cantidad": 2
}

Respuesta HTTP 201:

{
  "mensaje": "Guia generada correctamente",
  "datos": {
    "id": 1,
    "pedido_id": 1,
    "guia": "ENV-00000001",
    "codigo_postal": "38000",
    "direccion": "Calle Ejemplo 123, Celaya, Guanajuato",
    "peso": "2.000",
    "costo_envio": "120.00",
    "dias_estimados": 3,
    "estado": "preparando"
  }
}

Reglas:

- Solo existirá una guía por pedido.
- La guía deberá ser única.
- El estado inicial será preparando.
- Si el pedido ya tiene una guía, devolver la existente
  con HTTP 200 sin generar otra.
- El servicio de Pedidos solicitará la generación
  después de confirmar el pago.

## 4.3. Rastrear envío

GET /api/envios/rastreo/{guia}

Acceso: público.

Ejemplo:

GET /api/envios/rastreo/ENV-00000001

Respuesta HTTP 200:

{
  "mensaje": "Envio encontrado",
  "datos": {
    "pedido_id": 1,
    "guia": "ENV-00000001",
    "estado": "preparando",
    "dias_estimados": 3,
    "actualizado_en": "2026-11-26T20:30:00Z"
  }
}

No devolver dirección ni datos personales en el rastreo público.

Estados permitidos:

- preparando
- en_transito
- entregado

Errores:

- 404 GUIA_NO_ENCONTRADA

## 4.4. Actualizar estado de envío

Endpoint complementario.

PATCH /api/envios/{guia}/estado

Acceso: admin.

Solicitud:

{
  "estado": "en_transito"
}

Respuesta HTTP 200:

{
  "mensaje": "Estado de envio actualizado",
  "datos": {
    "guia": "ENV-00000001",
    "estado": "en_transito"
  }
}

Transiciones permitidas:

preparando -> en_transito -> entregado

No se permitirán transiciones hacia estados anteriores.

Cuando el envío pase a en_transito, se notificará
a Pedidos para actualizar su estado a enviado.

---

# 5. API de Inventario

Servicio: api-inventario
Puerto: 8004
Tecnología: Go + Gin
Base de datos: inventario_db
Responsable: Integrante 2

## 5.1. Consultar disponibilidad

GET /api/inventario/{producto_id}

Acceso: público.

Ejemplo:

GET /api/inventario/1

Respuesta HTTP 200:

{
  "mensaje": "Inventario encontrado",
  "datos": {
    "producto_id": 1,
    "stock_total": 20,
    "stock_reservado": 0,
    "stock_disponible": 20,
    "disponible": true
  }
}

stock_disponible = stock_total - stock_reservado

Si el producto existe pero no tiene inventario registrado,
se devolverá disponibilidad de cero unidades.

Si el producto no existe:

404 PRODUCTO_NO_ENCONTRADO

## 5.2. Reservar inventario

POST /api/inventario/reservar

Acceso: interno.

Solicitud:

{
  "pedido_id": 1,
  "productos": [
    {
      "producto_id": 1,
      "cantidad": 2
    }
  ]
}

Respuesta HTTP 201:

{
  "mensaje": "Inventario reservado correctamente",
  "datos": {
    "pedido_id": 1,
    "estado": "pendiente",
    "reservas": [
      {
        "id": 1,
        "producto_id": 1,
        "cantidad": 2,
        "estado": "pendiente"
      }
    ],
    "expira_en": "2026-11-26T20:45:00Z"
  }
}

Reglas:

- Validar todos los productos antes de reservar.
- Ejecutar la reserva completa en una transacción.
- Bloquear las filas necesarias para evitar sobreventa.
- Incrementar stock_reservado.
- No disminuir stock_total todavía.
- La reserva tendrá una duración inicial de 15 minutos.
- No crear reservas duplicadas para un mismo pedido.
- Si la misma solicitud ya fue procesada, devolver
  las reservas existentes.
- Si cambia el contenido de una reserva existente,
  devolver HTTP 409.

Errores:

- 400 DATOS_INVALIDOS
- 404 PRODUCTO_NO_ENCONTRADO
- 409 STOCK_INSUFICIENTE
- 409 RESERVA_INCOMPATIBLE

## 5.3. Reabastecer inventario

POST /api/inventario/reabastecer

Acceso: admin.

Solicitud:

{
  "producto_id": 1,
  "cantidad": 10
}

Respuesta HTTP 200:

{
  "mensaje": "Inventario actualizado correctamente",
  "datos": {
    "producto_id": 1,
    "stock_total": 30,
    "stock_reservado": 2,
    "stock_disponible": 28
  }
}

Reglas:

- Comprobar que el producto exista en Productos.
- Incrementar stock_total.
- Crear el registro de inventario si todavía no existe.
- No modificar stock_reservado.

## 5.4. Confirmar reserva

Endpoint complementario obligatorio para la integración.

POST /api/inventario/confirmar

Acceso: interno.

Solicitud:

{
  "pedido_id": 1
}

Respuesta HTTP 200:

{
  "mensaje": "Reserva confirmada correctamente",
  "datos": {
    "pedido_id": 1,
    "estado": "confirmada",
    "productos": [
      {
        "producto_id": 1,
        "cantidad": 2,
        "stock_total": 28,
        "stock_reservado": 0,
        "stock_disponible": 28
      }
    ]
  }
}

Reglas:

- Solo confirmar reservas pendientes.
- Disminuir stock_total en la cantidad reservada.
- Disminuir stock_reservado en la misma cantidad.
- Cambiar las reservas a confirmada.
- Ejecutar la operación en una transacción.
- Si ya está confirmada, devolver HTTP 200 sin
  descontar nuevamente.
- No confirmar reservas liberadas o expiradas.

Errores:

- 404 RESERVA_NO_ENCONTRADA
- 409 RESERVA_NO_CONFIRMABLE

## 5.5. Liberar reserva

Endpoint complementario obligatorio para la integración.

POST /api/inventario/liberar

Acceso: interno.

Solicitud:

{
  "pedido_id": 1
}

Respuesta HTTP 200:

{
  "mensaje": "Reserva liberada correctamente",
  "datos": {
    "pedido_id": 1,
    "estado": "liberada"
  }
}

Reglas:

- Disminuir stock_reservado.
- No modificar stock_total.
- Cambiar las reservas a liberada.
- Si ya está liberada o expirada, devolver HTTP 200.
- No liberar una reserva confirmada.
- Ejecutar la operación en una transacción.

## 5.6. Consultar reserva

Endpoint complementario.

GET /api/inventario/reservas/{pedido_id}

Acceso: interno.

Respuesta HTTP 200:

{
  "mensaje": "Reserva encontrada",
  "datos": {
    "pedido_id": 1,
    "estado": "pendiente",
    "expira_en": "2026-11-26T20:45:00Z",
    "productos": [
      {
        "producto_id": 1,
        "cantidad": 2
      }
    ]
  }
}

Este endpoint permitirá comprobar el estado de una reserva
antes de procesar el pago.

---

# 6. API de Carrito

Servicio: api-carrito
Puerto: 8005
Tecnología: Node.js + Express
Base de datos: carrito_db
Responsable: Integrante 3

## 6.1. Consultar carrito

GET /api/carrito/{usuario_id}

Acceso: propietario del carrito o interno.

Ejemplo:

GET /api/carrito/1

Respuesta HTTP 200:

{
  "mensaje": "Carrito encontrado",
  "datos": {
    "usuario_id": 1,
    "productos": [
      {
        "producto_id": 1,
        "nombre": "Teclado mecanico",
        "precio_unitario": "899.00",
        "cantidad": 2,
        "subtotal": "1798.00"
      }
    ],
    "cantidad_total": 2,
    "subtotal": "1798.00"
  }
}

Si el carrito no existe o está vacío:

{
  "mensaje": "Carrito vacio",
  "datos": {
    "usuario_id": 1,
    "productos": [],
    "cantidad_total": 0,
    "subtotal": "0.00"
  }
}

Reglas:

- Consultar precios vigentes mediante Productos.
- No confiar en precios enviados por el frontend.
- Calcular los subtotales desde el backend.

## 6.2. Agregar producto

POST /api/carrito/{usuario_id}/agregar

Acceso: propietario del carrito.

Solicitud:

{
  "producto_id": 1,
  "cantidad": 2
}

Respuesta HTTP 200:

{
  "mensaje": "Producto agregado al carrito",
  "datos": {
    "usuario_id": 1,
    "productos": [
      {
        "producto_id": 1,
        "nombre": "Teclado mecanico",
        "precio_unitario": "899.00",
        "cantidad": 2,
        "subtotal": "1798.00"
      }
    ],
    "cantidad_total": 2,
    "subtotal": "1798.00"
  }
}

Reglas:

- Comprobar que el producto exista.
- Consultar disponibilidad mediante Inventario.
- Si el producto ya existe en el carrito, sumar cantidad.
- Validar la cantidad acumulada.
- Agregar al carrito no reserva inventario.

Errores:

- 400 DATOS_INVALIDOS
- 403 ACCESO_DENEGADO
- 404 PRODUCTO_NO_ENCONTRADO
- 409 STOCK_INSUFICIENTE

## 6.3. Vaciar carrito

DELETE /api/carrito/{usuario_id}/vaciar

Acceso: propietario del carrito o interno.

Respuesta HTTP 200:

{
  "mensaje": "Carrito vaciado correctamente",
  "datos": {
    "usuario_id": 1,
    "productos": [],
    "cantidad_total": 0,
    "subtotal": "0.00"
  }
}

La operación será idempotente.

## 6.4. Actualizar cantidad

Endpoint complementario.

PATCH /api/carrito/{usuario_id}/productos/{producto_id}

Acceso: propietario del carrito.

Solicitud:

{
  "cantidad": 3
}

Respuesta HTTP 200:

{
  "mensaje": "Cantidad actualizada correctamente",
  "datos": {
    "usuario_id": 1,
    "productos": [
      {
        "producto_id": 1,
        "nombre": "Teclado mecanico",
        "precio_unitario": "899.00",
        "cantidad": 3,
        "subtotal": "2697.00"
      }
    ],
    "cantidad_total": 3,
    "subtotal": "2697.00"
  }
}

La cantidad representa el nuevo valor, no un incremento.

Debe ser mayor que cero y no superar el stock disponible.

## 6.5. Eliminar producto del carrito

Endpoint complementario.

DELETE /api/carrito/{usuario_id}/productos/{producto_id}

Acceso: propietario del carrito.

Respuesta HTTP 200:

{
  "mensaje": "Producto eliminado del carrito",
  "datos": {
    "usuario_id": 1,
    "productos": [],
    "cantidad_total": 0,
    "subtotal": "0.00"
  }
}

---

# 7. API de Pedidos

Servicio: api-pedidos
Puerto: 8006
Tecnología: PHP + PostgreSQL
Base de datos: pedidos_db
Responsable: Integrante 1

La API de Pedidos será responsable de coordinar
el proceso de compra entre los microservicios.

## 7.1. Crear pedido

POST /api/pedidos

Acceso: cliente.

Solicitud:

{
  "usuario_id": 1,
  "direccion": "Calle Ejemplo 123, Celaya, Guanajuato",
  "codigo_postal": "38000"
}

Respuesta HTTP 201:

{
  "mensaje": "Pedido creado correctamente",
  "datos": {
    "id": 1,
    "usuario_id": 1,
    "productos": [
      {
        "producto_id": 1,
        "nombre_producto": "Teclado mecanico",
        "cantidad": 2,
        "precio_unitario": "899.00",
        "subtotal": "1798.00"
      }
    ],
    "subtotal": "1798.00",
    "costo_envio": "120.00",
    "total": "1918.00",
    "moneda": "MXN",
    "direccion": "Calle Ejemplo 123, Celaya, Guanajuato",
    "codigo_postal": "38000",
    "estado": "pendiente",
    "guia": null,
    "creado_en": "2026-11-26T20:30:00Z"
  }
}

Proceso interno:

1. Validar el JWT.
2. Comprobar que usuario_id coincida con el token.
3. Consultar el carrito del usuario.
4. Consultar productos y precios vigentes.
5. Cotizar el envío.
6. Calcular subtotal y total.
7. Crear el pedido pendiente y sus detalles.
8. Solicitar la reserva de inventario.
9. Devolver el pedido creado.

Si la reserva falla por falta de stock,
el pedido se cancelará y se devolverá HTTP 409.

No se aceptarán precios ni totales calculados
por el frontend como valores definitivos.

Errores:

- 400 CARRITO_VACIO
- 400 DATOS_INVALIDOS
- 401 TOKEN_INVALIDO
- 403 ACCESO_DENEGADO
- 409 STOCK_INSUFICIENTE
- 503 SERVICIO_NO_DISPONIBLE

## 7.2. Consultar historial

GET /api/pedidos/usuario/{usuario_id}

Acceso: propietario o admin.

Respuesta HTTP 200:

{
  "mensaje": "Pedidos encontrados",
  "datos": [
    {
      "id": 1,
      "usuario_id": 1,
      "subtotal": "1798.00",
      "costo_envio": "120.00",
      "total": "1918.00",
      "estado": "pagado",
      "guia": "ENV-00000001",
      "creado_en": "2026-11-26T20:30:00Z"
    }
  ]
}

Los pedidos se devolverán del más reciente al más antiguo.

## 7.3. Actualizar estado

PATCH /api/pedidos/{id}/estado

Acceso: interno o admin, según la transición.

Solicitud:

{
  "estado": "enviado"
}

Respuesta HTTP 200:

{
  "mensaje": "Estado actualizado correctamente",
  "datos": {
    "id": 1,
    "estado": "enviado",
    "guia": "ENV-00000001"
  }
}

Estados permitidos:

- pendiente
- pagado
- enviado
- cancelado

Transiciones:

- pendiente -> pagado
- pendiente -> cancelado
- pagado -> enviado

El estado pagado solo podrá establecerse mediante
el flujo interno de confirmación de pago.

No se permitirá marcar un pedido como pagado
mediante una solicitud directa del cliente o administrador.

Un pedido pagado no podrá cancelarse en esta versión,
ya que no se implementarán reembolsos.

Errores:

- 404 PEDIDO_NO_ENCONTRADO
- 409 TRANSICION_INVALIDA

## 7.4. Consultar detalle del pedido

Endpoint complementario.

GET /api/pedidos/{id}

Acceso: propietario, admin o interno.

Respuesta HTTP 200:

{
  "mensaje": "Pedido encontrado",
  "datos": {
    "id": 1,
    "usuario_id": 1,
    "productos": [
      {
        "producto_id": 1,
        "nombre_producto": "Teclado mecanico",
        "cantidad": 2,
        "precio_unitario": "899.00",
        "subtotal": "1798.00"
      }
    ],
    "subtotal": "1798.00",
    "costo_envio": "120.00",
    "total": "1918.00",
    "estado": "pendiente",
    "guia": null,
    "direccion": "Calle Ejemplo 123, Celaya, Guanajuato",
    "codigo_postal": "38000",
    "creado_en": "2026-11-26T20:30:00Z"
  }
}

La API de Pagos utilizará este endpoint para comprobar
el monto real y el estado del pedido.

## 7.5. Procesar checkout

Endpoint complementario de integración.

POST /api/pedidos/{id}/checkout

Acceso: propietario del pedido.

Encabezado obligatorio:

Idempotency-Key: UUID_GENERADO_POR_FRONTEND

Solicitud:

{
  "metodo_pago": "tarjeta_simulada",
  "simulacion": "aprobar"
}

Respuesta HTTP 200 para pago aprobado:

{
  "mensaje": "Compra realizada correctamente",
  "datos": {
    "pedido_id": 1,
    "estado": "pagado",
    "total": "1918.00",
    "pago": {
      "estado": "aprobado",
      "referencia": "PAGO-00000001"
    },
    "envio": {
      "guia": "ENV-00000001",
      "estado": "preparando"
    }
  }
}

Respuesta HTTP 200 para pago rechazado:

{
  "mensaje": "El pago fue rechazado",
  "datos": {
    "pedido_id": 1,
    "estado": "cancelado",
    "pago": {
      "estado": "rechazado",
      "referencia": "PAGO-00000002"
    },
    "envio": null
  }
}

Reglas:

- Comprobar que el pedido pertenezca al usuario.
- Comprobar que esté pendiente.
- Validar la reserva de inventario.
- Solicitar el procesamiento del pago.
- Si el pago es aprobado, confirmar inventario.
- Actualizar el pedido a pagado.
- Generar y almacenar la guía de envío.
- Vaciar el carrito.
- Si el pago es rechazado, liberar inventario,
  cancelar el pedido y conservar el carrito.

No repetir cobros ni descuentos de inventario.

Si una dependencia falla después de aprobarse el pago,
se deberá conservar el resultado del pago y permitir
reanudar las operaciones pendientes sin cobrar nuevamente.

No cancelar automáticamente un pedido cuyo pago
ya fue aprobado.

---

# 8. API de Pagos

Servicio: api-pagos
Puerto: 8007
Tecnología: Java 21 + Spring Boot
Base de datos: pagos_db
Responsable: Integrante 3

La API simulará una pasarela de pago.

No se solicitarán números de tarjetas reales,
CVV, cuentas bancarias ni datos financieros sensibles.

## 8.1. Procesar pago

POST /api/pagos/procesar

Acceso: interno.

Encabezado obligatorio:

Idempotency-Key: UUID_GENERADO_POR_FRONTEND

Solicitud:

{
  "pedido_id": 1,
  "monto": "1918.00",
  "metodo_pago": "tarjeta_simulada",
  "simulacion": "aprobar"
}

Respuesta HTTP 201:

{
  "mensaje": "Pago procesado",
  "datos": {
    "id": 1,
    "pedido_id": 1,
    "monto": "1918.00",
    "moneda": "MXN",
    "metodo_pago": "tarjeta_simulada",
    "estado": "aprobado",
    "referencia": "PAGO-00000001",
    "creado_en": "2026-11-26T20:31:00Z"
  }
}

Ejemplo de pago rechazado:

{
  "mensaje": "Pago rechazado",
  "datos": {
    "id": 2,
    "pedido_id": 2,
    "monto": "1918.00",
    "moneda": "MXN",
    "metodo_pago": "tarjeta_simulada",
    "estado": "rechazado",
    "referencia": "PAGO-00000002",
    "creado_en": "2026-11-26T20:35:00Z"
  }
}

Un pago rechazado también devolverá HTTP 201 cuando
la transacción haya sido registrada correctamente.

Métodos permitidos:

- tarjeta_simulada
- transferencia_simulada

Valores de simulacion:

- aprobar
- rechazar

El campo simulacion permitirá realizar pruebas deterministas.

Validaciones:

- Comprobar que el pedido exista.
- Consultar el total real mediante Pedidos.
- Validar que el monto coincida.
- Validar el método de pago.
- No permitir más de un pago aprobado por pedido.
- Guardar la clave de idempotencia.
- Reutilizar el resultado si se repite la misma operación.
- Si se reutiliza una clave con datos diferentes,
  devolver HTTP 409.

Errores:

- 400 MONTO_INVALIDO
- 400 METODO_PAGO_INVALIDO
- 404 PEDIDO_NO_ENCONTRADO
- 409 PEDIDO_NO_PAGABLE
- 409 IDEMPOTENCIA_CONFLICTIVA
- 503 SERVICIO_NO_DISPONIBLE

## 8.2. Consultar comprobante

GET /api/pagos/pedido/{pedido_id}

Acceso: propietario del pedido, admin o interno.

Respuesta HTTP 200:

{
  "mensaje": "Comprobante encontrado",
  "datos": {
    "id": 1,
    "pedido_id": 1,
    "monto": "1918.00",
    "moneda": "MXN",
    "metodo_pago": "tarjeta_simulada",
    "estado": "aprobado",
    "referencia": "PAGO-00000001",
    "creado_en": "2026-11-26T20:31:00Z"
  }
}

Se devolverá el pago aprobado si existe.

En caso contrario, se devolverá el intento más reciente.

La propiedad del pedido deberá verificarse mediante
la API de Pedidos.

Errores:

- 403 ACCESO_DENEGADO
- 404 PAGO_NO_ENCONTRADO

---

# 9. API de Reseñas

Servicio: api-resenas
Puerto: 8008
Tecnología: Python + FastAPI + MongoDB
Base de datos: resenas_db
Colección: resenas
Responsable: Integrante 2

## 9.1. Consultar reseñas de un producto

GET /api/resenas/producto/{producto_id}

Acceso: público.

Respuesta HTTP 200:

{
  "mensaje": "Resenas encontradas",
  "datos": {
    "producto_id": 1,
    "promedio": 4.5,
    "total_resenas": 2,
    "resenas": [
      {
        "id": "674620b12f8b4c1234567890",
        "producto_id": 1,
        "usuario_id": 1,
        "calificacion": 5,
        "comentario": "Excelente producto",
        "creado_en": "2026-11-26T20:00:00Z"
      },
      {
        "id": "674620b12f8b4c1234567891",
        "producto_id": 1,
        "usuario_id": 2,
        "calificacion": 4,
        "comentario": "Buena calidad",
        "creado_en": "2026-11-26T20:10:00Z"
      }
    ]
  }
}

Cuando no existan reseñas:

{
  "mensaje": "El producto no tiene resenas",
  "datos": {
    "producto_id": 1,
    "promedio": 0,
    "total_resenas": 0,
    "resenas": []
  }
}

El promedio será numérico y se redondeará
a un máximo de dos decimales.

## 9.2. Crear reseña

POST /api/resenas

Acceso: cliente.

Solicitud:

{
  "producto_id": 1,
  "usuario_id": 1,
  "calificacion": 5,
  "comentario": "Excelente producto"
}

Respuesta HTTP 201:

{
  "mensaje": "Resena creada correctamente",
  "datos": {
    "id": "674620b12f8b4c1234567890",
    "producto_id": 1,
    "usuario_id": 1,
    "calificacion": 5,
    "comentario": "Excelente producto",
    "creado_en": "2026-11-26T20:00:00Z"
  }
}

Validaciones:

- El producto debe existir.
- usuario_id debe coincidir con el JWT.
- Calificación entera entre 1 y 5.
- Comentario obligatorio, máximo 1000 caracteres.
- Una reseña por usuario y producto.

No se exigirá una compra previa para publicar reseñas
en esta versión académica.

Errores:

- 400 CALIFICACION_INVALIDA
- 400 DATOS_INVALIDOS
- 403 ACCESO_DENEGADO
- 404 PRODUCTO_NO_ENCONTRADO
- 409 RESENA_DUPLICADA

## 9.3. Consultar promedios de varios productos

Endpoint complementario para optimizar el catálogo.

POST /api/resenas/promedios

Acceso: público.

Solicitud:

{
  "producto_ids": [1, 2, 3]
}

Respuesta HTTP 200:

{
  "mensaje": "Promedios encontrados",
  "datos": [
    {
      "producto_id": 1,
      "promedio": 4.5,
      "total_resenas": 2
    },
    {
      "producto_id": 2,
      "promedio": 5,
      "total_resenas": 1
    },
    {
      "producto_id": 3,
      "promedio": 0,
      "total_resenas": 0
    }
  ]
}

Máximo 100 identificadores por solicitud.

---

# 10. Autenticación JWT

La API de Usuarios generará los tokens JWT.

Algoritmo propuesto: RS256.

La API de Usuarios conservará la clave privada.

Los demás microservicios utilizarán la clave pública
para verificar los tokens.

Contenido del JWT:

{
  "iss": "api-usuarios",
  "aud": "tienda-microservicios",
  "sub": "1",
  "rol": "cliente",
  "iat": 1795723200,
  "exp": 1795726800
}

Los valores iat y exp son ejemplos de fechas Unix.

Reglas:

- sub contiene el identificador del usuario como cadena.
- rol contiene cliente o admin.
- Duración del token: 3600 segundos.
- Validar firma, algoritmo, emisor, audiencia y expiración.
- No confiar únicamente en la decodificación del token.
- No incluir contraseñas ni información sensible.
- No aceptar algoritmos diferentes de RS256.

Las claves y configuración se documentarán
en AUTENTICACION.md.

---

# 11. Variables de entorno

## 11.1. PostgreSQL

Cada microservicio utilizará:

DB_HOST=postgres
DB_PORT=5432
DB_NAME=nombre_db
DB_USER=usuario_del_servicio
DB_PASSWORD=CONTRASENA_LOCAL

Las credenciales reales no se subirán a GitHub.

## 11.2. MongoDB

MONGO_URI=URI_DE_CONEXION
MONGO_DB=resenas_db

## 11.3. Comunicación entre APIs

USUARIOS_API_URL=http://api-usuarios:8001
PRODUCTOS_API_URL=http://api-productos:8002
ENVIOS_API_URL=http://api-envios:8003
INVENTARIO_API_URL=http://api-inventario:8004
CARRITO_API_URL=http://api-carrito:8005
PEDIDOS_API_URL=http://api-pedidos:8006
PAGOS_API_URL=http://api-pagos:8007
RESENAS_API_URL=http://api-resenas:8008

Cada servicio utilizará únicamente las variables necesarias.

## 11.4. Autenticación

JWT_ISSUER=api-usuarios
JWT_AUDIENCE=tienda-microservicios
JWT_TTL_SECONDS=3600
JWT_PUBLIC_KEY_PATH=/run/secrets/jwt_public.pem
JWT_PRIVATE_KEY_PATH=/run/secrets/jwt_private.pem
INTERNAL_API_TOKEN=TOKEN_INTERNO

JWT_PRIVATE_KEY_PATH solo se configurará en Usuarios.

---

# 12. Comunicación entre microservicios

| Servicio | Servicios que necesita consultar |
|---|---|
| Usuarios | Ninguno |
| Productos | Ninguno |
| Envíos | Pedidos, para notificar cambios de estado |
| Inventario | Productos |
| Carrito | Productos e Inventario |
| Pedidos | Carrito, Productos, Inventario, Envíos y Pagos |
| Pagos | Pedidos |
| Reseñas | Productos |

La validación JWT se realizará localmente mediante
la clave pública.

No será necesario consultar Usuarios en cada solicitud.

Las llamadas internas tendrán tiempos de espera limitados.

Las operaciones que modifiquen datos solo se reintentarán
cuando sean idempotentes o utilicen una clave de idempotencia.

No se mantendrán transacciones de base de datos abiertas
mientras se espera una respuesta HTTP de otro microservicio.

---

# 13. Integración con Angular

El frontend utilizará rutas relativas:

/api/usuarios
/api/productos
/api/envios
/api/inventario
/api/carrito
/api/pedidos
/api/pagos
/api/resenas

Un proxy HTTP enviará cada solicitud al microservicio
correspondiente.

El navegador no utilizará los nombres DNS internos
de Docker o Kubernetes.

Angular utilizará un interceptor HTTP para agregar
el encabezado Authorization en solicitudes protegidas.

El token se conservará preferentemente en memoria.

Al recargar la aplicación, el usuario deberá autenticarse
nuevamente si el token ya no está disponible.

No se almacenarán claves privadas, credenciales internas
ni contraseñas de bases de datos en el frontend.

---

# 14. Flujo de compra

1. El usuario se registra o inicia sesión.
2. Angular recibe el token JWT.
3. Consulta Productos.
4. Consulta disponibilidad en Inventario.
5. Consulta calificaciones y comentarios en Reseñas.
6. Agrega productos al Carrito.
7. Consulta el resumen del Carrito.
8. Cotiza el envío.
9. Crea un pedido pendiente.
10. Pedidos reserva el inventario.
11. Angular solicita el checkout a Pedidos.
12. Pedidos solicita el pago a Pagos.

Si el pago es aprobado:

13. Confirmar las reservas de inventario.
14. Actualizar el pedido a pagado.
15. Generar la guía de envío.
16. Guardar la guía en Pedidos.
17. Vaciar el carrito.
18. Devolver la confirmación de compra.

Si el pago es rechazado:

13. Liberar las reservas.
14. Cancelar el pedido.
15. Conservar el carrito.
16. Informar al usuario del rechazo.

Angular podrá consultar posteriormente:

- Historial de pedidos.
- Detalle de pedidos.
- Comprobantes de pago.
- Estado del envío.

---

# 15. Datos iniciales para integración

Todos los integrantes utilizarán identificadores compatibles.

Categorías iniciales:

| ID | Nombre |
|---|---|
| 1 | Computacion |
| 2 | Audio |
| 3 | Accesorios |

Productos iniciales:

| ID | Producto | Precio MXN | Categoría | Stock |
|---|---|---|---|---|
| 1 | Teclado mecanico | 899.00 | 1 | 20 |
| 2 | Mouse inalambrico | 399.00 | 1 | 30 |
| 3 | Monitor 24 pulgadas | 2999.00 | 1 | 10 |
| 4 | Audifonos Bluetooth | 699.00 | 2 | 25 |
| 5 | Bocina portatil | 999.00 | 2 | 15 |
| 6 | Cable USB-C | 149.00 | 3 | 50 |
| 7 | Cargador USB-C | 349.00 | 3 | 35 |
| 8 | Webcam HD | 799.00 | 1 | 12 |
| 9 | Microfono USB | 1199.00 | 2 | 8 |
| 10 | Hub USB | 499.00 | 3 | 18 |

Se podrán agregar más productos conservando
los identificadores iniciales.

Productos e Inventario deberán coordinar sus datos semilla.

Las secuencias de PostgreSQL deberán ajustarse después
de insertar identificadores explícitos.

---

# 16. Responsabilidades de desarrollo

Cada integrante deberá entregar:

- Código fuente de sus servicios.
- Dockerfile por servicio.
- Archivo de dependencias.
- Configuración mediante variables de entorno.
- Scripts de creación de tablas o migraciones.
- Endpoints definidos en este documento.
- Validaciones de entrada.
- Autenticación y autorización correspondientes.
- Endpoints /health y /ready.
- Pruebas individuales.
- Evidencias de funcionamiento.
- README de cada servicio.

No modificar unilateralmente los contratos de API.

Cualquier cambio deberá ser comunicado al equipo
y actualizado en este documento antes de integrarse.

---

# 17. Consideraciones de implementación

Los endpoints adicionales permiten completar el flujo
de integración y mejorar la seguridad y consistencia.

La coordinación entre servicios utilizará solicitudes
HTTP síncronas y operaciones compensatorias.

No existe una transacción global entre todas las bases
de datos.

Por ello, las operaciones de compra deberán registrar
su progreso y permitir recuperarse ante fallos parciales.

Antes de implementar el checkout definitivo se deberán
agregar al modelo de datos los campos necesarios para:

- Claves de idempotencia.
- Seguimiento del progreso de checkout.
- Recuperación de operaciones pendientes.
- Control de expiración de reservas.

Estos ajustes serán coordinados por el responsable
de arquitectura y comunicados a los integrantes.

La aplicación es una implementación académica.
No representa una plataforma de comercio electrónico
preparada para procesar pagos reales en producción.


# 18. Ampliación del contrato de Inventario

## 18.1. Iniciar procesamiento de pago

POST /api/inventario/proteger-reserva

Acceso: interno.

Solicitud:

{
  "pedido_id": 1
}

Respuesta HTTP 200:

{
  "mensaje": "Reserva protegida correctamente",
  "datos": {
    "pedido_id": 1,
    "estado": "procesando_pago"
  }
}

Reglas:

- Comprobar que todas las reservas del pedido existan.
- Comprobar que las reservas pendientes no hayan expirado.
- Cambiar su estado a procesando_pago.
- Ejecutar la operación dentro de una transacción.
- No modificar stock_total ni stock_reservado.
- Si ya se encuentra en procesando_pago,
  devolver HTTP 200 sin repetir efectos.
- No permitir la operación sobre reservas liberadas,
  confirmadas o expiradas.

Errores:

- 404 RESERVA_NO_ENCONTRADA
- 409 RESERVA_EXPIRADA
- 409 RESERVA_NO_PROTEGIBLE

Pedidos deberá llamar a este endpoint antes de
solicitar el procesamiento del pago.

## 18.2. Actualización de confirmar y liberar

POST /api/inventario/confirmar

Permitirá confirmar reservas pendientes vigentes
o reservas en estado procesando_pago.

POST /api/inventario/liberar

Permitirá liberar reservas pendientes o reservas
en estado procesando_pago cuando el coordinador
haya comprobado que no existe un pago aprobado.

## 18.3. Actualización del checkout

POST /api/pedidos/{id}/checkout

Antes de solicitar el pago, Pedidos deberá
proteger la reserva mediante el nuevo endpoint.

Cuando se repita una clave de idempotencia,
se recuperará el progreso existente.

No se iniciará un segundo checkout para
un pedido que ya tenga uno registrado.

Si se reutiliza una clave con datos diferentes,
se devolverá HTTP 409.

Si una operación está incompleta por un fallo
temporal, se devolverá HTTP 503 y el cliente
podrá reintentar con la misma clave.

Las operaciones completadas devolverán
su resultado previamente registrado.