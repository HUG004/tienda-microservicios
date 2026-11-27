CREATE TABLE IF NOT EXISTS carritos (
    id BIGSERIAL PRIMARY KEY,
    usuario_id BIGINT UNIQUE NOT NULL,
    creado_en TIMESTAMPTZ DEFAULT NOW(),
    actualizado_en TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS carrito_detalles (
    id BIGSERIAL PRIMARY KEY,
    carrito_id BIGINT REFERENCES carritos(id) ON DELETE CASCADE,
    producto_id BIGINT NOT NULL,
    cantidad INTEGER NOT NULL CHECK (cantidad > 0),
    UNIQUE(carrito_id, producto_id)
);