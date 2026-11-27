package com.tienda.pagos;

import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.web.bind.annotation.*;
import org.springframework.web.client.RestTemplate;
import org.springframework.http.*;
import org.springframework.http.client.SimpleClientHttpRequestFactory;
import javax.sql.DataSource;
import java.math.BigDecimal;
import java.nio.charset.StandardCharsets;
import java.security.*;
import java.security.spec.X509EncodedKeySpec;
import java.sql.Connection;
import java.util.*;
import java.util.regex.Matcher;
import java.util.regex.Pattern;
import java.util.stream.Collectors;
import java.nio.file.Files;
import java.nio.file.Paths;

@RestController
public class PagoController {

    @Autowired
    private PagoRepository pagoRepository;
    @Autowired
    private DataSource dataSource;

    private final RestTemplate restTemplate;

    public PagoController() {
        SimpleClientHttpRequestFactory factory = new SimpleClientHttpRequestFactory();
        factory.setConnectTimeout(5000); factory.setReadTimeout(5000);
        this.restTemplate = new RestTemplate(factory);
    }

    // Validar JWT RS256 sin librerías externas
    private void verifyJwtRs256(String token) throws Exception {
        String[] parts = token.split("\\.");
        if (parts.length != 3) throw new Exception("Formato invalido");
        
        String publicKeyPem = new String(Files.readAllBytes(Paths.get("/run/secrets/jwt_public.pem")))
                .replace("-----BEGIN PUBLIC KEY-----", "").replace("-----END PUBLIC KEY-----", "").replaceAll("\\s+", "");
        
        byte[] publicKeyBytes = Base64.getDecoder().decode(publicKeyPem);
        PublicKey publicKey = KeyFactory.getInstance("RSA").generatePublic(new X509EncodedKeySpec(publicKeyBytes));

        Signature sig = Signature.getInstance("SHA256withRSA");
        sig.initVerify(publicKey);
        sig.update((parts[0] + "." + parts[1]).getBytes(StandardCharsets.UTF_8));
        
        if (!sig.verify(Base64.getUrlDecoder().decode(parts[2]))) throw new Exception("Firma invalida");

        // Validar payload usando regex (sin Jackson)
        String payloadJson = new String(Base64.getUrlDecoder().decode(parts[1]), StandardCharsets.UTF_8);
        
        if (!payloadJson.contains("\"iss\":\"api-usuarios\"")) throw new Exception("Issuer invalido");
        if (!payloadJson.contains("\"aud\":\"tienda-microservicios\"")) throw new Exception("Audience invalido");
        
        Matcher matcher = Pattern.compile("\"exp\":(\\d+)").matcher(payloadJson);
        if (matcher.find()) {
            long exp = Long.parseLong(matcher.group(1));
            if (System.currentTimeMillis() / 1000 > exp) throw new Exception("Token expirado");
        } else {
            throw new Exception("Token sin expiracion");
        }
    }

    @GetMapping("/health")
    public ResponseEntity<?> health() { return ResponseEntity.ok(Map.of("status", "ok", "servicio", "api-pagos")); }

