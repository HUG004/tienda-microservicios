BEGIN;

-- SEGUIMIENTO DE OPERACIONES DE CHECKOUT

CREATE TABLE IF NOT EXISTS checkout_operaciones (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,

    pedido_id BIGINT NOT NULL,

    clave_idempotencia VARCHAR(100) NOT NULL,

    solicitud_hash VARCHAR(64) NOT NULL,

    estado VARCHAR(40) NOT NULL DEFAULT 'iniciado',

    pago_id BIGINT,

    pago_estado VARCHAR(20),

    pago_referencia VARCHAR(100),

    inventario_confirmado BOOLEAN NOT NULL DEFAULT FALSE,

    inventario_liberado BOOLEAN NOT NULL DEFAULT FALSE,

    envio_generado BOOLEAN NOT NULL DEFAULT FALSE,

    carrito_vaciado BOOLEAN NOT NULL DEFAULT FALSE,

    ultimo_error TEXT,

    intentos INTEGER NOT NULL DEFAULT 0,

    creado_en TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    actualizado_en TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    completado_en TIMESTAMPTZ,

    CONSTRAINT fk_checkout_pedido
        FOREIGN KEY (pedido_id)
        REFERENCES pedidos(id)
        ON DELETE RESTRICT,

    CONSTRAINT uq_checkout_pedido
        UNIQUE (pedido_id),

    CONSTRAINT uq_checkout_idempotencia
        UNIQUE (clave_idempotencia),

    CONSTRAINT chk_checkout_clave
        CHECK (LENGTH(TRIM(clave_idempotencia)) > 0),

    CONSTRAINT chk_checkout_hash
        CHECK (solicitud_hash ~ '^[a-f0-9]{64}$'),

    CONSTRAINT chk_checkout_estado
        CHECK (
            estado IN (
                'iniciado',
                'pago_en_proceso',
                'pago_aprobado',
                'pago_rechazado',
                'inventario_confirmado',
                'pedido_pagado',
                'envio_generado',
                'carrito_vaciado',
                'completado',
                'compensacion_pendiente'
            )
        ),

    CONSTRAINT chk_checkout_pago_estado
        CHECK (
            pago_estado IS NULL
            OR pago_estado IN (
                'aprobado',
                'rechazado'
            )
        ),

    CONSTRAINT chk_checkout_pago_id
        CHECK (
            pago_id IS NULL
            OR pago_id > 0
        ),

    CONSTRAINT chk_checkout_intentos
        CHECK (intentos >= 0),

    CONSTRAINT chk_checkout_inventario
        CHECK (
            NOT (
                inventario_confirmado
                AND inventario_liberado
            )
        ),

    CONSTRAINT chk_checkout_completado
        CHECK (
            estado <> 'completado'
            OR completado_en IS NOT NULL
        )
);

-- INDICES

CREATE INDEX IF NOT EXISTS idx_checkout_estado
    ON checkout_operaciones(estado);

CREATE INDEX IF NOT EXISTS idx_checkout_actualizado
    ON checkout_operaciones(actualizado_en);

-- ACTUALIZACION AUTOMATICA DE FECHA


DROP TRIGGER IF EXISTS trg_actualizar_checkout
ON checkout_operaciones;

CREATE TRIGGER trg_actualizar_checkout
BEFORE UPDATE ON checkout_operaciones
FOR EACH ROW
EXECUTE FUNCTION actualizar_fecha_pedido();

COMMIT;