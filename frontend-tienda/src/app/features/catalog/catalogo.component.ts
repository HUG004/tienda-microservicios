import { Component, inject, OnInit, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { TiendaService } from '../../core/services/tienda.service';
import { AuthService } from '../../core/services/auth.service';
import { Categoria, Producto, PromedioProducto } from '../../core/models/api.models';

@Component({
  selector: 'app-catalogo',
  standalone: true,
  imports: [FormsModule, RouterLink],
  template: `
    <div>
      <!-- Encabezado y Filtros -->
      <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
        <div>
          <h1 class="text-3xl font-bold text-slate-900">Catálogo de Productos</h1>
          <p class="text-slate-500 text-sm mt-1">Explora nuestros artículos con disponibilidad en tiempo real</p>
        </div>

        <div class="flex flex-col sm:flex-row gap-3">
          <input
            type="text"
            [(ngModel)]="busqueda"
            (keyup.enter)="cargarProductos()"
            placeholder="Buscar teclado, mouse..."
            class="px-4 py-2 bg-white border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
          />

          <select
            [(ngModel)]="categoriaSeleccionada"
            (change)="cargarProductos()"
            class="px-4 py-2 bg-white border border-slate-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
          >
            <option [ngValue]="undefined">Todas las categorías</option>
            @for (cat of categorias(); track cat.id) {
              <option [ngValue]="cat.id">{{ cat.nombre }}</option>
            }
          </select>

          <button
            (click)="cargarProductos()"
            class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition cursor-pointer"
          >
            Filtrar
          </button>
        </div>
      </div>

      <!-- Notificación flotante -->
      @if (toastMsg()) {
        <div class="fixed bottom-5 right-5 bg-slate-900 text-white px-4 py-3 rounded-xl shadow-lg text-sm z-50">
          {{ toastMsg() }}
        </div>
      }

      <!-- Estados de Carga / Error -->
      @if (cargando()) {
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
          @for (item of [1, 2, 3, 4]; track item) {
            <div class="bg-white h-80 rounded-2xl border border-slate-200 animate-pulse"></div>
          }
        </div>
      } @else if (errorMsg()) {
        <div class="p-6 bg-rose-50 border border-rose-200 rounded-xl text-rose-700 text-center">
          {{ errorMsg() }}
        </div>
      } @else if (productos().length === 0) {
        <div class="p-12 bg-white border border-slate-200 rounded-2xl text-center text-slate-500">
          No se encontraron productos con los filtros seleccionados.
        </div>
      } @else {
        <!-- Cuadrícula de Productos -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
          @for (prod of productos(); track prod.id) {
            <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-sm hover:shadow-md transition flex flex-col">
              <a [routerLink]="['/producto', prod.id]" class="block h-48 bg-slate-100 overflow-hidden">
                <img
                  [src]="prod.imagen_url || 'https://placehold.co/400x300?text=Producto'"
                  [alt]="prod.nombre"
                  class="w-full h-full object-cover hover:scale-105 transition duration-300"
                />
              </a>

              <div class="p-5 flex-1 flex flex-col justify-between">
                <div>
                  <div class="flex items-center justify-between text-xs text-slate-500 mb-1">
                    <span class="bg-slate-100 px-2 py-0.5 rounded font-medium">{{ prod.categoria }}</span>
                    <span class="text-amber-500 font-semibold">
                      ★ {{ obtenerPromedio(prod.id) }} ({{ obtenerTotalResenas(prod.id) }})
                    </span>
                  </div>

                  <a [routerLink]="['/producto', prod.id]" class="font-bold text-slate-900 hover:text-indigo-600 line-clamp-1">
                    {{ prod.nombre }}
                  </a>
                  <p class="text-xs text-slate-500 mt-1 line-clamp-2">{{ prod.descripcion }}</p>
                </div>

                <div class="mt-4 pt-4 border-t border-slate-100 flex items-center justify-between">
                  <span class="text-lg font-extrabold text-slate-900">\${{ prod.precio }} <span class="text-xs font-normal text-slate-400">MXN</span></span>

                  <div class="flex gap-2">
                    <a
                      [routerLink]="['/producto', prod.id]"
                      class="px-3 py-1.5 text-xs font-medium border border-slate-300 rounded-lg hover:bg-slate-50 transition"
                    >
                      Ver
                    </a>
                    @if (auth.estaAutenticado()) {
                      <button
                        (click)="agregarRapido(prod)"
                        class="px-3 py-1.5 text-xs font-medium bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg transition cursor-pointer"
                      >
                        + Carrito
                      </button>
                    }
                  </div>
                </div>
              </div>
            </div>
          }
        </div>
      }
    </div>
  `,
})
export class CatalogoComponent implements OnInit {
  private tienda = inject(TiendaService);
  auth = inject(AuthService);

  productos = signal<Producto[]>([]);
  categorias = signal<Categoria[]>([]);
  promediosMap = signal<Record<number, PromedioProducto>>({});

  busqueda = '';
  categoriaSeleccionada?: number;
  cargando = signal(true);
  errorMsg = signal<string | null>(null);
  toastMsg = signal<string | null>(null);

  ngOnInit(): void {
    this.tienda.listarCategorias().subscribe({
      next: (res) => this.categorias.set(res.datos),
      error: () => {},
    });
    this.cargarProductos();
  }

  cargarProductos(): void {
    this.cargando.set(true);
    this.errorMsg.set(null);

    this.tienda.listarProductos(this.busqueda, this.categoriaSeleccionada).subscribe({
      next: (res) => {
        this.productos.set(res.datos);
        this.cargando.set(false);

        if (res.datos.length > 0) {
          const ids = res.datos.map((p) => p.id);
          this.tienda.obtenerPromediosProductos(ids).subscribe({
            next: (promRes) => {
              const map: Record<number, PromedioProducto> = {};
              for (const item of promRes.datos) {
                map[item.producto_id] = item;
              }
              this.promediosMap.set(map);
            },
            error: () => {},
          });
        }
      },
      error: (err) => {
        this.cargando.set(false);
        this.errorMsg.set(err.error?.error?.mensaje || 'No se pudo conectar con la API de Productos (puerto 8002)');
      },
    });
  }

  obtenerPromedio(productoId: number): string {
    const info = this.promediosMap()[productoId];
    return info ? info.promedio.toFixed(1) : '0.0';
  }

  obtenerTotalResenas(productoId: number): number {
    return this.promediosMap()[productoId]?.total_resenas || 0;
  }

  agregarRapido(prod: Producto): void {
    const user = this.auth.usuario();
    if (!user) return;

    this.tienda.agregarAlCarrito(user.id, prod.id, 1).subscribe({
      next: () => this.mostrarToast(`"${prod.nombre}" agregado al carrito`),
      error: (err) => this.mostrarToast(err.error?.error?.mensaje || 'No se pudo agregar al carrito'),
    });
  }

  private mostrarToast(msg: string): void {
    this.toastMsg.set(msg);
    setTimeout(() => this.toastMsg.set(null), 3000);
  }
}