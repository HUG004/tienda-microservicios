package com.tienda.pagos;

import org.springframework.data.jpa.repository.JpaRepository;
import java.util.Optional;

public interface PagoRepository extends JpaRepository<Pago, Long> {
    Optional<Pago> findByPedidoIdAndEstado(Long pedidoId, String estado);
    Optional<Pago> findTopByPedidoIdOrderByCreadoEnDesc(Long pedidoId);
}