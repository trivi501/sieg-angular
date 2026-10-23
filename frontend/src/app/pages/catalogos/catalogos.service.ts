import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';

import { environment } from 'src/environments/environment';
import { Paginado } from 'src/app/core/api';

export interface CatalogoResumen {
  slug: string;
  titulo: string;
  grupo: string;
  descripcion: string;
  total: number;
  activos: number | null;
}

export type TipoCampo = 'texto' | 'texto_largo' | 'entero' | 'fecha' | 'booleano' | 'seleccion' | 'relacion' | 'calculado';
export type Valor = string | number | boolean | null;

/** Campo de App\Catalogos\Campo::paraFrontend(). */
export interface CampoCatalogo {
  nombre: string;
  etiqueta: string;
  tipo: TipoCampo;
  requerido: boolean;
  max: number | null;
  enLista: boolean;
  editable: boolean;
  mayusculas: boolean;
  ayuda: string | null;
  porDefecto: Valor;
  opciones: { valor: string; etiqueta: string }[] | null;
}

export interface DefinicionCatalogo {
  slug: string;
  titulo: string;
  singular: string;
  grupo: string;
  descripcion: string;
  tieneActivo: boolean;
  campos: CampoCatalogo[];
  puede: { crear: boolean; editar: boolean; baja: boolean; importar: boolean };
}

export interface RegistroCatalogo {
  id: number | string;
  activo: boolean;
  valores: Record<string, Valor>;
  textos: Record<string, string | null>;
}

export interface Movimiento {
  id: number;
  fecha: string | null;
  usuario: string | null;
  accion: string;
  descripcion: string | null;
  antes: Record<string, unknown> | null;
  despues: Record<string, unknown> | null;
}

export interface ResumenImportacion {
  token: string;
  archivo: string;
  altas: number;
  modificaciones: number;
  sinCambios: number;
  errores: { fila: number; mensajes: string[] }[];
  totalErrores: number;
  faltantes: string[];
  columnasIgnoradas: string[];
}

interface Mensaje {
  message: string;
}

/** Llamadas a /api/catalogos (SIEG: CatalogoController). */
@Injectable({ providedIn: 'root' })
export class CatalogosService {
  private http = inject(HttpClient);
  private api = `${environment.apiUrl}/catalogos`;

  inicio() {
    return this.http.get<{ grupos: string[]; catalogos: CatalogoResumen[] }>(this.api);
  }

  definicion(slug: string) {
    return this.http.get<DefinicionCatalogo>(`${this.api}/${slug}/definicion`);
  }

  registros(slug: string, page: number, buscar: string, estado: string) {
    const params = new HttpParams().set('page', page).set('buscar', buscar).set('estado', estado);
    return this.http.get<Paginado<RegistroCatalogo>>(`${this.api}/${slug}`, { params });
  }

  guardar(slug: string, datos: Record<string, Valor>, id?: number | string) {
    return id !== undefined
      ? this.http.put<Mensaje>(`${this.api}/${slug}/${id}`, datos)
      : this.http.post<Mensaje>(`${this.api}/${slug}`, datos);
  }

  cambiarEstado(slug: string, id: number | string, activo: boolean) {
    return this.http.post<Mensaje>(`${this.api}/${slug}/${id}/estado`, { activo });
  }

  historial(slug: string, id: number | string) {
    return this.http.get<Movimiento[]>(`${this.api}/${slug}/${id}/historial`);
  }

  /** La descarga lleva el token, así que no puede ser un enlace directo: se baja como blob. */
  plantilla(slug: string) {
    return this.http.get(`${this.api}/${slug}/plantilla`, { responseType: 'blob' });
  }

  previa(slug: string, archivo: File) {
    const datos = new FormData();
    datos.append('archivo', archivo);
    return this.http.post<ResumenImportacion>(`${this.api}/${slug}/importar/previa`, datos);
  }

  importar(slug: string, token: string) {
    return this.http.post<Mensaje>(`${this.api}/${slug}/importar`, { token });
  }
}
