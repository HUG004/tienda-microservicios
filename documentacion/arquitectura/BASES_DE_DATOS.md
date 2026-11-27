# Diseño de Bases de Datos

## 1. Estrategia de almacenamiento

El proyecto utilizará una instancia de PostgreSQL 16 y una
instancia de MongoDB 8.

Cada microservicio tendrá una base de datos independiente
y será responsable de sus propios datos.

No existirán claves foráneas entre bases de datos diferentes.

## 2. Usuarios

Base de datos: usuarios_db

Tabla usuarios:
- id: BIGINT, PK, identidad.
- nombre: VARCHAR(150), NOT NULL.
- correo: VARCHAR(255), UNIQUE, NOT NULL.
- contrasena_hash: VARCHAR(255), NOT NULL.
- rol: VARCHAR(20), cliente o admin.
- activo: BOOLEAN, DEFAULT TRUE.
- creado_en: TIMESTAMPTZ.
- actualizado_en: TIMESTAMPTZ.

## 3. Productos

Base de datos: productos_db

Tabla categorias:
- id: BIGINT, PK, identidad.
- nombre: VARCHAR(100), UNIQUE.
- descripcion: TEXT.

Tabla productos:
- id: BIGINT, PK, identidad.
- categoria_id: BIGINT, FK categorias.
- nombre: VARCHAR(150).
- descripcion: TEXT.
- precio: NUMERIC(12,2).
- imagen_url: TEXT.
- activo: BOOLEAN.
- creado_en: TIMESTAMPTZ.
- actualizado_en: TIMESTAMPTZ.

## 4. Envíos

Base de datos: envios_db

Tabla envios:
- id: BIGINT, PK, identidad.
- pedido_id: BIGINT, UNIQUE.
- guia: VARCHAR(50), UNIQUE.
- codigo_postal: VARCHAR(5).
- direccion: TEXT.
- peso: NUMERIC(10,3).
- costo_envio: NUMERIC(12,2).
- dias_estimados: INTEGER.
- estado: VARCHAR(20).
- creado_en: TIMESTAMPTZ.
- actualizado_en: TIMESTAMPTZ.

Estados:
preparando, en_transito, entregado.

## 5. Inventario

Base de datos: inventario_db

Tabla inventario:
- producto_id: BIGINT, PK.
- stock_total: INTEGER.
- stock_reservado: INTEGER.
- actualizado_en: TIMESTAMPTZ.

Tabla reservas:
- id: BIGINT, PK, identidad.
- pedido_id: BIGINT.
- producto_id: BIGINT, FK inventario.
- cantidad: INTEGER.
- estado: VARCHAR(20).
- creado_en: TIMESTAMPTZ.
- expira_en: TIMESTAMPTZ.

Estados de reserva:
pendiente, confirmada, liberada, expirada.

Restricciones:
- stock_total >= 0.
- stock_reservado >= 0.
- stock_reservado <= stock_total.
- cantidad > 0.
- UNIQUE(pedido_id, producto_id).

Stock disponible = stock_total - stock_reservado.

Las operaciones de reserva, confirmación y liberación
deberán ejecutarse mediante transacciones atómicas.

## 6. Carrito

Base de datos: carrito_db

Tabla carritos:
- id: BIGINT, PK, identidad.
- usuario_id: BIGINT, UNIQUE.
- creado_en: TIMESTAMPTZ.
- actualizado_en: TIMESTAMPTZ.

Tabla carrito_detalles:
- id: BIGINT, PK, identidad.
- carrito_id: BIGINT, FK carritos.
- producto_id: BIGINT.
- cantidad: INTEGER.

Restricciones:
- UNIQUE(carrito_id, producto_id).
- cantidad > 0.

## 7. Pedidos

Base de datos: pedidos_db

Tabla pedidos:
- id: BIGINT, PK, identidad.
- usuario_id: BIGINT.
- subtotal: NUMERIC(12,2).
- costo_envio: NUMERIC(12,2).
- total: NUMERIC(12,2).
- direccion: TEXT.
- codigo_postal: VARCHAR(5).
- estado: VARCHAR(20).
- guia: VARCHAR(50), NULL.
- creado_en: TIMESTAMPTZ.
- actualizado_en: TIMESTAMPTZ.

