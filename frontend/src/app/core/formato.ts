const formatoNumero = new Intl.NumberFormat('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** Importe en pesos: $1,234.56 y -$1,234.56 (SIEG: lib/presupuesto.ts → money). */
export function money(n: number | string | null | undefined): string {
  const valor = Number(n ?? 0);
  return (valor < 0 ? '-$' : '$') + formatoNumero.format(Math.abs(valor));
}

/** Centavos enteros, para comparar importes sin errores de punto flotante (SIEG: DistribucionMensual). */
export function centavos(n: number | string | null | undefined): number {
  return Math.round(Number(n || 0) * 100);
}
