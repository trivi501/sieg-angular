import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';

import { environment } from 'src/environments/environment';
import { Paginado } from 'src/app/core/api';

export interface UsuarioFila {
  id: number;
  name: string;
  cargo: string | null;
  regidor: string | null;
  email: string;
  unidad: string | null;
  roles: string[];
  created_at: string | null;
}

export interface UsuarioDetalle {
  id?: number;
  name: string;
  titulo: string | null;
  cargo: string | null;
  email: string;
  password?: string;
  pertenece: 'secretaria' | 'regidor';
  id_cat_egreso_unidad_administrativa: number | null;
  regidor_id: number | null;
  roles: number[];
}

export interface OpcionesUsuario {
  roles: { id: number; name: string }[];
  unidades: { id: number; nombre: string }[];
  regidores: { id: number; nombre: string }[];
}

export interface RolFila {
  id: number;
  name: string;
  permissions_count: number;
  users_count: number;
}

export interface RolDetalle {
  id?: number;
  name: string;
  permissions: number[];
}

export interface Permiso {
  id: number;
  name: string;
  nombre_mostrar: string | null;
  categoria: string | null;
}

interface Mensaje {
  message: string;
}

/** Llamadas a /api/users, /api/roles y /api/permissions. */
@Injectable({ providedIn: 'root' })
export class AdminService {
  private http = inject(HttpClient);
  private api = environment.apiUrl;

  usuarios(page: number, q: string) {
    const params = new HttpParams().set('page', page).set('q', q);
    return this.http.get<Paginado<UsuarioFila>>(`${this.api}/users`, { params });
  }

  usuario(id: number) {
    return this.http.get<UsuarioDetalle>(`${this.api}/users/${id}`);
  }

  opcionesUsuario(id?: number) {
    const params = id ? new HttpParams().set('user', id) : undefined;
    return this.http.get<OpcionesUsuario>(`${this.api}/users/opciones`, { params });
  }

  guardarUsuario(datos: UsuarioDetalle, id?: number) {
    return id ? this.http.put<Mensaje>(`${this.api}/users/${id}`, datos) : this.http.post<Mensaje>(`${this.api}/users`, datos);
  }

  eliminarUsuario(id: number) {
    return this.http.delete<Mensaje>(`${this.api}/users/${id}`);
  }

  roles() {
    return this.http.get<RolFila[]>(`${this.api}/roles`);
  }

  rol(id: number) {
    return this.http.get<RolDetalle>(`${this.api}/roles/${id}`);
  }

  permisosParaRol() {
    return this.http.get<{ permissions: Permiso[] }>(`${this.api}/roles/opciones`);
  }

  guardarRol(datos: RolDetalle, id?: number) {
    return id ? this.http.put<Mensaje>(`${this.api}/roles/${id}`, datos) : this.http.post<Mensaje>(`${this.api}/roles`, datos);
  }

  eliminarRol(id: number) {
    return this.http.delete<Mensaje>(`${this.api}/roles/${id}`);
  }

  permisos(page: number, q: string, categoria: string) {
    const params = new HttpParams().set('page', page).set('q', q).set('categoria', categoria);
    return this.http.get<Paginado<Permiso> & { categorias: string[] }>(`${this.api}/permissions`, { params });
  }

  guardarPermiso(datos: Omit<Permiso, 'id'>, id?: number) {
    return id
      ? this.http.put<Mensaje>(`${this.api}/permissions/${id}`, datos)
      : this.http.post<Mensaje>(`${this.api}/permissions`, datos);
  }

  eliminarPermiso(id: number) {
    return this.http.delete<Mensaje>(`${this.api}/permissions/${id}`);
  }
}