Tabla pedido_detalles:
- id: BIGINT, PK, identidad.
- pedido_id: BIGINT, FK pedidos.
- producto_id: BIGINT.
- nombre_producto: VARCHAR(150).
- cantidad: INTEGER.
- precio_unitario: NUMERIC(12,2).
- subtotal: NUMERIC(12,2).

Estados:
pendiente, pagado, enviado, cancelado.

El total será calculado por el backend.

Los precios y nombres de los productos se conservarán
como información histórica del pedido.

## 8. Pagos

Base de datos: pagos_db

Tabla pagos:
- id: BIGINT, PK, identidad.
- pedido_id: BIGINT.
- monto: NUMERIC(12,2).
- metodo_pago: VARCHAR(30).
- estado: VARCHAR(20).
- referencia: VARCHAR(100), UNIQUE.
- creado_en: TIMESTAMPTZ.

Estados:
aprobado, rechazado.

Solo podrá existir un pago aprobado por pedido.
Las operaciones deberán ser idempotentes.

## 9. Reseñas

Base de datos: resenas_db
Motor: MongoDB
Colección: resenas

Campos:
- _id: ObjectId.
- producto_id: entero.
- usuario_id: entero.
- calificacion: entero entre 1 y 5.
- comentario: string.
- creado_en: BSON Date.

Se creará un índice por producto_id para facilitar
las consultas de reseñas.

Se permitirá una reseña por usuario y producto,
mediante un índice compuesto único.

## 10. Restricciones generales

- Los identificadores internos serán generados por la BD.
- Las cantidades deberán ser positivas.
- Los importes monetarios no podrán ser negativos.
- Los campos obligatorios utilizarán NOT NULL.
- Las fechas utilizarán UTC.
- Los importes utilizarán precisión decimal.
- Los códigos postales serán cadenas de cinco caracteres.
- Las credenciales serán independientes por servicio.
- Cada integrante implementará las tablas y restricciones
  correspondientes a sus microservicios.

  ## 11. Ampliación para integración distribuida

### 11.1. Tabla pagos

Agregar los siguientes campos:

- clave_idempotencia: VARCHAR(100), UNIQUE, NOT NULL.
- solicitud_hash: VARCHAR(64), NOT NULL.

Crear un índice único parcial sobre pedido_id
cuando estado sea aprobado.

Esto impedirá que un mismo pedido tenga
dos pagos aprobados.

### 11.2. Base de datos pedidos_db

Agregar la tabla checkout_operaciones:

- id: BIGINT, PK, identidad.
- pedido_id: BIGINT, FK pedidos, UNIQUE.
- clave_idempotencia: VARCHAR(100), UNIQUE.
- solicitud_hash: VARCHAR(64), NOT NULL.
- estado: VARCHAR(40), NOT NULL.
- pago_id: BIGINT, NULL.
- pago_estado: VARCHAR(20), NULL.
- pago_referencia: VARCHAR(100), NULL.
- inventario_confirmado: BOOLEAN, DEFAULT FALSE.
- inventario_liberado: BOOLEAN, DEFAULT FALSE.
- envio_generado: BOOLEAN, DEFAULT FALSE.
- carrito_vaciado: BOOLEAN, DEFAULT FALSE.
- ultimo_error: TEXT, NULL.
- intentos: INTEGER, DEFAULT 0.
- creado_en: TIMESTAMPTZ.
- actualizado_en: TIMESTAMPTZ.
- completado_en: TIMESTAMPTZ, NULL.

La tabla permitirá conservar el progreso
de las compras y recuperar operaciones
interrumpidas.

### 11.3. Tabla reservas

Agregar el estado:

procesando_pago

Estados actualizados:

- pendiente
- procesando_pago
- confirmada
- liberada
- expirada

Las reservas en estado procesando_pago
no se liberarán automáticamente por expiración.

El coordinador deberá comprobar el resultado
del pago antes de confirmar o liberar
estas reservas.

### 11.4. Concurrencia

Las operaciones de inventario deberán utilizar
transacciones y bloqueo de filas.

Pedidos deberá utilizar un mecanismo persistente
para impedir ejecuciones concurrentes del checkout.

No se mantendrán transacciones abiertas mientras
se realizan solicitudes HTTP a otros servicios.

### 11.5. Recuperación

Los procesos de recuperación deberán consultar
el estado persistido de las operaciones.

No se utilizará únicamente memoria local
para controlar compras en proceso.

Esto permitirá recuperar operaciones después
del reinicio de un contenedor o Pod.