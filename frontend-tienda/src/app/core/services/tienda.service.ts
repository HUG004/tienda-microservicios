import { Injectable, inject, signal } from '@angular/core';
import { HttpClient, HttpHeaders, HttpParams } from '@angular/common/http';
import { tap } from 'rxjs';
import { environment } from '../../../environments/environment';
import {
  ApiResponse,
  Carrito,
  Categoria,
  CheckoutRequest,
  CheckoutResponseData,
  ComprobantePago,
  CotizacionEnvio,
  CrearProductoRequest,
  InventarioProducto,
  Pedido,
  Producto,
  PromedioProducto,
  RastreoEnvio,
  Resena,
  ResenasProductoData,
} from '../models/api.models';

@Injectable({ providedIn: 'root' })
export class TiendaService {
  private http = inject(HttpClient);
  private urls = environment.apiUrls;

  // Contador global del carrito para el Navbar
  readonly carritoCantidad = signal<number>(0);

  // --- 2. PRODUCTOS (8002) ---
  listarProductos(buscar?: string, categoriaId?: number) {
    let params = new HttpParams();
    if (buscar?.trim()) params = params.set('buscar', buscar.trim());
    if (categoriaId) params = params.set('categoria', categoriaId.toString());
    return this.http.get<ApiResponse<Producto[]>>(this.urls.productos, { params });
  }

  obtenerProducto(id: number) {
    return this.http.get<ApiResponse<Producto>>(`${this.urls.productos}/${id}`);
  }

  listarCategorias() {
    return this.http.get<ApiResponse<Categoria[]>>(`${this.urls.productos}/categorias`);
  }

  crearProducto(datos: CrearProductoRequest) {
    return this.http.post<ApiResponse<Producto>>(this.urls.productos, datos);
  }

  // --- 3. ENVÍOS (8003) ---
  cotizarEnvio(codigo_postal: string, cantidad: number) {
    return this.http.post<ApiResponse<CotizacionEnvio>>(`${this.urls.envios}/cotizar`, {
      codigo_postal,
      cantidad,
    });
  }

  rastrearEnvio(guia: string) {
    return this.http.get<ApiResponse<RastreoEnvio>>(`${this.urls.envios}/rastreo/${guia}`);
  }

  // --- 4. INVENTARIO (8004) ---
  consultarInventario(productoId: number) {
    return this.http.get<ApiResponse<InventarioProducto>>(`${this.urls.inventario}/${productoId}`);
  }

  reabastecerInventario(producto_id: number, cantidad: number) {
    return this.http.post<ApiResponse<InventarioProducto>>(`${this.urls.inventario}/reabastecer`, {
      producto_id,
      cantidad,
    });
  }

  // --- 5. CARRITO (8005) ---
  obtenerCarrito(usuarioId: number) {
    return this.http.get<ApiResponse<Carrito>>(`${this.urls.carrito}/${usuarioId}`).pipe(
      tap((res) => this.carritoCantidad.set(res.datos.cantidad_total))
    );
  }

  agregarAlCarrito(usuarioId: number, producto_id: number, cantidad: number) {
    return this.http
      .post<ApiResponse<Carrito>>(`${this.urls.carrito}/${usuarioId}/agregar`, {
        producto_id,
        cantidad,
      })
      .pipe(tap((res) => this.carritoCantidad.set(res.datos.cantidad_total)));
  }

  actualizarCantidadCarrito(usuarioId: number, productoId: number, cantidad: number) {
    return this.http
      .patch<ApiResponse<Carrito>>(`${this.urls.carrito}/${usuarioId}/productos/${productoId}`, {
        cantidad,
      })
      .pipe(tap((res) => this.carritoCantidad.set(res.datos.cantidad_total)));
  }

  eliminarDelCarrito(usuarioId: number, productoId: number) {
    return this.http
      .delete<ApiResponse<Carrito>>(`${this.urls.carrito}/${usuarioId}/productos/${productoId}`)
      .pipe(tap((res) => this.carritoCantidad.set(res.datos.cantidad_total)));
  }

  vaciarCarrito(usuarioId: number) {
    return this.http
      .delete<ApiResponse<Carrito>>(`${this.urls.carrito}/${usuarioId}/vaciar`)
      .pipe(tap(() => this.carritoCantidad.set(0)));
  }

  // --- 6. PEDIDOS (8006) ---
  crearPedido(datos: { usuario_id: number; direccion: string; codigo_postal: string }) {
    return this.http.post<ApiResponse<Pedido>>(this.urls.pedidos, datos);
  }

  procesarCheckout(pedidoId: number, datos: CheckoutRequest, idempotencyKey: string) {
    const headers = new HttpHeaders({ 'Idempotency-Key': idempotencyKey });
    return this.http.post<ApiResponse<CheckoutResponseData>>(
      `${this.urls.pedidos}/${pedidoId}/checkout`,
      datos,
      { headers }
    );
  }

  obtenerPedidosUsuario(usuarioId: number) {
    return this.http.get<ApiResponse<Pedido[]>>(`${this.urls.pedidos}/usuario/${usuarioId}`);
  }

  obtenerDetallePedido(pedidoId: number) {
    return this.http.get<ApiResponse<Pedido>>(`${this.urls.pedidos}/${pedidoId}`);
  }

  // --- 7. PAGOS (8007) ---
  obtenerComprobantePago(pedidoId: number) {
    return this.http.get<ApiResponse<ComprobantePago>>(`${this.urls.pagos}/pedido/${pedidoId}`);
  }

  // --- 8. RESEÑAS (8008) ---
  obtenerResenasProducto(productoId: number) {
    return this.http.get<ApiResponse<ResenasProductoData>>(
      `${this.urls.resenas}/producto/${productoId}`
    );
  }

  obtenerPromediosProductos(producto_ids: number[]) {
    return this.http.post<ApiResponse<PromedioProducto[]>>(`${this.urls.resenas}/promedios`, {
      producto_ids,
    });
  }

  crearResena(datos: {
    producto_id: number;
    usuario_id: number;
    calificacion: number;
    comentario: string;
  }) {
    return this.http.post<ApiResponse<Resena>>(this.urls.resenas, datos);
  }
}