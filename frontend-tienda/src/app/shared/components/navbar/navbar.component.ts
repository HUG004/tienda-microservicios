import { Component, inject, OnInit } from '@angular/core';
import { RouterLink, RouterLinkActive } from '@angular/router';
import { AuthService } from '../../../core/services/auth.service';
import { TiendaService } from '../../../core/services/tienda.service';

@Component({
  selector: 'app-navbar',
  standalone: true,
  imports: [RouterLink, RouterLinkActive],
  template: `
    <nav class="bg-slate-900 text-white shadow-md sticky top-0 z-50">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16 items-center">
          <!-- Logo / Marca -->
          <a routerLink="/" class="flex items-center gap-2 font-bold text-xl tracking-tight text-indigo-400 hover:text-indigo-300">
            <span>🛒 TechStore</span>
            <span class="text-xs bg-indigo-500/20 text-indigo-300 px-2 py-0.5 rounded-full border border-indigo-500/30">
              Microservicios
            </span>
          </a>

          <!-- Enlaces principales -->
          <div class="flex items-center gap-4 sm:gap-6 text-sm font-medium">
            <a
              routerLink="/"
              routerLinkActive="text-indigo-400"
              [routerLinkActiveOptions]="{ exact: true }"
              class="hover:text-indigo-300 transition"
            >
              Catálogo
            </a>

            @if (auth.estaAutenticado()) {
              <a
                routerLink="/mis-pedidos"
                routerLinkActive="text-indigo-400"
                class="hover:text-indigo-300 transition"
              >
                Mis Pedidos
              </a>

              @if (auth.esAdmin()) {
                <a
                  routerLink="/admin"
                  routerLinkActive="text-amber-400"
                  class="text-amber-300 hover:text-amber-200 transition"
                >
                  Admin
                </a>
              }

              <a
                routerLink="/carrito"
                routerLinkActive="text-indigo-400"
                class="relative flex items-center gap-1 bg-slate-800 hover:bg-slate-700 px-3 py-1.5 rounded-lg transition"
              >
                <span>Carrito</span>
                <span class="bg-indigo-600 text-white text-xs font-bold px-2 py-0.5 rounded-full">
                  {{ tienda.carritoCantidad() }}
                </span>
              </a>

              <a
                routerLink="/perfil"
                routerLinkActive="text-indigo-400"
                class="hidden sm:inline text-slate-300 hover:text-white transition"
              >
                {{ auth.usuario()?.nombre }}
              </a>

              <button
                (click)="auth.cerrarSesion()"
                class="text-rose-400 hover:text-rose-300 transition cursor-pointer"
              >
                Salir
              </button>
            } @else {
              <a
                routerLink="/login"
                routerLinkActive="text-indigo-400"
                class="hover:text-indigo-300 transition"
              >
                Iniciar Sesión
              </a>
              <a
                routerLink="/registro"
                class="bg-indigo-600 hover:bg-indigo-500 text-white px-3.5 py-1.5 rounded-lg transition"
              >
                Registrarse
              </a>
            }
          </div>
        </div>
      </div>
    </nav>
  `,
})
export class NavbarComponent implements OnInit {
  auth = inject(AuthService);
  tienda = inject(TiendaService);

  ngOnInit(): void {
    const user = this.auth.usuario();
    if (user) {
      this.tienda.obtenerCarrito(user.id).subscribe({ error: () => {} });
    }
  }
}