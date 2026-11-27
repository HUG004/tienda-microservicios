package com.tienda.pagos;

import org.springframework.web.bind.annotation.*;
import org.springframework.http.ResponseEntity;
import org.springframework.http.HttpStatus;
import java.util.Map;

@RestController
public class PagoController {

    // 1. Health Checks (en la raíz, sin prefijo)
    @GetMapping("/health")
    public ResponseEntity<?> health() {
        return ResponseEntity.ok(Map.of("status", "ok", "servicio", "api-pagos"));
    }

    @GetMapping("/ready")
    public ResponseEntity<?> ready() {
        // En un caso real verificarías la BD aquí. Por ahora responde ready.
        return ResponseEntity.ok(Map.of("status", "ready", "servicio", "api-pagos"));
    }

    // 2. Endpoint de Procesar Pago
    @PostMapping("/api/pagos/procesar")
    public ResponseEntity<?> procesarPago(
            @RequestHeader(value = "X-Internal-Token", required = false) String internalToken,
            @RequestHeader(value = "Idempotency-Key", required = false) String idempotencyKey,
            @RequestBody Map<String, Object> request) {
        
        // 1. Validar token interno (simplificado)
        String expectedToken = System.getenv("INTERNAL_API_TOKEN");
        if (internalToken == null || !internalToken.equals(expectedToken)) {
            return ResponseEntity.status(HttpStatus.FORBIDDEN).body(Map.of("error", Map.of("codigo", "ACCESO_DENEGADO", "mensaje", "Token interno requerido")));
        }
        
        if (idempotencyKey == null) {
            return ResponseEntity.badRequest().body(Map.of("error", Map.of("codigo", "DATOS_INVALIDOS", "mensaje", "Falta Idempotency-Key")));
        }

        // 2. Lógica de Simulación
        String simulacion = (String) request.getOrDefault("simulacion", "aprobar");
        String estado = simulacion.equals("aprobar") ? "aprobado" : "rechazado";
        String referencia = "PAGO-" + String.format("%08d", (int)(Math.random() * 100000000));
        
        // TODO: Guardar en Base de Datos mediante JpaRepository (PagoEntity) para el siguiente paso

        return ResponseEntity.status(HttpStatus.CREATED).body(Map.of(
            "mensaje", estado.equals("aprobado") ? "Pago procesado" : "Pago rechazado",
            "datos", Map.of(
                "id", 1,
                "pedido_id", request.get("pedido_id"),
                "monto", request.get("monto"),
                "moneda", "MXN",
                "metodo_pago", request.get("metodo_pago"),
                "estado", estado,
                "referencia", referencia,
                "creado_en", "2026-11-26T20:31:00Z"
            )
        ));
    }
}