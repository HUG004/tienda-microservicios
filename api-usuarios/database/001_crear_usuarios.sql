BEGIN;

CREATE TABLE IF NOT EXISTS usuarios (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,

    nombre VARCHAR(150) NOT NULL,

    correo VARCHAR(255) NOT NULL UNIQUE,

    contrasena_hash VARCHAR(255) NOT NULL,

    rol VARCHAR(20) NOT NULL DEFAULT 'cliente',

    activo BOOLEAN NOT NULL DEFAULT TRUE,

    creado_en TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    actualizado_en TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT chk_usuarios_rol
        CHECK (rol IN ('cliente', 'admin')),

    CONSTRAINT chk_usuarios_nombre
        CHECK (CHAR_LENGTH(TRIM(nombre)) > 0),

    CONSTRAINT chk_usuarios_correo
        CHECK (
            correo = LOWER(TRIM(correo))
            AND CHAR_LENGTH(correo) > 0
        )
);

CREATE OR REPLACE FUNCTION actualizar_fecha_usuario()
RETURNS TRIGGER AS $$
BEGIN
    NEW.actualizado_en = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_actualizar_usuario ON usuarios;

CREATE TRIGGER trg_actualizar_usuario
BEFORE UPDATE ON usuarios
FOR EACH ROW
EXECUTE FUNCTION actualizar_fecha_usuario();

COMMIT;