import { HttpErrorResponse } from '@angular/common/http';

/** Respuesta paginada de Laravel (`->paginate()`). */
export interface Paginado<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

/** Errores de validación de Laravel (422) por campo; el resto, como mensaje general. */
export function erroresDe(error: HttpErrorResponse): { campos: Record<string, string>; mensaje: string } {
  const campos: Record<string, string> = {};
  const errores = (error.error?.errors ?? {}) as Record<string, string[]>;
  for (const [campo, mensajes] of Object.entries(errores)) {
    campos[campo] = mensajes[0];
  }
  const mensaje = Object.keys(campos).length ? '' : (error.error?.message ?? 'No se pudo completar la operación.');
  return { campos, mensaje };
}
