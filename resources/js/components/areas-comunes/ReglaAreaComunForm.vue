<script setup lang="ts">
import { reactive } from 'vue'
import type { HorarioReglaAreaComunFormData, ReglaAreaComunFormData } from '../../types'

const props = withDefaults(defineProps<{
  timezone: string
  capacidad: number
  minimumDate: string
  errors?: Record<string, string>
  loading?: boolean
}>(), {
  errors: () => ({}),
  loading: false
})

const emit = defineEmits<{
  submit: [data: ReglaAreaComunFormData]
  cancel: []
}>()

const state = reactive<ReglaAreaComunFormData>({
  vigente_desde: props.minimumDate,
  requiere_aprobacion: true,
  duracion_minima_minutos: 60,
  duracion_maxima_minutos: 240,
  antelacion_minima_minutos: 60,
  antelacion_maxima_dias: 30,
  buffer_antes_minutos: 0,
  buffer_despues_minutos: 0,
  aforo_maximo: props.capacidad,
  reglamento: '',
  horarios: [{ dia_semana: 1, hora_inicio: '08:00', hora_fin: '20:00' }]
})
const dayItems = [
  { label: 'Lunes', value: 1 },
  { label: 'Martes', value: 2 },
  { label: 'Miércoles', value: 3 },
  { label: 'Jueves', value: 4 },
  { label: 'Viernes', value: 5 },
  { label: 'Sábado', value: 6 },
  { label: 'Domingo', value: 7 }
]

const addSchedule = () => state.horarios.push({ dia_semana: 1, hora_inicio: '08:00', hora_fin: '20:00' })
const removeSchedule = (index: number) => {
  if (state.horarios.length > 1) state.horarios.splice(index, 1)
}
const scheduleError = (index: number, field: 'diaSemana' | 'horaInicio' | 'horaFin') => props.errors[`horarios.${index}.${field}`]
const submit = () => emit('submit', {
  ...state,
  horarios: state.horarios.map((schedule): HorarioReglaAreaComunFormData => ({ ...schedule }))
})
</script>

<template>
  <form class="space-y-6" @submit.prevent="submit">
    <UAlert v-if="errors.horarios" color="error" :description="errors.horarios" icon="i-lucide-circle-alert" variant="subtle" />
    <div class="grid gap-4 sm:grid-cols-2">
      <UFormField label="Vigente desde" name="vigente_desde" required :error="errors.vigenteDesde">
        <UInput v-model="state.vigente_desde" class="w-full" type="date" :min="minimumDate" required size="xl" />
      </UFormField>
      <UFormField label="Aforo máximo" name="aforo_maximo" required :error="errors.aforoMaximo">
        <UInput v-model.number="state.aforo_maximo" class="w-full" type="number" min="1" :max="capacidad" required size="xl" />
      </UFormField>
      <UFormField label="Duración mínima (minutos)" name="duracion_minima_minutos" required :error="errors.duracionMinimaMinutos">
        <UInput v-model.number="state.duracion_minima_minutos" class="w-full" type="number" min="1" max="1440" required size="xl" />
      </UFormField>
      <UFormField label="Duración máxima (minutos)" name="duracion_maxima_minutos" required :error="errors.duracionMaximaMinutos">
        <UInput v-model.number="state.duracion_maxima_minutos" class="w-full" type="number" :min="state.duracion_minima_minutos" max="1440" required size="xl" />
      </UFormField>
      <UFormField label="Antelación mínima (minutos)" name="antelacion_minima_minutos" required :error="errors.antelacionMinimaMinutos">
        <UInput v-model.number="state.antelacion_minima_minutos" class="w-full" type="number" min="0" :max="Math.min(43200, state.antelacion_maxima_dias * 1440)" required size="xl" />
      </UFormField>
      <UFormField label="Antelación máxima (días)" name="antelacion_maxima_dias" required :error="errors.antelacionMaximaDias">
        <UInput v-model.number="state.antelacion_maxima_dias" class="w-full" type="number" min="1" max="365" required size="xl" />
      </UFormField>
      <UFormField label="Preparación previa (minutos)" name="buffer_antes_minutos" required :error="errors.bufferAntesMinutos">
        <UInput v-model.number="state.buffer_antes_minutos" class="w-full" type="number" min="0" max="1440" required size="xl" />
      </UFormField>
      <UFormField label="Limpieza posterior (minutos)" name="buffer_despues_minutos" required :error="errors.bufferDespuesMinutos">
        <UInput v-model.number="state.buffer_despues_minutos" class="w-full" type="number" min="0" max="1440" required size="xl" />
      </UFormField>
    </div>

    <div class="rounded-xl border border-default p-4">
      <UCheckbox v-model="state.requiere_aprobacion" label="Las solicitudes requieren aprobación" />
      <p class="mt-1 pl-6 text-xs text-muted">Si se desactiva, una solicitud válida se confirma inmediatamente.</p>
      <p v-if="errors.requiereAprobacion" class="mt-2 text-sm text-error">{{ errors.requiereAprobacion }}</p>
    </div>

    <section class="space-y-4 border-t border-default pt-5">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p class="text-sm font-semibold text-highlighted">Horarios habilitados</p>
          <p class="mt-1 text-xs text-muted">Puede añadir varios intervalos por día; no deben solaparse.</p>
        </div>
        <UButton color="neutral" icon="i-lucide-plus" label="Añadir horario" type="button" variant="outline" @click="addSchedule" />
      </div>
      <div class="space-y-3">
        <div v-for="(schedule, index) in state.horarios" :key="index" class="grid gap-3 rounded-xl bg-elevated/40 p-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-start">
          <UFormField label="Día" required :error="scheduleError(index, 'diaSemana')">
            <USelect v-model="schedule.dia_semana" class="w-full" :items="dayItems" required />
          </UFormField>
          <UFormField label="Desde" required :error="scheduleError(index, 'horaInicio')">
            <UInput v-model="schedule.hora_inicio" class="w-full" type="time" required />
          </UFormField>
          <UFormField label="Hasta" required :error="scheduleError(index, 'horaFin')">
            <UInput v-model="schedule.hora_fin" class="w-full" type="time" required />
          </UFormField>
          <UButton class="sm:mt-6" color="error" icon="i-lucide-trash-2" type="button" variant="ghost" aria-label="Quitar horario" :disabled="state.horarios.length === 1" @click="removeSchedule(index)" />
        </div>
      </div>
    </section>

    <UFormField label="Reglamento" name="reglamento" hint="Opcional" :error="errors.reglamento">
      <UTextarea v-model="state.reglamento" class="w-full" :rows="5" maxlength="20000" placeholder="Condiciones de uso, restricciones y responsabilidades" />
    </UFormField>
    <UAlert color="info" icon="i-lucide-clock-3" variant="subtle" :description="`Todas las fechas y horas corresponden a ${timezone}. La nueva versión cerrará la vigencia abierta anterior.`" />

    <div class="flex flex-col-reverse justify-end gap-3 border-t border-default pt-4 sm:flex-row">
      <UButton color="neutral" label="Cancelar" type="button" variant="outline" :disabled="loading" @click="emit('cancel')" />
      <UButton icon="i-lucide-list-plus" label="Registrar nueva regla" type="submit" :loading="loading" />
    </div>
  </form>
</template>
