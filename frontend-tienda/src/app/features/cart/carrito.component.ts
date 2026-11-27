import { Component, inject, OnInit, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { TiendaService } from '../../core/services/tienda.service';
import { AuthService } from '../../core/services/auth.service';
import {
  Carrito,
  CheckoutRequest,
  CheckoutResponseData,
  CotizacionEnvio,
} from '../../core/models/api.models';

interface CheckoutPendiente {
  usuarioId: number;
  pedidoId: number;
  idempotencyKey: string;
  payload: CheckoutRequest;
}

const STORAGE_KEY_CHECKOUT = 'techstore_checkout_pendiente';

@Component({
  selector: 'app-carrito',
  standalone: true,
  imports: [FormsModule, RouterLink],
  template: `
    <div class="max-w-5xl mx-auto">
      <h1 class="text-3xl font-bold text-slate-900 mb-6">Mi Carrito y Checkout</h1>

      @if (cargando()) {
        <div class="bg-white h-64 rounded-2xl border border-slate-200 animate-pulse"></div>
      } @else if ((!carrito() || carrito()?.productos?.length === 0) && !checkoutPendiente()) {
        <div class="bg-white p-12 rounded-2xl border border-slate-200 text-center">
          <p class="text-slate-500 mb-4">Tu carrito está vacío en este momento.</p>
          <a
            routerLink="/"
            class="inline-block bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-6 py-2.5 rounded-lg transition"
          >
            Ir a explorar productos
          </a>
        </div>
      } @else {
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
          <!-- Lista de productos en el carrito -->
          <div class="lg:col-span-2 space-y-4">
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
              <div class="flex justify-between items-center pb-4 border-b border-slate-100 mb-4">
                <h2 class="font-bold text-lg text-slate-800">
                  Artículos ({{ carrito()?.cantidad_total || 0 }})
                </h2>
                @if (!checkoutPendiente()) {
                  <button
                    (click)="vaciarCarrito()"
                    class="text-xs text-rose-600 hover:underline font-medium cursor-pointer"
                  >
                    Vaciar carrito
                  </button>
                }
              </div>

              @if (carrito()?.productos?.length) {
                <div class="divide-y divide-slate-100">
                  @for (item of carrito()?.productos; track item.producto_id) {
                    <div class="py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                      <div>
                        <h3 class="font-semibold text-slate-900">{{ item.nombre }}</h3>
                        <p class="text-xs text-slate-500">Precio unitario: \${{ item.precio_unitario }} MXN</p>
                      </div>

                      <div class="flex items-center gap-3">
                        <button
                          (click)="cambiarCantidad(item.producto_id, item.cantidad - 1)"
                          [disabled]="item.cantidad <= 1 || !!checkoutPendiente()"
                          class="w-8 h-8 rounded-lg border border-slate-300 font-bold disabled:opacity-40 cursor-pointer"
                        >
                          -
                        </button>
                        <span class="w-8 text-center font-semibold text-sm">{{ item.cantidad }}</span>
                        <button
                          (click)="cambiarCantidad(item.producto_id, item.cantidad + 1)"
                          [disabled]="!!checkoutPendiente()"
                          class="w-8 h-8 rounded-lg border border-slate-300 font-bold disabled:opacity-40 cursor-pointer"
                        >
                          +
                        </button>

                        <span class="w-24 text-right font-bold text-slate-900">\${{ item.subtotal }}</span>

                        <button
                          (click)="eliminarItem(item.producto_id)"
                          [disabled]="!!checkoutPendiente()"
                          class="text-rose-500 hover:text-rose-700 disabled:opacity-40 text-xs ml-2 cursor-pointer"
                        >
                          Eliminar
                        </button>
                      </div>
                    </div>
                  }
                </div>
              } @else {
                <p class="text-sm text-slate-500 py-4">
                  Los artículos ya fueron reservados en el pedido pendiente #{{ checkoutPendiente()?.pedidoId }}.
                </p>
              }
            </div>
          </div>

          <!-- Panel de Envío y Pago (Checkout) -->
          <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 h-fit space-y-6">
            <h2 class="font-bold text-lg text-slate-900">Resumen y Pago</h2>

            @if (checkoutPendiente()) {
              <div class="p-3 rounded-lg bg-amber-50 border border-amber-300 text-amber-900 text-xs space-y-1">
                <p class="font-bold">Operación de compra pendiente de confirmación</p>
                <p>
                  Tu <strong>Pedido #{{ checkoutPendiente()?.pedidoId }}</strong> está guardado en esta sesión.
                  Conservamos tu clave de idempotencia y solicitud original para reintentar sin duplicar la compra.
                </p>
              </div>
            }

            @if (errorMsg()) {
              <div class="p-3 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-xs">
                {{ errorMsg() }}
              </div>
            }

            @if (resultadoCheckout()) {
              <div
                class="p-4 rounded-xl border text-sm"
                [class]="
                  resultadoCheckout()?.estado === 'pagado'
                    ? 'bg-emerald-50 border-emerald-200 text-emerald-800'
                    : 'bg-rose-50 border-rose-200 text-rose-800'
                "
              >
                <p class="font-bold mb-1">
                  {{
                    resultadoCheckout()?.estado === 'pagado'
                      ? '¡Compra completada con éxito!'
                      : 'El pago fue rechazado'
                  }}
                </p>
                <p class="text-xs">Pedido #{{ resultadoCheckout()?.pedido_id }}</p>
                <p class="text-xs">Referencia pago: {{ resultadoCheckout()?.pago?.referencia }}</p>
                @if (resultadoCheckout()?.envio) {
                  <p class="text-xs font-semibold mt-1">
                    Guía de rastreo: {{ resultadoCheckout()?.envio?.guia }}
                  </p>
                }
                <a routerLink="/mis-pedidos" class="inline-block mt-3 underline font-semibold text-xs">
                  Ver en Mis Pedidos →
                </a>
              </div>
            }

            <!-- Datos de envío y cotizador -->
            <div class="space-y-3">
              <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Código Postal (5 dígitos)</label>
                <div class="flex gap-2">
                  <input
                    type="text"
                    [(ngModel)]="codigoPostal"
                    [disabled]="!!checkoutPendiente()"
                    maxlength="5"
                    placeholder="38000"
                    class="flex-1 px-3 py-2 border border-slate-300 rounded-lg text-sm disabled:bg-slate-100"
                  />
                  <button
                    type="button"
                    (click)="cotizarEnvio()"
                    [disabled]="cotizando() || !!checkoutPendiente()"
                    class="px-3 py-2 bg-slate-800 hover:bg-slate-700 disabled:opacity-50 text-white text-xs font-semibold rounded-lg cursor-pointer"
                  >
                    {{ cotizando() ? '...' : 'Cotizar' }}
                  </button>
                </div>
              </div>

              <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Dirección de entrega</label>
                <textarea
                  [(ngModel)]="direccion"
                  [disabled]="!!checkoutPendiente()"
                  rows="2"
                  placeholder="Calle Ejemplo 123, Col. Centro, Celaya, Gto."
                  class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm disabled:bg-slate-100"
                ></textarea>
              </div>

              <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Método de pago</label>
                <select
                  [(ngModel)]="metodoPago"
                  [disabled]="!!checkoutPendiente()"
                  class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white disabled:bg-slate-100"
                >
                  <option value="tarjeta_simulada">Tarjeta Simulada</option>
                  <option value="transferencia_simulada">Transferencia Simulada</option>
                </select>
              </div>

              <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Simulación de pasarela</label>
                <select
                  [(ngModel)]="simulacion"
                  [disabled]="!!checkoutPendiente()"
                  class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm bg-white disabled:bg-slate-100"
                >
                  <option value="aprobar">Aprobar pago</option>
                  <option value="rechazar">Rechazar pago (Prueba compensación)</option>
                </select>
              </div>
            </div>

            <!-- Desglose de importes -->
            <div class="pt-4 border-t border-slate-100 space-y-2 text-sm">
              <div class="flex justify-between text-slate-600">
                <span>Subtotal productos:</span>
                <span>\${{ carrito()?.subtotal || '0.00' }} MXN</span>
              </div>
              <div class="flex justify-between text-slate-600">
                <span>Envío estimado {{ cotizacion() ? '(' + cotizacion()?.dias_estimados + ' días)' : '' }}:</span>
                <span>{{ cotizacion() ? '$' + cotizacion()?.costo_envio + ' MXN' : 'Por calcular' }}</span>
              </div>
              <div class="flex justify-between font-extrabold text-base text-slate-900 pt-2 border-t border-slate-100">
                <span>Total estimado:</span>
                <span>\${{ calcularTotalEstimado() }} MXN</span>
              </div>
            </div>

            @if (checkoutPendiente()) {
              <button
                (click)="reintentarCheckout()"
                [disabled]="procesandoCompra()"
                class="w-full bg-amber-600 hover:bg-amber-700 disabled:opacity-50 text-white font-bold py-3 rounded-xl transition cursor-pointer"
              >
                {{
                  procesandoCompra()
                    ? 'Confirmando operación...'
                    : 'Reintentar pago (Pedido #' + checkoutPendiente()?.pedidoId + ')'
                }}
              </button>
            } @else {
              <button
                (click)="confirmarCompra()"
                [disabled]="procesandoCompra()"
                class="w-full bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white font-bold py-3 rounded-xl transition cursor-pointer"
              >
                {{ procesandoCompra() ? 'Procesando orden...' : 'Confirmar y Pagar' }}
              </button>
            }
          </div>
        </div>
      }
    </div>
  `,
})
export class CarritoComponent implements OnInit {
  private tienda = inject(TiendaService);
  private auth = inject(AuthService);
  private router = inject(Router);

  carrito = signal<Carrito | null>(null);
  cotizacion = signal<CotizacionEnvio | null>(null);
  resultadoCheckout = signal<CheckoutResponseData | null>(null);
  checkoutPendiente = signal<CheckoutPendiente | null>(null);

  cargando = signal(true);
  cotizando = signal(false);
  procesandoCompra = signal(false);
  errorMsg = signal<string | null>(null);

  codigoPostal = '38000';
  direccion = '';
  metodoPago: 'tarjeta_simulada' | 'transferencia_simulada' = 'tarjeta_simulada';
  simulacion: 'aprobar' | 'rechazar' = 'aprobar';

  ngOnInit(): void {
    this.recuperarCheckoutPendiente();
    this.cargarCarrito();
  }

  private recuperarCheckoutPendiente(): void {
    const user = this.auth.usuario();
    if (!user) return;

    try {
      const raw = sessionStorage.getItem(STORAGE_KEY_CHECKOUT);
      if (!raw) return;

      const guardado = JSON.parse(raw) as CheckoutPendiente;
      if (
        guardado &&
        guardado.usuarioId === user.id &&
        guardado.pedidoId &&
        guardado.idempotencyKey &&
        guardado.payload
      ) {
        this.checkoutPendiente.set(guardado);
        this.metodoPago = guardado.payload.metodo_pago;
        this.simulacion = guardado.payload.simulacion;
      } else if (guardado.usuarioId !== user.id) {
        sessionStorage.removeItem(STORAGE_KEY_CHECKOUT);
      }
    } catch {
      sessionStorage.removeItem(STORAGE_KEY_CHECKOUT);
    }
  }

  private guardarCheckoutPendiente(operacion: CheckoutPendiente): void {
    const datosSeguros: CheckoutPendiente = {
      usuarioId: operacion.usuarioId,
      pedidoId: operacion.pedidoId,
      idempotencyKey: operacion.idempotencyKey,
      payload: {
        metodo_pago: operacion.payload.metodo_pago,
        simulacion: operacion.payload.simulacion,
      },
    };
    sessionStorage.setItem(STORAGE_KEY_CHECKOUT, JSON.stringify(datosSeguros));
    this.checkoutPendiente.set(datosSeguros);
  }

  private limpiarCheckoutPendiente(): void {
    sessionStorage.removeItem(STORAGE_KEY_CHECKOUT);
    this.checkoutPendiente.set(null);
  }

  cargarCarrito(): void {
    const user = this.auth.usuario();
    if (!user) return;

    this.tienda.obtenerCarrito(user.id).subscribe({
      next: (res) => {
        this.carrito.set(res.datos);
        this.cargando.set(false);
      },
      error: (err) => {
        this.errorMsg.set(err.error?.error?.mensaje || 'No se pudo cargar el carrito');
        this.cargando.set(false);
      },
    });
  }

  cambiarCantidad(productoId: number, nuevaCantidad: number): void {
    const user = this.auth.usuario();
    if (!user || nuevaCantidad < 1 || this.checkoutPendiente()) return;

    this.tienda.actualizarCantidadCarrito(user.id, productoId, nuevaCantidad).subscribe({
      next: (res) => this.carrito.set(res.datos),
      error: (err) => this.errorMsg.set(err.error?.error?.mensaje || 'Stock insuficiente'),
    });
  }

  eliminarItem(productoId: number): void {
    const user = this.auth.usuario();
    if (!user || this.checkoutPendiente()) return;

    this.tienda.eliminarDelCarrito(user.id, productoId).subscribe({
      next: (res) => this.carrito.set(res.datos),
      error: (err) => this.errorMsg.set(err.error?.error?.mensaje || 'Error al eliminar producto'),
    });
  }

  vaciarCarrito(): void {
    const user = this.auth.usuario();
    if (!user || this.checkoutPendiente()) return;

    this.tienda.vaciarCarrito(user.id).subscribe({
      next: (res) => this.carrito.set(res.datos),
    });
  }

  cotizarEnvio(): void {
    const totalUnidades = this.carrito()?.cantidad_total || 1;
    if (!/^\d{5}$/.test(this.codigoPostal)) {
      this.errorMsg.set('Ingresa un código postal válido de 5 dígitos.');
      return;
    }

    this.cotizando.set(true);
    this.errorMsg.set(null);

    this.tienda.cotizarEnvio(this.codigoPostal, totalUnidades).subscribe({
      next: (res) => {
        this.cotizacion.set(res.datos);
        this.cotizando.set(false);
      },
      error: (err) => {
        this.cotizando.set(false);
        this.errorMsg.set(err.error?.error?.mensaje || 'No se pudo cotizar el envío');
      },
    });
  }

  calcularTotalEstimado(): string {
    const sub = parseFloat(this.carrito()?.subtotal || '0');
    const env = parseFloat(this.cotizacion()?.costo_envio || '0');
    return (sub + env).toFixed(2);
  }

  confirmarCompra(): void {
    if (this.checkoutPendiente()) {
      this.reintentarCheckout();
      return;
    }

    const user = this.auth.usuario();
    if (!user) return;

    if (!/^\d{5}$/.test(this.codigoPostal) || !this.direccion.trim()) {
      this.errorMsg.set('Completa el código postal de 5 dígitos y la dirección de entrega.');
      return;
    }

    this.procesandoCompra.set(true);
    this.errorMsg.set(null);
    this.resultadoCheckout.set(null);

    // Paso 1: Crear pedido pendiente y reservar inventario (POST /api/pedidos)
    this.tienda
      .crearPedido({
        usuario_id: user.id,
        direccion: this.direccion.trim(),
        codigo_postal: this.codigoPostal,
      })
      .subscribe({
        next: (pedidoRes) => {
          const operacion: CheckoutPendiente = {
            usuarioId: user.id,
            pedidoId: pedidoRes.datos.id,
            idempotencyKey: crypto.randomUUID(),
            payload: {
              metodo_pago: this.metodoPago,
              simulacion: this.simulacion,
            },
          };

          // Persistir ANTES de enviar el checkout para sobrevivir a recargas o caídas de red
          this.guardarCheckoutPendiente(operacion);
          this.ejecutarCheckoutSaga(operacion);
        },
        error: (err) => {
          this.procesandoCompra.set(false);
          this.errorMsg.set(err.error?.error?.mensaje || 'No se pudo crear el pedido (verifica stock)');
        },
      });
  }

  reintentarCheckout(): void {
    const pendiente = this.checkoutPendiente();
    if (!pendiente) return;

    this.procesandoCompra.set(true);
    this.errorMsg.set(null);
    this.ejecutarCheckoutSaga(pendiente);
  }

  private ejecutarCheckoutSaga(operacion: CheckoutPendiente): void {
    this.tienda
      .procesarCheckout(operacion.pedidoId, operacion.payload, operacion.idempotencyKey)
      .subscribe({
        next: (checkoutRes) => {
          // Resultado definitivo obtenido: eliminar datos temporales
          this.procesandoCompra.set(false);
          this.limpiarCheckoutPendiente();
          this.resultadoCheckout.set(checkoutRes.datos);
          this.cargarCarrito();
        },
        error: (err) => {
          this.procesandoCompra.set(false);
          const esFalloTransitorio =
            err.status === 0 || err.status === 502 || err.status === 503 || err.status === 504;

          if (esFalloTransitorio) {
            // Conservar en sessionStorage ante errores de red o HTTP 502, 503, 504
            this.guardarCheckoutPendiente(operacion);
            this.errorMsg.set(
              err.error?.error?.mensaje ||
                `No se recibió confirmación definitiva (HTTP ${err.status || 'Red'}). Reintenta el pedido #${operacion.pedidoId} sin generar otra compra.`
            );
          } else {
            // Error definitivo del negocio (ej. 400, 402, 409): limpiar operación pendiente
            this.limpiarCheckoutPendiente();
            this.errorMsg.set(err.error?.error?.mensaje || 'Error al procesar el pago del pedido');
          }
        },
      });
  }
}