package com.tienda.pagos;

import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.web.bind.annotation.*;
import org.springframework.web.client.RestTemplate;
import org.springframework.http.*;
import javax.sql.DataSource;
import java.math.BigDecimal;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.sql.Connection;
import java.util.Map;
import java.util.Optional;

@RestController
public class PagoController {

    @Autowired
    private PagoRepository pagoRepository;
    @Autowired
    private DataSource dataSource;

    private final RestTemplate restTemplate = new RestTemplate();

    @GetMapping("/health")
    public ResponseEntity<?> health() { return ResponseEntity.ok(Map.of("status", "ok", "servicio", "api-pagos")); }

    @GetMapping("/ready")
    public ResponseEntity<?> ready() {
        try (Connection conn = dataSource.getConnection()) {
            return ResponseEntity.ok(Map.of("status", "ready", "servicio", "api-pagos"));
        } catch (Exception e) {
            return ResponseEntity.status(HttpStatus.SERVICE_UNAVAILABLE).body(Map.of("status", "not_ready", "servicio", "api-pagos"));
        }
    }

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

        Long pedidoId = Long.parseLong(request.get("pedido_id").toString());
        // AQUÍ ESTÁ LA CORRECCIÓN: La variable ahora está fuera del try para que todo el método la vea
        String montoRecibido = request.get("monto").toString(); 

        // 1. Consultar Pedidos para validar monto
        try {
            String pedidosUrl = System.getenv("PEDIDOS_API_URL") + "/api/pedidos/" + pedidoId;
            HttpHeaders headers = new HttpHeaders();
            headers.set("X-Internal-Token", expectedToken);
            HttpEntity<String> entity = new HttpEntity<>(headers);
            ResponseEntity<Map> pedidoResponse = restTemplate.exchange(pedidosUrl, HttpMethod.GET, entity, Map.class);
            
            Map<String, Object> pedidoData = (Map<String, Object>) pedidoResponse.getBody().get("datos");
            String montoOficial = pedidoData.get("total").toString();
            
            if (!montoOficial.equals(montoRecibido)) {
                return ResponseEntity.badRequest().body(Map.of("error", Map.of("codigo", "MONTO_INVALIDO", "mensaje", "El monto no coincide con el pedido")));
            }
        } catch (Exception e) {
            return ResponseEntity.status(HttpStatus.SERVICE_UNAVAILABLE).body(Map.of("error", Map.of("codigo", "SERVICIO_NO_DISPONIBLE", "mensaje", "Error al conectar con Pedidos: " + e.getMessage())));
        }

        // 2. Generar SHA-256 de la solicitud
        String solicitudString = request.toString();
        String solicitudHash;
        try {
            MessageDigest digest = MessageDigest.getInstance("SHA-256");
            byte[] hash = digest.digest(solicitudString.getBytes(StandardCharsets.UTF_8));
            StringBuilder hexString = new StringBuilder();
            for (byte b : hash) { String hex = Integer.toHexString(0xff & b); if (hex.length() == 1) hexString.append('0'); hexString.append(hex); }
            solicitudHash = hexString.toString();
        } catch (Exception e) { return ResponseEntity.internalServerError().build(); }

        // 3. Idempotencia Real
        Optional<Pago> pagoExistente = pagoRepository.findByClaveIdempotencia(idempotencyKey);
        if (pagoExistente.isPresent()) {
            Pago p = pagoExistente.get();
            if (!p.getSolicitudHash().equals(solicitudHash)) {
                return ResponseEntity.status(HttpStatus.CONFLICT).body(Map.of("error", Map.of("codigo", "IDEMPOTENCIA_CONFLICTIVA", "mensaje", "La clave se usó con otros datos")));
            }
            return ResponseEntity.ok(Map.of(
                "mensaje", p.getEstado().equals("aprobado") ? "Pago procesado" : "Pago rechazado",
                "datos", Map.of("id", p.getId(), "pedido_id", p.getPedidoId(), "monto", p.getMonto().toString(), "moneda", "MXN", "metodo_pago", p.getMetodoPago(), "estado", p.getEstado(), "referencia", p.getReferencia(), "creado_en", p.getCreadoEn().toString())
            ));
        }

        // 4. Guardar nuevo pago
        String simulacion = (String) request.getOrDefault("simulacion", "aprobar");
        String estado = simulacion.equals("aprobar") ? "aprobado" : "rechazado";
        String referencia = "PAGO-" + String.format("%08d", (int)(Math.random() * 100000000));

        Pago nuevoPago = new Pago();
        nuevoPago.setPedidoId(pedidoId);
        nuevoPago.setMonto(new BigDecimal(montoRecibido));
        nuevoPago.setMetodoPago(request.get("metodo_pago").toString());
        nuevoPago.setEstado(estado);
        nuevoPago.setReferencia(referencia);
        nuevoPago.setClaveIdempotencia(idempotencyKey);
        nuevoPago.setSolicitudHash(solicitudHash);

        pagoRepository.save(nuevoPago);

        return ResponseEntity.status(HttpStatus.CREATED).body(Map.of(
            "mensaje", estado.equals("aprobado") ? "Pago procesado" : "Pago rechazado",
            "datos", Map.of("id", nuevoPago.getId(), "pedido_id", nuevoPago.getPedidoId(), "monto", nuevoPago.getMonto().toString(), "moneda", "MXN", "metodo_pago", nuevoPago.getMetodoPago(), "estado", nuevoPago.getEstado(), "referencia", nuevoPago.getReferencia(), "creado_en", nuevoPago.getCreadoEn().toString())
        ));
    }

    @GetMapping("/api/pagos/pedido/{pedido_id}")
    public ResponseEntity<?> consultarPago(
            @PathVariable("pedido_id") Long pedidoId,
            @RequestHeader(value = "X-Internal-Token", required = false) String internalToken,
            @RequestHeader(value = "Authorization", required = false) String authHeader) {
        
        if (internalToken == null && authHeader == null) {
            return ResponseEntity.status(HttpStatus.UNAUTHORIZED).body(Map.of("error", Map.of("codigo", "TOKEN_AUSENTE", "mensaje", "No autorizado")));
        }

        Optional<Pago> pagoAprobado = pagoRepository.findByPedidoIdAndEstado(pedidoId, "aprobado");
        Pago pago = pagoAprobado.orElseGet(() -> pagoRepository.findTopByPedidoIdOrderByCreadoEnDesc(pedidoId).orElse(null));

        if (pago == null) return ResponseEntity.status(HttpStatus.NOT_FOUND).body(Map.of("error", Map.of("codigo", "PAGO_NO_ENCONTRADO", "mensaje", "No hay pagos")));

        return ResponseEntity.ok(Map.of("mensaje", "Comprobante encontrado", "datos", Map.of("id", pago.getId(), "pedido_id", pago.getPedidoId(), "monto", pago.getMonto().toString(), "moneda", "MXN", "metodo_pago", pago.getMetodoPago(), "estado", pago.getEstado(), "referencia", pago.getReferencia(), "creado_en", pago.getCreadoEn().toString())));
    }
}