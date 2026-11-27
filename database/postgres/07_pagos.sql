CREATE TABLE IF NOT EXISTS pagos (
    id BIGSERIAL PRIMARY KEY,
    pedido_id BIGINT NOT NULL,
    monto NUMERIC(12,2) NOT NULL,
    metodo_pago VARCHAR(30) NOT NULL,
    estado VARCHAR(20) NOT NULL,
    referencia VARCHAR(100) UNIQUE NOT NULL,
    clave_idempotencia VARCHAR(100) UNIQUE NOT NULL,
    solicitud_hash VARCHAR(64) NOT NULL,
    creado_en TIMESTAMPTZ DEFAULT NOW()
);

-- Índice parcial para evitar dos pagos aprobados para el mismo pedido
CREATE UNIQUE INDEX idx_un_pago_aprobado ON pagos (pedido_id) WHERE estado = 'aprobado';