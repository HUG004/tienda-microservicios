import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { AuthService } from '../../core/services/auth.service';

@Component({
  selector: 'app-registro',
  standalone: true,
  imports: [FormsModule, RouterLink],
  template: `
    <div class="max-w-md mx-auto mt-10 bg-white p-8 rounded-2xl shadow-sm border border-slate-200">
      <h1 class="text-2xl font-bold text-slate-900 mb-2">Crear Cuenta</h1>
      <p class="text-sm text-slate-500 mb-6">Regístrate como cliente en TechStore.</p>

      @if (errorMsg()) {
        <div class="mb-4 p-3 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-sm">
          {{ errorMsg() }}
        </div>
      }

      @if (exitoMsg()) {
        <div class="mb-4 p-3 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm">
          {{ exitoMsg() }}
        </div>
      }

      <form (ngSubmit)="onSubmit()" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Nombre completo</label>
          <input
            type="text"
            [(ngModel)]="nombre"
            name="nombre"
            required
            maxlength="150"
            placeholder="Juan Perez"
            class="w-full px-3.5 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
          />
        </div>

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
          <label class="block text-sm font-medium text-slate-700 mb-1">Contraseña (mín. 8 caracteres)</label>
          <input
            type="password"
            [(ngModel)]="contrasena"
            name="contrasena"
            required
            minlength="8"
            placeholder="EjemploSeguro123!"
            class="w-full px-3.5 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
          />
        </div>

        <button
          type="submit"
          [disabled]="cargando()"
          class="w-full bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white font-semibold py-2.5 rounded-lg transition cursor-pointer"
        >
          {{ cargando() ? 'Registrando...' : 'Registrarme' }}
        </button>
      </form>

      <p class="mt-6 text-center text-sm text-slate-600">
        ¿Ya tienes cuenta?
        <a routerLink="/login" class="text-indigo-600 font-medium hover:underline">Inicia sesión</a>
      </p>
    </div>
  `,
})
export class RegistroComponent {
  private auth = inject(AuthService);
  private router = inject(Router);

  nombre = '';
  correo = '';
  contrasena = '';
  cargando = signal(false);
  errorMsg = signal<string | null>(null);
  exitoMsg = signal<string | null>(null);

  onSubmit(): void {
    if (this.contrasena.length < 8) {
      this.errorMsg.set('La contraseña debe tener al menos 8 caracteres.');
      return;
    }

    this.cargando.set(true);
    this.errorMsg.set(null);

    this.auth
      .registro({ nombre: this.nombre, correo: this.correo, contrasena: this.contrasena })
      .subscribe({
        next: () => {
          this.cargando.set(false);
          this.exitoMsg.set('Cuenta creada correctamente. Redirigiendo al login...');
          setTimeout(() => this.router.navigate(['/login']), 1500);
        },
        error: (err) => {
          this.cargando.set(false);
          this.errorMsg.set(err.error?.error?.mensaje || 'No se pudo completar el registro');
        },
      });
  }
}