import { Component, inject, OnInit, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { TiendaService } from '../../core/services/tienda.service';
import { AuthService } from '../../core/services/auth.service';
import {
  InventarioProducto,
  Producto,
  ResenasProductoData,
} from '../../core/models/api.models';

@Component({
  selector: 'app-producto-detalle',
  standalone: true,
  imports: [RouterLink, FormsModule],
  template: `
    <div class="max-w-5xl mx-auto">
      <a routerLink="/" class="inline-flex items-center text-sm text-indigo-600 hover:underline mb-6">
        ← Volver al catálogo
      </a>

      @if (cargando()) {
        <div class="bg-white h-96 rounded-2xl border border-slate-200 animate-pulse"></div>
      } @else if (errorMsg()) {
        <div class="p-6 bg-rose-50 border border-rose-200 rounded-xl text-rose-700">
          {{ errorMsg() }}
        </div>
      } @else if (producto()) {
        <!-- Ficha del Producto + Inventario -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden grid grid-cols-1 md:grid-cols-2 mb-8">
          <div class="bg-slate-100 h-80 md:h-auto">
            <img
              [src]="producto()?.imagen_url || 'https://placehold.co/600x500?text=Producto'"
              [alt]="producto()?.nombre"
              class="w-full h-full object-cover"
            />
          </div>

          <div class="p-8 flex flex-col justify-between">
            <div>
              <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-semibold uppercase tracking-wider bg-indigo-50 text-indigo-700 px-3 py-1 rounded-full">
                  {{ producto()?.categoria }}
                </span>
                <span class="text-amber-500 font-bold text-sm">
                  ★ {{ resenasData()?.promedio || 0 }} ({{ resenasData()?.total_resenas || 0 }} reseñas)
                </span>
              </div>

              <h1 class="text-3xl font-bold text-slate-900 mt-2">{{ producto()?.nombre }}</h1>
              <p class="text-slate-600 mt-4 leading-relaxed">{{ producto()?.descripcion }}</p>

              <div class="mt-6">
                <span class="text-3xl font-extrabold text-slate-900">\${{ producto()?.precio }}</span>
                <span class="text-sm text-slate-500 ml-1">MXN</span>
              </div>

              <!-- Disponibilidad en tiempo real (api-inventario) -->
              <div class="mt-4 p-3 rounded-xl bg-slate-50 border border-slate-200 text-sm">
                @if (inventario()) {
                  @if ((inventario()?.stock_disponible || 0) > 0) {
                    <span class="text-emerald-700 font-semibold">
                      ● Disponible en almacén: {{ inventario()?.stock_disponible }} unidades
                    </span>
                  } @else {
                    <span class="text-rose-600 font-semibold">● Agotado temporalmente</span>
                  }
                } @else {
                  <span class="text-slate-500">Consultando disponibilidad...</span>
                }
              </div>
            </div>

            <!-- Selector de cantidad y botón agregar -->
            <div class="mt-8 pt-6 border-t border-slate-100">
              @if (mensajeCarrito()) {
                <div class="mb-3 p-2.5 rounded-lg bg-indigo-50 text-indigo-700 text-sm font-medium">
                  {{ mensajeCarrito() }}
                </div>
              }

              @if (auth.estaAutenticado()) {
                <div class="flex items-center gap-4">
                  <input
                    type="number"
                    [(ngModel)]="cantidad"
                    min="1"
                    [max]="inventario()?.stock_disponible || 1"
                    class="w-24 px-3 py-2.5 border border-slate-300 rounded-lg text-center font-semibold"
                  />
                  <button
                    (click)="agregarAlCarrito()"
                    [disabled]="(inventario()?.stock_disponible || 0) <= 0 || agregando()"
                    class="flex-1 bg-indigo-600 hover:bg-indigo-700 disabled:bg-slate-300 text-white font-semibold py-2.5 px-6 rounded-lg transition cursor-pointer"
                  >
                    {{ agregando() ? 'Agregando...' : 'Agregar al Carrito' }}
                  </button>
                </div>
              } @else {
                <a
                  routerLink="/login"
                  class="block text-center w-full bg-slate-900 hover:bg-slate-800 text-white font-semibold py-3 rounded-lg transition"
                >
                  Inicia sesión para comprar
                </a>
              }
            </div>
          </div>
        </div>

        <!-- Sección de Reseñas (api-resenas) -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-8">
          <h2 class="text-xl font-bold text-slate-900 mb-6">Reseñas de Clientes</h2>

          @if (auth.estaAutenticado()) {
            <form (ngSubmit)="enviarResena()" class="mb-8 p-5 bg-slate-50 rounded-xl border border-slate-200 space-y-4">
              <h3 class="font-semibold text-slate-800 text-sm">Escribe tu opinión sobre este producto</h3>

              @if (resenaMsg()) {
                <div class="p-2.5 rounded bg-amber-50 border border-amber-200 text-amber-800 text-sm">
                  {{ resenaMsg() }}
                </div>
              }

              <div class="flex items-center gap-4">
                <label class="text-sm font-medium text-slate-700">Calificación:</label>
                <select
                  [(ngModel)]="calificacion"
                  name="calificacion"
                  class="px-3 py-1.5 bg-white border border-slate-300 rounded-lg text-sm"
                >
                  <option [ngValue]="5">★★★★★ (5 - Excelente)</option>
                  <option [ngValue]="4">★★★★☆ (4 - Muy bueno)</option>
                  <option [ngValue]="3">★★★☆☆ (3 - Regular)</option>
                  <option [ngValue]="2">★★☆☆☆ (2 - Malo)</option>
                  <option [ngValue]="1">★☆☆☆☆ (1 - Muy malo)</option>
                </select>
              </div>

              <textarea
                [(ngModel)]="comentario"
                name="comentario"
                rows="3"
                required
                maxlength="1000"
                placeholder="Comparte tu experiencia con el producto..."
                class="w-full px-3.5 py-2 bg-white border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
              ></textarea>

              <button
                type="submit"
                [disabled]="enviandoResena()"
                class="bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold px-5 py-2 rounded-lg transition cursor-pointer"
              >
                {{ enviandoResena() ? 'Publicando...' : 'Publicar Reseña' }}
              </button>
            </form>
          }

          @if (resenasData()?.resenas?.length) {
            <div class="space-y-4">
              @for (rev of resenasData()?.resenas; track rev.id) {
                <div class="p-4 border border-slate-100 rounded-xl">
                  <div class="flex items-center justify-between text-xs text-slate-500 mb-1">
                    <span class="font-semibold text-slate-700">Usuario #{{ rev.usuario_id }}</span>
                    <span class="text-amber-500 font-bold">★ {{ rev.calificacion }} / 5</span>
                  </div>
                  <p class="text-slate-700 text-sm">{{ rev.comentario }}</p>
                </div>
              }
            </div>
          } @else {
            <p class="text-slate-500 text-sm">Aún no hay reseñas para este producto. ¡Sé el primero en comentar!</p>
          }
        </div>
      }
    </div>
  `,
})
export class ProductoDetalleComponent implements OnInit {
  private route = inject(ActivatedRoute);
  private tienda = inject(TiendaService);
  auth = inject(AuthService);

  producto = signal<Producto | null>(null);
  inventario = signal<InventarioProducto | null>(null);
  resenasData = signal<ResenasProductoData | null>(null);

  cargando = signal(true);
  errorMsg = signal<string | null>(null);
  cantidad = 1;
  agregando = signal(false);
  mensajeCarrito = signal<string | null>(null);

  calificacion = 5;
  comentario = '';
  enviandoResena = signal(false);
  resenaMsg = signal<string | null>(null);

  ngOnInit(): void {
    const id = Number(this.route.snapshot.paramMap.get('id'));
    if (!id) {
      this.errorMsg.set('ID de producto inválido');
      this.cargando.set(false);
      return;
    }

    this.tienda.obtenerProducto(id).subscribe({
      next: (res) => {
        this.producto.set(res.datos);
        this.cargando.set(false);
      },
      error: (err) => {
        this.errorMsg.set(err.error?.error?.mensaje || 'Producto no encontrado');
        this.cargando.set(false);
      },
    });

    this.tienda.consultarInventario(id).subscribe({
      next: (res) => this.inventario.set(res.datos),
      error: () => {},
    });

    this.cargarResenas(id);
  }

  cargarResenas(productoId: number): void {
    this.tienda.obtenerResenasProducto(productoId).subscribe({
      next: (res) => this.resenasData.set(res.datos),
      error: () => {},
    });
  }

  agregarAlCarrito(): void {
    const user = this.auth.usuario();
    const prod = this.producto();
    if (!user || !prod) return;

    this.agregando.set(true);
    this.tienda.agregarAlCarrito(user.id, prod.id, this.cantidad).subscribe({
      next: () => {
        this.agregando.set(false);
        this.mensajeCarrito.set('Producto agregado correctamente al carrito.');
      },
      error: (err) => {
        this.agregando.set(false);
        this.mensajeCarrito.set(err.error?.error?.mensaje || 'No se pudo agregar al carrito');
      },
    });
  }

  enviarResena(): void {
    const user = this.auth.usuario();
    const prod = this.producto();
    if (!user || !prod || !this.comentario.trim()) return;

    this.enviandoResena.set(true);
    this.resenaMsg.set(null);

    this.tienda
      .crearResena({
        producto_id: prod.id,
        usuario_id: user.id,
        calificacion: Number(this.calificacion),
        comentario: this.comentario.trim(),
      })
      .subscribe({
        next: () => {
          this.enviandoResena.set(false);
          this.comentario = '';
          this.resenaMsg.set('Reseña publicada correctamente.');
          this.cargarResenas(prod.id);
        },
        error: (err) => {
          this.enviandoResena.set(false);
          this.resenaMsg.set(err.error?.error?.mensaje || 'No se pudo publicar la reseña');
        },
      });
  }
}