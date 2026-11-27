import { Component, inject, OnInit, signal } from '@angular/core';
import { AuthService } from '../../core/services/auth.service';
import { Usuario } from '../../core/models/api.models';

@Component({
  selector: 'app-perfil',
  standalone: true,
  template: `
    <div class="max-w-lg mx-auto bg-white p-8 rounded-2xl shadow-sm border border-slate-200">
      <h1 class="text-2xl font-bold text-slate-900 mb-6">Mi Perfil</h1>

      @if (perfil()) {
        <div class="space-y-4">
          <div class="flex justify-between py-2 border-b border-slate-100">
            <span class="text-slate-500">ID de Usuario</span>
            <span class="font-mono font-semibold">#{{ perfil()?.id }}</span>
          </div>
          <div class="flex justify-between py-2 border-b border-slate-100">
            <span class="text-slate-500">Nombre</span>
            <span class="font-semibold">{{ perfil()?.nombre }}</span>
          </div>
          <div class="flex justify-between py-2 border-b border-slate-100">
            <span class="text-slate-500">Correo</span>
            <span>{{ perfil()?.correo }}</span>
          </div>
          <div class="flex justify-between py-2 border-b border-slate-100">
            <span class="text-slate-500">Rol</span>
            <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold uppercase bg-indigo-100 text-indigo-700">
              {{ perfil()?.rol }}
            </span>
          </div>
        </div>
      } @else {
        <p class="text-slate-500">Cargando perfil...</p>
      }
    </div>
  `,
})
export class PerfilComponent implements OnInit {
  private auth = inject(AuthService);
  perfil = signal<Usuario | null>(this.auth.usuario());

  ngOnInit(): void {
    this.auth.obtenerPerfilMe().subscribe({
      next: (res) => this.perfil.set(res.datos),
      error: () => {
        const current = this.auth.usuario();
        if (current) {
          this.auth.obtenerPerfilPorId(current.id).subscribe({
            next: (res) => this.perfil.set(res.datos),
          });
        }
      },
    });
  }
}