<script setup lang="ts">
import { computed } from 'vue'
import type { ReglaAreaComun } from '../../types'

const props = defineProps<{
  regla: ReglaAreaComun
  timezone: string
}>()

const days = [
  { value: 1, label: 'Lunes' },
  { value: 2, label: 'Martes' },
  { value: 3, label: 'Miércoles' },
  { value: 4, label: 'Jueves' },
  { value: 5, label: 'Viernes' },
  { value: 6, label: 'Sábado' },
  { value: 7, label: 'Domingo' }
]
const todayParts = Object.fromEntries(new Intl.DateTimeFormat('en', {
  timeZone: props.timezone,
  year: 'numeric',
  month: '2-digit',
  day: '2-digit'
}).formatToParts(new Date()).map(part => [part.type, part.value]))
const today = `${todayParts.year}-${todayParts.month}-${todayParts.day}`
const status = computed<{ label: string, color: 'primary' | 'success' | 'neutral' }>(() => {
  if (props.regla.vigenteDesde > today) return { label: 'Programada', color: 'primary' }
  if (props.regla.vigenteHasta && props.regla.vigenteHasta <= today) return { label: 'Histórica', color: 'neutral' }
  return { label: 'Vigente', color: 'success' }
})
const schedules = computed(() => days.map(day => ({
  ...day,
  intervals: props.regla.horarios
    .filter(schedule => schedule.diaSemana === day.value)
    .sort((left, right) => left.horaInicio.localeCompare(right.horaInicio))
})))
const formatDate = (value: string) => new Intl.DateTimeFormat('es-EC', { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${value}T00:00:00Z`))
</script>

<template>
  <UCard>
    <template #header>
      <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <p class="font-semibold text-highlighted">Desde {{ formatDate(regla.vigenteDesde) }}</p>
          <p class="mt-1 text-xs text-muted">{{ regla.vigenteHasta ? `Hasta ${formatDate(regla.vigenteHasta)} (fecha exclusiva)` : 'Sin fecha de cierre' }} · {{ timezone }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
          <UBadge :color="status.color" :label="status.label" variant="subtle" />
          <UBadge :color="regla.requiereAprobacion ? 'warning' : 'success'" :label="regla.requiereAprobacion ? 'Requiere aprobación' : 'Confirmación automática'" variant="outline" />
        </div>
      </div>
    </template>

    <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
      <div><dt class="text-xs uppercase text-muted">Duración</dt><dd class="mt-1 font-medium">{{ regla.duracionMinimaMinutos }} a {{ regla.duracionMaximaMinutos }} min</dd></div>
      <div><dt class="text-xs uppercase text-muted">Antelación</dt><dd class="mt-1 font-medium">{{ regla.antelacionMinimaMinutos }} min a {{ regla.antelacionMaximaDias }} días</dd></div>
      <div><dt class="text-xs uppercase text-muted">Bloqueos</dt><dd class="mt-1 font-medium">{{ regla.bufferAntesMinutos }} min antes · {{ regla.bufferDespuesMinutos }} min después</dd></div>
      <div><dt class="text-xs uppercase text-muted">Aforo máximo</dt><dd class="mt-1 font-medium">{{ regla.aforoMaximo }} personas</dd></div>
    </dl>

    <div class="mt-5 border-t border-default pt-5">
      <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-muted">Horarios por día</p>
      <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="day in schedules" :key="day.value" class="flex items-start justify-between gap-3 rounded-lg bg-elevated/40 px-3 py-2 text-sm">
          <span class="font-medium">{{ day.label }}</span>
          <span v-if="day.intervals.length" class="text-right text-muted">{{ day.intervals.map(item => `${item.horaInicio}-${item.horaFin}`).join(', ') }}</span>
          <span v-else class="text-dimmed">Cerrado</span>
        </div>
      </div>
    </div>

    <div v-if="regla.reglamento" class="mt-5 border-t border-default pt-5">
      <p class="text-xs font-semibold uppercase tracking-wide text-muted">Reglamento</p>
      <p class="mt-2 whitespace-pre-line text-sm leading-6 text-muted">{{ regla.reglamento }}</p>
    </div>
  </UCard>
</template>
