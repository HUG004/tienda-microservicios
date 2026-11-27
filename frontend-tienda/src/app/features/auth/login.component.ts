import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/services/auth.service';
import { TiendaService } from '../../core/services/tienda.service';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [FormsModule, RouterLink],
  template: `
    <div class="max-w-md mx-auto mt-10 bg-white p-8 rounded-2xl shadow-sm border border-slate-200">
      <h1 class="text-2xl font-bold text-slate-900 mb-2">Iniciar Sesión</h1>
      <p class="text-sm text-slate-500 mb-6">Accede a tu cuenta para comprar y gestionar pedidos.</p>

      @if (errorMsg()) {
        <div class="mb-4 p-3 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-sm">
          {{ errorMsg() }}
        </div>
      }

      <form (ngSubmit)="onSubmit()" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Correo electrónico</label>
          <input
            type="email"
            [(ngModel)]="correo"
            name="correo"
            required
            placeholder="juan@example.com"
            class="w-full px-3.5 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
          />
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Contraseña</label>
          <input
            type="password"
            [(ngModel)]="contrasena"
            name="contrasena"
            required
            placeholder="••••••••"
            class="w-full px-3.5 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
          />
        </div>

        <button
          type="submit"
          [disabled]="cargando()"
          class="w-full bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white font-semibold py-2.5 rounded-lg transition cursor-pointer"
        >
          {{ cargando() ? 'Ingresando...' : 'Entrar' }}
        </button>
      </form>

      <p class="mt-6 text-center text-sm text-slate-600">
        ¿No tienes cuenta?
        <a routerLink="/registro" class="text-indigo-600 font-medium hover:underline">Regístrate aquí</a>
      </p>
    </div>
  `,
})
export class LoginComponent {
  private auth = inject(AuthService);
  private tienda = inject(TiendaService);
  private router = inject(Router);

  correo = '';
  contrasena = '';
  cargando = signal(false);
  errorMsg = signal<string | null>(null);

  onSubmit(): void {
    if (!this.correo || !this.contrasena) return;
    this.cargando.set(true);
    this.errorMsg.set(null);

    this.auth.login({ correo: this.correo, contrasena: this.contrasena }).subscribe({
      next: (res) => {
        this.tienda.obtenerCarrito(res.datos.usuario.id).subscribe({ error: () => {} });
        this.cargando.set(false);
        this.router.navigate(['/']);
      },
      error: (err) => {
        this.cargando.set(false);
        this.errorMsg.set(err.error?.error?.mensaje || 'Credenciales inválidas o servicio no disponible');
      },
    });
  }
}