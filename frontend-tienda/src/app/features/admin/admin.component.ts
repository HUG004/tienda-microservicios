import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { TiendaService } from '../../core/services/tienda.service';

@Component({
  selector: 'app-admin',
  standalone: true,
  imports: [FormsModule],
  template: `
    <div class="max-w-4xl mx-auto space-y-8">
      <div>
        <h1 class="text-3xl font-bold text-slate-900">Panel de Administración</h1>
        <p class="text-sm text-slate-500">Alta de productos en catálogo y reabastecimiento de almacén</p>
      </div>

      @if (mensaje()) {
        <div class="p-4 rounded-xl bg-indigo-50 border border-indigo-200 text-indigo-800 text-sm font-medium">
          {{ mensaje() }}
        </div>
      }

      <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
        <!-- Crear Producto (POST /api/productos) -->
        <form (ngSubmit)="crearProducto()" class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm space-y-4">
          <h2 class="font-bold text-lg text-slate-900">1. Crear Nuevo Producto</h2>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Nombre</label>
            <input type="text" [(ngModel)]="nombre" name="nombre" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm" />
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Descripción</label>
            <textarea [(ngModel)]="descripcion" name="descripcion" rows="2" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm"></textarea>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-semibold text-slate-700 mb-1">Precio (MXN)</label>
              <input type="text" [(ngModel)]="precio" name="precio" placeholder="899.00" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-700 mb-1">Categoría</label>
              <input type="text" [(ngModel)]="categoria" name="categoria" placeholder="Computacion" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm" />
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">URL de Imagen</label>
            <input type="text" [(ngModel)]="imagenUrl" name="imagenUrl" placeholder="https://placehold.co/400x300" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm" />
          </div>

          <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2.5 rounded-lg text-sm cursor-pointer">
            Registrar Producto
          </button>
        </form>

        <!-- Reabastecer Inventario (POST /api/inventario/reabastecer) -->
        <form (ngSubmit)="reabastecer()" class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm space-y-4 h-fit">
          <h2 class="font-bold text-lg text-slate-900">2. Reabastecer Inventario</h2>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">ID del Producto</label>
            <input type="number" [(ngModel)]="productoId" name="productoId" min="1" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm" />
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-700 mb-1">Cantidad a agregar</label>
            <input type="number" [(ngModel)]="cantidadStock" name="cantidadStock" min="1" required class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm" />
          </div>

          <button type="submit" class="w-full bg-slate-900 hover:bg-slate-800 text-white font-semibold py-2.5 rounded-lg text-sm cursor-pointer">
            Agregar Existencias
          </button>
        </form>
      </div>
    </div>
  `,
})
export class AdminComponent {
  private tienda = inject(TiendaService);
  mensaje = signal<string | null>(null);

  nombre = '';
  descripcion = '';
  precio = '';
  categoria = 'Computacion';
  imagenUrl = 'https://placehold.co/400x300?text=Producto';

  productoId = 1;
  cantidadStock = 10;

  crearProducto(): void {
    this.tienda
      .crearProducto({
        nombre: this.nombre,
        descripcion: this.descripcion,
        precio: this.precio,
        categoria: this.categoria,
        imagen_url: this.imagenUrl,
      })
      .subscribe({
        next: (res) => {
          this.mensaje.set(`Producto "${res.datos.nombre}" creado con ID #${res.datos.id}. Ahora asígnale stock.`);
          this.productoId = res.datos.id;
        },
        error: (err) => this.mensaje.set(err.error?.error?.mensaje || 'Error al crear producto'),
      });
  }

  reabastecer(): void {
    this.tienda.reabastecerInventario(Number(this.productoId), Number(this.cantidadStock)).subscribe({
      next: (res) =>
        this.mensaje.set(
          `Stock actualizado para producto #${res.datos.producto_id}: ${res.datos.stock_disponible} disponibles.`
        ),
      error: (err) => this.mensaje.set(err.error?.error?.mensaje || 'Error al reabastecer inventario'),
    });
  }
}