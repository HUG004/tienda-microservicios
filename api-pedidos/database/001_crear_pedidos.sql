BEGIN;


-- TABLA PEDIDOS


CREATE TABLE IF NOT EXISTS pedidos (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,

    usuario_id BIGINT NOT NULL,

    subtotal NUMERIC(12,2) NOT NULL,

    costo_envio NUMERIC(12,2) NOT NULL,

    total NUMERIC(12,2) NOT NULL,

    direccion TEXT NOT NULL,

    codigo_postal VARCHAR(5) NOT NULL,

    estado VARCHAR(20) NOT NULL DEFAULT 'pendiente',

    guia VARCHAR(50),

    creado_en TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    actualizado_en TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT chk_pedido_usuario
        CHECK (usuario_id > 0),

    CONSTRAINT chk_pedido_subtotal
        CHECK (subtotal >= 0),

    CONSTRAINT chk_pedido_envio
        CHECK (costo_envio >= 0),

    CONSTRAINT chk_pedido_total
        CHECK (total = subtotal + costo_envio),

    CONSTRAINT chk_pedido_direccion
        CHECK (LENGTH(TRIM(direccion)) > 0),

    CONSTRAINT chk_pedido_codigo_postal
        CHECK (codigo_postal ~ '^[0-9]{5}$'),

    CONSTRAINT chk_pedido_estado
        CHECK (
            estado IN (
                'pendiente',
                'pagado',
                'enviado',
                'cancelado'
            )
        ),

    CONSTRAINT uq_pedido_guia
        UNIQUE (guia)
);

-- TABLA DETALLES DEL PEDIDO

CREATE TABLE IF NOT EXISTS pedido_detalles (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,

    pedido_id BIGINT NOT NULL,

    producto_id BIGINT NOT NULL,

    nombre_producto VARCHAR(150) NOT NULL,

    cantidad INTEGER NOT NULL,

    precio_unitario NUMERIC(12,2) NOT NULL,

    subtotal NUMERIC(12,2) NOT NULL,

    CONSTRAINT fk_detalle_pedido
        FOREIGN KEY (pedido_id)
        REFERENCES pedidos(id)
        ON DELETE CASCADE,

    CONSTRAINT uq_detalle_producto
        UNIQUE (pedido_id, producto_id),

    CONSTRAINT chk_detalle_producto
        CHECK (producto_id > 0),

    CONSTRAINT chk_detalle_nombre
        CHECK (LENGTH(TRIM(nombre_producto)) > 0),

    CONSTRAINT chk_detalle_cantidad
        CHECK (cantidad > 0),

    CONSTRAINT chk_detalle_precio
        CHECK (precio_unitario >= 0),

    CONSTRAINT chk_detalle_subtotal
        CHECK (subtotal = cantidad * precio_unitario)
);


-- INDICES

CREATE INDEX IF NOT EXISTS idx_pedidos_usuario
    ON pedidos(usuario_id, creado_en DESC);

CREATE INDEX IF NOT EXISTS idx_pedidos_estado
    ON pedidos(estado);

CREATE INDEX IF NOT EXISTS idx_detalles_producto
    ON pedido_detalles(producto_id);

-- ACTUALIZACION AUTOMATICA DE FECHA


CREATE OR REPLACE FUNCTION actualizar_fecha_pedido()
RETURNS TRIGGER AS $$
BEGIN
    NEW.actualizado_en = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_actualizar_pedido
ON pedidos;

CREATE TRIGGER trg_actualizar_pedido
BEFORE UPDATE ON pedidos
FOR EACH ROW
EXECUTE FUNCTION actualizar_fecha_pedido();

COMMIT;