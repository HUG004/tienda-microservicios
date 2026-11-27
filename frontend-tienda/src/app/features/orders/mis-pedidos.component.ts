import { Component, inject, OnInit, signal } from '@angular/core';
import { TiendaService } from '../../core/services/tienda.service';
import { AuthService } from '../../core/services/auth.service';
import { ComprobantePago, Pedido, RastreoEnvio } from '../../core/models/api.models';

@Component({
  selector: 'app-mis-pedidos',
  standalone: true,
  template: `
    <div class="max-w-5xl mx-auto">
      <h1 class="text-3xl font-bold text-slate-900 mb-6">Mis Pedidos</h1>

      @if (cargando()) {
        <div class="space-y-4">
          @for (i of [1, 2]; track i) {
            <div class="bg-white h-36 rounded-2xl border border-slate-200 animate-pulse"></div>
          }
        </div>
      } @else if (errorMsg()) {
        <div class="p-6 bg-rose-50 border border-rose-200 rounded-xl text-rose-700">
          {{ errorMsg() }}
        </div>
      } @else if (pedidos().length === 0) {
        <div class="bg-white p-12 rounded-2xl border border-slate-200 text-center text-slate-500">
          Aún no has realizado ningún pedido.
        </div>
      } @else {
        <div class="space-y-4">
          @for (ped of pedidos(); track ped.id) {
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
              <div class="flex flex-wrap items-center justify-between gap-4 pb-4 border-b border-slate-100">
                <div>
                  <span class="text-xs text-slate-400 font-mono">ORDEN #{{ ped.id }}</span>
                  <p class="text-xs text-slate-500 mt-0.5">{{ ped.creado_en }}</p>
                </div>

                <div class="flex items-center gap-3">
                  <span
                    class="px-3 py-1 rounded-full text-xs font-bold uppercase"
                    [class]="{
                      'bg-emerald-100 text-emerald-700': ped.estado === 'pagado' || ped.estado === 'enviado',
                      'bg-amber-100 text-amber-700': ped.estado === 'pendiente',
                      'bg-rose-100 text-rose-700': ped.estado === 'cancelado'
                    }"
                  >
                    {{ ped.estado }}
                  </span>

                  <span class="text-lg font-extrabold text-slate-900">\${{ ped.total }} MXN</span>
                </div>
              </div>

              <div class="pt-4 flex flex-wrap items-center justify-between gap-4 text-sm">
                <div class="text-slate-600 space-y-1">
                  <p>Subtotal: \${{ ped.subtotal }} | Envío: \${{ ped.costo_envio }}</p>
                  @if (ped.guia) {
                    <p class="font-mono text-indigo-600 font-semibold">Guía: {{ ped.guia }}</p>
                  }
                </div>

                <div class="flex gap-2">
                  <button
                    (click)="verComprobante(ped.id)"
                    class="px-3.5 py-1.5 text-xs font-semibold border border-slate-300 rounded-lg hover:bg-slate-50 cursor-pointer"
                  >
                    Ver Comprobante Pago
                  </button>

                  @if (ped.guia) {
                    <button
                      (click)="rastrearGuia(ped.guia)"
                      class="px-3.5 py-1.5 text-xs font-semibold bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg cursor-pointer"
                    >
                      Rastrear Envío
                    </button>
                  }
                </div>
              </div>

              <!-- Detalle de comprobante de pago -->
              @if (comprobanteActivo()?.pedido_id === ped.id) {
                <div class="mt-4 p-4 rounded-xl bg-slate-50 border border-slate-200 text-xs space-y-1">
                  <p class="font-bold text-slate-800">Comprobante de Transacción (api-pagos)</p>
                  <p>Referencia: <span class="font-mono">{{ comprobanteActivo()?.referencia }}</span></p>
                  <p>Método: {{ comprobanteActivo()?.metodo_pago }}</p>
                  <p>Estado pago: <span class="uppercase font-bold">{{ comprobanteActivo()?.estado }}</span></p>
                  <p>Monto: \${{ comprobanteActivo()?.monto }} {{ comprobanteActivo()?.moneda }}</p>
                </div>
              }

              <!-- Detalle de rastreo de envío -->
              @if (rastreoActivo()?.pedido_id === ped.id) {
                <div class="mt-4 p-4 rounded-xl bg-indigo-50 border border-indigo-200 text-xs space-y-1">
                  <p class="font-bold text-indigo-900">Estado Logístico del Paquete (api-envios)</p>
                  <p>Guía: <span class="font-mono font-semibold">{{ rastreoActivo()?.guia }}</span></p>
                  <p>Estado actual: <span class="uppercase font-bold">{{ rastreoActivo()?.estado }}</span></p>
                  <p>Tiempo estimado: {{ rastreoActivo()?.dias_estimados }} días</p>
                </div>
              }
            </div>
          }
        </div>
      }
    </div>
  `,
})
export class MisPedidosComponent implements OnInit {
  private tienda = inject(TiendaService);
  private auth = inject(AuthService);

  pedidos = signal<Pedido[]>([]);
  cargando = signal(true);
  errorMsg = signal<string | null>(null);

  comprobanteActivo = signal<ComprobantePago | null>(null);
  rastreoActivo = signal<RastreoEnvio | null>(null);

  ngOnInit(): void {
    const user = this.auth.usuario();
    if (!user) return;

    this.tienda.obtenerPedidosUsuario(user.id).subscribe({
      next: (res) => {
        this.pedidos.set(res.datos);
        this.cargando.set(false);
      },
      error: (err) => {
        this.errorMsg.set(err.error?.error?.mensaje || 'No se pudieron cargar tus pedidos');
        this.cargando.set(false);
      },
    });
  }

  verComprobante(pedidoId: number): void {
    this.tienda.obtenerComprobantePago(pedidoId).subscribe({
      next: (res) => this.comprobanteActivo.set(res.datos),
      error: (err) => alert(err.error?.error?.mensaje || 'Sin comprobante registrado para este pedido'),
    });
  }

  rastrearGuia(guia: string): void {
    this.tienda.rastrearEnvio(guia).subscribe({
      next: (res) => this.rastreoActivo.set(res.datos),
      error: (err) => alert(err.error?.error?.mensaje || 'No se encontró información de la guía'),
    });
  }
}