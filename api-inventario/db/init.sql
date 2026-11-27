CREATE TABLE IF NOT EXISTS inventario (
    producto_id BIGINT PRIMARY KEY,
    stock_total INTEGER NOT NULL DEFAULT 0 CHECK (stock_total >= 0),
    stock_reservado INTEGER NOT NULL DEFAULT 0 CHECK (stock_reservado >= 0),
    actualizado_en TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CHECK (stock_reservado <= stock_total)
);

CREATE TABLE IF NOT EXISTS reservas (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    pedido_id BIGINT NOT NULL,
    producto_id BIGINT NOT NULL REFERENCES inventario(producto_id),
    cantidad INTEGER NOT NULL CHECK (cantidad > 0),
    estado VARCHAR(20) NOT NULL DEFAULT 'pendiente'
        CHECK (estado IN ('pendiente', 'confirmada', 'liberada', 'expirada')),
    creado_en TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expira_en TIMESTAMPTZ NOT NULL,
    UNIQUE (pedido_id, producto_id)
);

INSERT INTO inventario (producto_id, stock_total) VALUES
    (1, 20), (2, 30), (3, 10), (4, 25), (5, 15),
    (6, 50), (7, 35), (8, 12), (9, 8), (10, 18)
ON CONFLICT (producto_id) DO NOTHING;