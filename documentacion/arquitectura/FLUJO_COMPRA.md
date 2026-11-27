# Flujo de Compra Distribuido

## 1. Objetivo

Coordinar las operaciones de compra entre los
microservicios de manera consistente.

La API de Pedidos actuará como coordinador
del proceso de checkout.

Se utilizará comunicación HTTP síncrona
y operaciones compensatorias.

Este enfoque está basado en el patrón Saga
mediante orquestación.

## 2. Consulta del catálogo

Angular consulta:

GET /api/productos
GET /api/inventario/{producto_id}
GET /api/resenas/producto/{producto_id}

El usuario podrá consultar precios, existencias
y comentarios.

## 3. Carrito

Angular solicita:

POST /api/carrito/{usuario_id}/agregar

Carrito comprueba el producto y la disponibilidad.

Agregar productos al carrito no reserva existencias.

## 4. Cotización

Angular solicita:

POST /api/envios/cotizar

Envíos devuelve el costo y tiempo estimado.

La cotización mostrada en el frontend es informativa.

Pedidos calculará nuevamente el costo definitivo.

## 5. Creación del pedido

Angular solicita:

POST /api/pedidos

Pedidos realiza:

1. Validar la identidad del usuario.
2. Consultar Carrito.
3. Consultar precios vigentes.
4. Cotizar el envío.
5. Calcular los importes.
6. Registrar el pedido pendiente.
7. Registrar los detalles.
8. Solicitar la reserva de inventario.

La reserva tendrá una duración inicial de 15 minutos.

Si no existe stock suficiente, el pedido se cancela.

## 6. Checkout

Angular solicita:

POST /api/pedidos/{id}/checkout

Encabezado:

Idempotency-Key: UUID

Pedidos registrará la clave de idempotencia
y el progreso de la operación.

Antes de solicitar el pago, deberá comprobar
que la reserva siga vigente.

También deberá bloquear la expiración de la
reserva mientras exista un pago en proceso.

No se permitirán dos procesos de checkout
simultáneos para el mismo pedido.

## 7. Pago aprobado

Pedidos solicita:

POST /api/pagos/procesar

Pagos registra la transacción aprobada.

Pedidos realiza las siguientes operaciones:

1. Registrar el resultado aprobado.
2. Confirmar la reserva de inventario.
3. Actualizar el pedido a pagado.
4. Solicitar la generación del envío.
5. Almacenar el número de guía.
6. Vaciar el carrito.
7. Registrar el checkout como completado.
8. Devolver la confirmación al frontend.

La confirmación de inventario deberá ser idempotente.

La generación de envío deberá ser idempotente.

## 8. Pago rechazado

Si Pagos devuelve una transacción rechazada:

1. Registrar el resultado rechazado.
2. Liberar la reserva de inventario.
3. Actualizar el pedido a cancelado.
4. Conservar el carrito.
5. Registrar el checkout como completado.
6. Informar al usuario.

## 9. Expiración de reservas

Las reservas pendientes tendrán una fecha de expiración.

Si el usuario no inicia el checkout dentro del
periodo permitido, se liberará el inventario.

Una reserva asociada a un pago en proceso no
deberá liberarse automáticamente.

Si el resultado del pago es desconocido, Pedidos
deberá consultar Pagos antes de liberar inventario.

Una reserva con pago aprobado no deberá expirar.

## 10. Recuperación ante fallos

No existe una transacción global entre todas
las bases de datos.

Cada operación deberá registrar su progreso.

Si una solicitud HTTP falla durante el procesamiento
del pago, no se asumirá automáticamente que el pago
fue rechazado.

Se consultará el resultado mediante:

GET /api/pagos/pedido/{pedido_id}

También se podrá repetir la solicitud utilizando
la misma clave de idempotencia.

Si el pago fue aprobado, se deberán completar
las operaciones pendientes sin realizar otro cobro.

Si el pago fue rechazado, se deberán ejecutar
las operaciones compensatorias correspondientes.

## 11. Estados internos del checkout

- iniciado
- pago_en_proceso
- pago_aprobado
- pago_rechazado
- inventario_confirmado
- pedido_pagado
- envio_generado
- carrito_vaciado
- completado
- compensacion_pendiente

Estos estados serán independientes de los estados
comerciales del pedido.

El estado comercial continuará siendo:

- pendiente
- pagado
- enviado
- cancelado

Los errores temporales no eliminarán el progreso
registrado del checkout.

## 12. Idempotencia

Las operaciones críticas deberán evitar efectos
duplicados ante solicitudes repetidas.

Pagos:
- Clave de idempotencia única.
- Un único pago aprobado por pedido.

Inventario:
- Una reserva por pedido y producto.
- Confirmación sin descuentos duplicados.
- Liberación sin incrementos duplicados.

Envíos:
- Una guía única por pedido.

Pedidos:
- Control de checkout por pedido.
- Persistencia del progreso de la operación.

## 13. Historial y seguimiento

Angular consultará:

GET /api/pedidos/usuario/{usuario_id}
GET /api/pedidos/{id}
GET /api/pagos/pedido/{pedido_id}
GET /api/envios/rastreo/{guia}

El usuario podrá visualizar sus pedidos,
comprobantes y estados de envío.

## 14. Casos de prueba

- Compra con pago aprobado.
- Compra con pago rechazado.
- Producto sin stock.
- Carrito vacío.
- Reserva expirada.
- Dos usuarios comprando las últimas existencias.
- Solicitud de pago duplicada.
- Fallo temporal de Pagos.
- Fallo temporal de Envíos.
- Recuperación de un checkout incompleto.

## 15. Alcance

El proyecto implementará una coordinación
distribuida con persistencia de progreso,
idempotencia y operaciones compensatorias.

No se utilizarán transacciones bancarias reales.

No se implementarán reembolsos en esta versión.