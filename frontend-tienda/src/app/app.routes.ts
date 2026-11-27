import { Routes } from '@angular/router';
import { adminGuard, authGuard } from './core/guards/auth.guard';
import { CatalogoComponent } from './features/catalog/catalogo.component';
import { ProductoDetalleComponent } from './features/catalog/producto-detalle.component';
import { LoginComponent } from './features/auth/login.component';
import { RegistroComponent } from './features/auth/registro.component';
import { PerfilComponent } from './features/auth/perfil.component';
import { CarritoComponent } from './features/cart/carrito.component';
import { MisPedidosComponent } from './features/orders/mis-pedidos.component';
import { AdminComponent } from './features/admin/admin.component';

export const routes: Routes = [
  { path: '', component: CatalogoComponent },
  { path: 'producto/:id', component: ProductoDetalleComponent },
  { path: 'login', component: LoginComponent },
  { path: 'registro', component: RegistroComponent },
  { path: 'perfil', component: PerfilComponent, canActivate: [authGuard] },
  { path: 'carrito', component: CarritoComponent, canActivate: [authGuard] },
  { path: 'mis-pedidos', component: MisPedidosComponent, canActivate: [authGuard] },
  { path: 'admin', component: AdminComponent, canActivate: [adminGuard] },
  { path: '**', redirectTo: '' },
];