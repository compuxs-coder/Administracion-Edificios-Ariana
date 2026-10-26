import type { ExportacionAuditoriaResumen } from '../../../types'

export const utcInputToIso = (value: string): string => {
  const normalized = value.length === 16 ? `${value}:00Z` : `${value}Z`
  const date = new Date(normalized)
  if (Number.isNaN(date.getTime())) throw new Error('Fecha UTC inválida')

  return date.toISOString()
}

export const hasActiveExports = (exports: Array<Pick<ExportacionAuditoriaResumen, 'status'>>): boolean =>
  exports.some(item => item.status === 'pendiente' || item.status === 'procesando')

export const exportStatusLabel: Record<ExportacionAuditoriaResumen['status'], string> = {
  pendiente: 'En cola',
  procesando: 'Procesando',
  completada: 'Lista',
  fallida: 'Fallida',
  expirada: 'Expirada'
}
