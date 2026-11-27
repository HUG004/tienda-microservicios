import { Injectable, inject, signal, computed } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { tap } from 'rxjs';
import { environment } from '../../../environments/environment';
import { ApiResponse, LoginResponseData, Usuario } from '../models/api.models';

@Injectable({ providedIn: 'root' })
export class AuthService {
  private http = inject(HttpClient);
  private router = inject(Router);
  private baseUrl = environment.apiUrls.usuarios;

  // Estado reactivo con Signals de Angular 21
  private _usuario = signal<Usuario | null>(this.cargarUsuarioGuardado());
  private _token = signal<string | null>(localStorage.getItem('auth_token'));

  readonly usuario = this._usuario.asReadonly();
  readonly token = this._token.asReadonly();
  readonly estaAutenticado = computed(() => !!this._token() && !!this._usuario());
  readonly esAdmin = computed(() => this._usuario()?.rol === 'admin');

  private cargarUsuarioGuardado(): Usuario | null {
    const raw = localStorage.getItem('auth_user');
    if (!raw) return null;
    try {
      return JSON.parse(raw) as Usuario;
    } catch {
      return null;
    }
  }

  registro(datos: { nombre: string; correo: string; contrasena: string }) {
    return this.http.post<ApiResponse<Usuario>>(`${this.baseUrl}/registro`, datos);
  }

  login(credenciales: { correo: string; contrasena: string }) {
    return this.http
      .post<ApiResponse<LoginResponseData>>(`${this.baseUrl}/login`, credenciales)
      .pipe(
        tap((res) => {
          const { token, usuario } = res.datos;
          localStorage.setItem('auth_token', token);
          localStorage.setItem('auth_user', JSON.stringify(usuario));
          this._token.set(token);
          this._usuario.set(usuario);
        })
      );
  }

  obtenerPerfilMe() {
    return this.http.get<ApiResponse<Usuario>>(`${this.baseUrl}/me`).pipe(
      tap((res) => {
        localStorage.setItem('auth_user', JSON.stringify(res.datos));
        this._usuario.set(res.datos);
      })
    );
  }

  obtenerPerfilPorId(id: number) {
    return this.http.get<ApiResponse<Usuario>>(`${this.baseUrl}/${id}`);
  }

  cerrarSesion(): void {
    localStorage.removeItem('auth_token');
    localStorage.removeItem('auth_user');
    this._token.set(null);
    this._usuario.set(null);
    this.router.navigate(['/login']);
  }
}