\c envios_db;

CREATE TABLE IF NOT EXISTS envios (
    id BIGSERIAL PRIMARY KEY,
    pedido_id BIGINT UNIQUE NOT NULL,
    guia VARCHAR(50) UNIQUE NOT NULL,
    codigo_postal VARCHAR(5) NOT NULL,
    direccion TEXT NOT NULL,
    peso NUMERIC(10,3) NOT NULL,
    costo_envio NUMERIC(12,2) NOT NULL,
    dias_estimados INTEGER NOT NULL,
    estado VARCHAR(20) DEFAULT 'preparando',
    creado_en TIMESTAMPTZ DEFAULT NOW(),
    actualizado_en TIMESTAMPTZ DEFAULT NOW()
);

-- Otorgar permisos al usuario de la aplicación
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO envios_app;
GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO envios_app;