    @GetMapping("/ready")
    public ResponseEntity<?> ready() {
        try (Connection conn = dataSource.getConnection()) {
            conn.createStatement().execute("SELECT 1 FROM pagos LIMIT 1");
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
        if (expectedToken == null || internalToken == null || !internalToken.equals(expectedToken)) {
            return ResponseEntity.status(HttpStatus.FORBIDDEN).body(Map.of("error", Map.of("codigo", "ACCESO_DENEGADO", "mensaje", "Token interno requerido")));
        }
        if (idempotencyKey == null) return ResponseEntity.badRequest().body(Map.of("error", Map.of("codigo", "DATOS_INVALIDOS", "mensaje", "Falta Idempotency-Key")));

        // Validaciones de entrada
        Long pedidoId; String montoRecibido, metodoPago, simulacion;
        try {
            pedidoId = Long.parseLong(request.get("pedido_id").toString());
            montoRecibido = request.get("monto").toString();
            metodoPago = request.get("metodo_pago").toString();
            simulacion = request.getOrDefault("simulacion", "aprobar").toString();
            if (!metodoPago.equals("tarjeta_simulada") && !metodoPago.equals("transferencia_simulada")) throw new Exception("Metodo invalido");
            if (!simulacion.equals("aprobar") && !simulacion.equals("rechazar")) throw new Exception("Simulacion invalida");
        } catch (Exception e) { return ResponseEntity.badRequest().body(Map.of("error", Map.of("codigo", "DATOS_INVALIDOS", "mensaje", "Campos invalidos"))); }

        // Hash normalizado
        String normalizedRequest = request.entrySet().stream().sorted(Map.Entry.comparingByKey()).map(e -> e.getKey() + "=" + e.getValue()).collect(Collectors.joining("&"));
        String solicitudHash;
        try {
            MessageDigest digest = MessageDigest.getInstance("SHA-256");
            byte[] hash = digest.digest(normalizedRequest.getBytes(StandardCharsets.UTF_8));
            StringBuilder hexString = new StringBuilder();
            for (byte b : hash) { String hex = Integer.toHexString(0xff & b); if (hex.length() == 1) hexString.append('0'); hexString.append(hex); }
            solicitudHash = hexString.toString();
        } catch (Exception e) { return ResponseEntity.internalServerError().build(); }

        // Validar idempotencia ANTES de llamar a Pedidos
        Optional<Pago> pagoExistente = pagoRepository.findByClaveIdempotencia(idempotencyKey);
        if (pagoExistente.isPresent()) {
            Pago p = pagoExistente.get();
            if (!p.getSolicitudHash().equals(solicitudHash)) return ResponseEntity.status(HttpStatus.CONFLICT).body(Map.of("error", Map.of("codigo", "IDEMPOTENCIA_CONFLICTIVA", "mensaje", "La clave se usó con otros datos")));
            return ResponseEntity.ok(Map.of("mensaje", p.getEstado().equals("aprobado") ? "Pago procesado" : "Pago rechazado", "datos", Map.of("id", p.getId(), "pedido_id", p.getPedidoId(), "monto", p.getMonto().toString(), "moneda", "MXN", "metodo_pago", p.getMetodoPago(), "estado", p.getEstado(), "referencia", p.getReferencia(), "creado_en", p.getCreadoEn().toString())));
        }

        // Consultar Pedidos para validar monto y estado
        try {
            String pedidosUrl = System.getenv("PEDIDOS_API_URL") + "/api/pedidos/" + pedidoId;
            HttpHeaders headers = new HttpHeaders(); headers.set("X-Internal-Token", expectedToken);
            ResponseEntity<Map> pedidoResponse = restTemplate.exchange(pedidosUrl, HttpMethod.GET, new HttpEntity<>(headers), Map.class);
            
            Map<String, Object> pedidoData = (Map<String, Object>) pedidoResponse.getBody().get("datos");
            if (!"pendiente".equals(pedidoData.get("estado").toString())) return ResponseEntity.status(HttpStatus.CONFLICT).body(Map.of("error", Map.of("codigo", "PEDIDO_NO_PAGABLE", "mensaje", "El pedido no esta pendiente")));
            if (!pedidoData.get("total").toString().equals(montoRecibido)) return ResponseEntity.badRequest().body(Map.of("error", Map.of("codigo", "MONTO_INVALIDO", "mensaje", "El monto no coincide")));
        } catch (Exception e) { return ResponseEntity.status(HttpStatus.SERVICE_UNAVAILABLE).body(Map.of("error", Map.of("codigo", "SERVICIO_NO_DISPONIBLE", "mensaje", "Error con Pedidos: " + e.getMessage()))); }

        // Guardar
        String estado = simulacion.equals("aprobar") ? "aprobado" : "rechazado";
        String referencia = "PAGO-" + String.format("%08d", (int)(Math.random() * 100000000));

        Pago nuevoPago = new Pago();
        nuevoPago.setPedidoId(pedidoId); nuevoPago.setMonto(new BigDecimal(montoRecibido)); nuevoPago.setMetodoPago(metodoPago);
        nuevoPago.setEstado(estado); nuevoPago.setReferencia(referencia); nuevoPago.setClaveIdempotencia(idempotencyKey); nuevoPago.setSolicitudHash(solicitudHash);

        try {
            pagoRepository.save(nuevoPago);
        } catch (Exception e) {
            if (e.getMessage().contains("idx_un_pago_aprobado") || e.getMessage().contains("clave_idempotencia") || e.getMessage().contains("referencia")) {
                return ResponseEntity.status(HttpStatus.CONFLICT).body(Map.of("error", Map.of("codigo", "IDEMPOTENCIA_CONFLICTIVA", "mensaje", "Conflicto de concurrencia detectado")));
            }
            return ResponseEntity.internalServerError().body(Map.of("error", Map.of("codigo", "ERROR_INTERNO", "mensaje", "Error al guardar")));
        }

        return ResponseEntity.status(HttpStatus.CREATED).body(Map.of("mensaje", estado.equals("aprobado") ? "Pago procesado" : "Pago rechazado", "datos", Map.of("id", nuevoPago.getId(), "pedido_id", nuevoPago.getPedidoId(), "monto", nuevoPago.getMonto().toString(), "moneda", "MXN", "metodo_pago", nuevoPago.getMetodoPago(), "estado", nuevoPago.getEstado(), "referencia", nuevoPago.getReferencia(), "creado_en", nuevoPago.getCreadoEn().toString())));
    }

    @GetMapping("/api/pagos/pedido/{pedido_id}")
    public ResponseEntity<?> consultarPago(
            @PathVariable("pedido_id") Long pedidoId,
            @RequestHeader(value = "X-Internal-Token", required = false) String internalToken,
            @RequestHeader(value = "Authorization", required = false) String authHeader) {
        
        String expectedToken = System.getenv("INTERNAL_API_TOKEN");
        boolean isInternal = expectedToken != null && internalToken != null && internalToken.equals(expectedToken);
        
        if (!isInternal) {
            if (authHeader == null || !authHeader.startsWith("Bearer ")) return ResponseEntity.status(HttpStatus.UNAUTHORIZED).body(Map.of("error", Map.of("codigo", "TOKEN_AUSENTE", "mensaje", "No autorizado")));
            try {
                verifyJwtRs256(authHeader.split(" ")[1]);
            } catch (Exception e) { return ResponseEntity.status(HttpStatus.UNAUTHORIZED).body(Map.of("error", Map.of("codigo", "TOKEN_INVALIDO", "mensaje", e.getMessage()))); }
        }

        Optional<Pago> pagoAprobado = pagoRepository.findByPedidoIdAndEstado(pedidoId, "aprobado");
        Pago pago = pagoAprobado.orElseGet(() -> pagoRepository.findTopByPedidoIdOrderByCreadoEnDesc(pedidoId).orElse(null));

        if (pago == null) return ResponseEntity.status(HttpStatus.NOT_FOUND).body(Map.of("error", Map.of("codigo", "PAGO_NO_ENCONTRADO", "mensaje", "No hay pagos")));

        return ResponseEntity.ok(Map.of("mensaje", "Comprobante encontrado", "datos", Map.of("id", pago.getId(), "pedido_id", pago.getPedidoId(), "monto", pago.getMonto().toString(), "moneda", "MXN", "metodo_pago", pago.getMetodoPago(), "estado", pago.getEstado(), "referencia", pago.getReferencia(), "creado_en", pago.getCreadoEn().toString())));
    }
}