package com.tienda.pagos;

import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.web.bind.annotation.*;
import org.springframework.http.ResponseEntity;
import org.springframework.http.HttpStatus;
import java.math.BigDecimal;
import java.util.Map;
import java.util.Optional;

@RestController
public class PagoController {

    @Autowired
    private PagoRepository pagoRepository;

    @GetMapping("/health")
    public ResponseEntity<?> health() { return ResponseEntity.ok(Map.of("status", "ok", "servicio", "api-pagos")); }

    @GetMapping("/ready")
    public ResponseEntity<?> ready() { return ResponseEntity.ok(Map.of("status", "ready", "servicio", "api-pagos")); }

    @PostMapping("/api/pagos/procesar")
    public ResponseEntity<?> procesarPago(
            @RequestHeader(value = "X-Internal-Token", required = false) String internalToken,
            @RequestHeader(value = "Idempotency-Key", required = false) String idempotencyKey,
            @RequestBody Map<String, Object> request) {
        
        String expectedToken = System.getenv("INTERNAL_API_TOKEN");
        if (internalToken == null || !internalToken.equals(expectedToken)) {
            return ResponseEntity.status(HttpStatus.FORBIDDEN).body(Map.of("error", Map.of("codigo", "ACCESO_DENEGADO", "mensaje", "Token interno requerido")));
        }
        if (idempotencyKey == null) {
            return ResponseEntity.badRequest().body(Map.of("error", Map.of("codigo", "DATOS_INVALIDOS", "mensaje", "Falta Idempotency-Key")));
        }

        // Idempotencia (evitar cobrar dos veces)
        Optional<Pago> pagoExistente = pagoRepository.findByPedidoIdAndEstado(Long.parseLong(request.get("pedido_id").toString()), "aprobado");
        if (pagoExistente.isPresent()) {
            Pago p = pagoExistente.get();
            return ResponseEntity.ok(Map.of("mensaje", "Pago ya procesado", "datos", Map.of("id", p.getId(), "estado", p.getEstado(), "referencia", p.getReferencia())));
        }

        String simulacion = (String) request.getOrDefault("simulacion", "aprobar");
        String estado = simulacion.equals("aprobar") ? "aprobado" : "rechazado";
        String referencia = "PAGO-" + String.format("%08d", (int)(Math.random() * 100000000));

        // Guardar en BD
        Pago nuevoPago = new Pago();
        nuevoPago.setPedidoId(Long.parseLong(request.get("pedido_id").toString()));
        nuevoPago.setMonto(new BigDecimal(request.get("monto").toString()));
        nuevoPago.setMetodoPago(request.get("metodo_pago").toString());
        nuevoPago.setEstado(estado);
        nuevoPago.setReferencia(referencia);
        nuevoPago.setClaveIdempotencia(idempotencyKey);
        nuevoPago.setSolicitudHash("hash_simulado_123"); // En producción se calcula un SHA-256

        pagoRepository.save(nuevoPago);

        return ResponseEntity.status(HttpStatus.CREATED).body(Map.of(
            "mensaje", estado.equals("aprobado") ? "Pago procesado" : "Pago rechazado",
            "datos", Map.of(
                "id", nuevoPago.getId(), "pedido_id", nuevoPago.getPedidoId(), "monto", nuevoPago.getMonto().toString(),
                "moneda", "MXN", "metodo_pago", nuevoPago.getMetodoPago(), "estado", nuevoPago.getEstado(),
                "referencia", nuevoPago.getReferencia(), "creado_en", nuevoPago.getCreadoEn().toString()
            )
        ));
    }

    @GetMapping("/api/pagos/pedido/{pedido_id}")
    public ResponseEntity<?> consultarPago(@PathVariable("pedido_id") Long pedidoId) {
        Optional<Pago> pagoAprobado = pagoRepository.findByPedidoIdAndEstado(pedidoId, "aprobado");
        Pago pago = pagoAprobado.orElseGet(() -> pagoRepository.findTopByPedidoIdOrderByCreadoEnDesc(pedidoId).orElse(null));

        if (pago == null) {
            return ResponseEntity.status(HttpStatus.NOT_FOUND).body(Map.of("error", Map.of("codigo", "PAGO_NO_ENCONTRADO", "mensaje", "No hay pagos para este pedido")));
        }

        return ResponseEntity.ok(Map.of(
            "mensaje", "Comprobante encontrado",
            "datos", Map.of(
                "id", pago.getId(), "pedido_id", pago.getPedidoId(), "monto", pago.getMonto().toString(),
                "moneda", "MXN", "metodo_pago", pago.getMetodoPago(), "estado", pago.getEstado(),
                "referencia", pago.getReferencia(), "creado_en", pago.getCreadoEn().toString()
            )
        ));
    }
}