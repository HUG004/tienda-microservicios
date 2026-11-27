// Envoltorio estándar de respuestas
export interface ApiResponse<T> {
  mensaje: string;
  datos: T;
}

export interface ApiErrorResponse {
  error: {
    codigo: string;
    mensaje: string;
  };
}

// 1. Usuarios (8001)
export type RolUsuario = 'cliente' | 'admin';

export interface Usuario {
  id: number;
  nombre: string;
  correo: string;
  rol: RolUsuario;
  activo?: boolean;
  creado_en?: string;
}

export interface LoginResponseData {
  token: string;
  tipo_token: string;
  expira_en: number;
  usuario: Usuario;
}

// 2. Productos (8002)
export interface Categoria {
  id: number;
  nombre: string;
  descripcion: string;
}

export interface Producto {
  id: number;
  nombre: string;
  descripcion: string;
  precio: string; // Ej. "899.00"
  categoria_id: number;
  categoria: string;
  imagen_url: string;
  activo: boolean;
}

export interface CrearProductoRequest {
  nombre: string;
  descripcion: string;
  precio: string;
  categoria: string;
  imagen_url: string;
}

// 3. Envíos (8003)
export type EstadoEnvio = 'preparando' | 'en_transito' | 'entregado';

export interface CotizacionEnvio {
  codigo_postal: string;
  cantidad: number;
  peso: string;
  costo_envio: string;
  dias_estimados: number;
  moneda: string;
}

export interface RastreoEnvio {
  pedido_id: number;
  guia: string;
  estado: EstadoEnvio;
  dias_estimados: number;
  actualizado_en: string;
}

// 4. Inventario (8004)
export interface InventarioProducto {
  producto_id: number;
  stock_total: number;
  stock_reservado: number;
  stock_disponible: number;
  disponible?: boolean;
}

// 5. Carrito (8005)
export interface CarritoItem {
  producto_id: number;
  nombre: string;
  precio_unitario: string;
  cantidad: number;
  subtotal: string;
}

export interface Carrito {
  usuario_id: number;
  productos: CarritoItem[];
  cantidad_total: number;
  subtotal: string;
}

// 6. Pedidos (8006)
export type EstadoPedido = 'pendiente' | 'pagado' | 'enviado' | 'cancelado';

export interface PedidoDetalle {
  producto_id: number;
  nombre_producto: string;
  cantidad: number;
  precio_unitario: string;
  subtotal: string;
}

export interface Pedido {
  id: number;
  usuario_id: number;
  productos?: PedidoDetalle[];
  subtotal: string;
  costo_envio: string;
  total: string;
  moneda?: string;
  direccion?: string;
  codigo_postal?: string;
  estado: EstadoPedido;
  guia: string | null;
  creado_en: string;
}

export interface CheckoutRequest {
  metodo_pago: 'tarjeta_simulada' | 'transferencia_simulada';
  simulacion: 'aprobar' | 'rechazar';
}

export interface CheckoutResponseData {
  pedido_id: number;
  estado: EstadoPedido;
  total?: string;
  pago: {
    estado: 'aprobado' | 'rechazado';
    referencia: string;
  };
  envio: {
    guia: string;
    estado: EstadoEnvio;
  } | null;
}

// 7. Pagos (8007)
export interface ComprobantePago {
  id: number;
  pedido_id: number;
  monto: string;
  moneda: string;
  metodo_pago: string;
  estado: 'aprobado' | 'rechazado';
  referencia: string;
  creado_en: string;
}

// 8. Reseñas (8008)
export interface Resena {
  id: string;
  producto_id: number;
  usuario_id: number;
  calificacion: number;
  comentario: string;
  creado_en: string;
}

export interface ResenasProductoData {
  producto_id: number;
  promedio: number;
  total_resenas: number;
  resenas: Resena[];
}

export interface PromedioProducto {
  producto_id: number;
  promedio: number;
  total_resenas: number;
}