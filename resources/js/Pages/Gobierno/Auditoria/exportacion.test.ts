import { describe, expect, it } from 'vitest'
import { exportStatusLabel, hasActiveExports, utcInputToIso } from './exportacion'

describe('audit export UI helpers', () => {
  it('converts a datetime-local value explicitly as UTC', () => {
    expect(utcInputToIso('2026-10-22T09:30')).toBe('2026-10-22T09:30:00.000Z')
  })

  it('polls only while at least one export remains active', () => {
    expect(hasActiveExports([{ status: 'pendiente' }])).toBe(true)
    expect(hasActiveExports([{ status: 'procesando' }])).toBe(true)
    expect(hasActiveExports([{ status: 'completada' }])).toBe(false)
  })

  it('has a user-facing label for every durable state', () => {
    expect(Object.keys(exportStatusLabel)).toEqual(['pendiente', 'procesando', 'completada', 'fallida', 'expirada'])
  })
})
