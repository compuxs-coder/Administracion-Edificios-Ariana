<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import type { EdificioAreaComunOption, EspacioComun, OcupacionAreaComunOption, ReservaAreaComunFormData } from '../../types'

const props = withDefaults(defineProps<{
  edificios: EdificioAreaComunOption[]
  espacios: EspacioComun[]
  ocupaciones: OcupacionAreaComunOption[]
  selectedEdificioId?: string | null
  selectedEspacioId?: string | null
  errors?: Record<string, string>
  loading?: boolean
}>(), {
  selectedEdificioId: null,
  selectedEspacioId: null,
  errors: () => ({}),
  loading: false
})

const emit = defineEmits<{
  submit: [data: ReservaAreaComunFormData]
  cancel: []
}>()

const requestedBuilding = props.edificios.some(item => item.id === props.selectedEdificioId) ? props.selectedEdificioId ?? '' : ''
const initialBuilding = requestedBuilding || (props.edificios.length === 1 ? props.edificios[0]?.id ?? '' : '')
const requestedSpace = props.espacios.some(item => item.id === props.selectedEspacioId && item.edificioId === initialBuilding) ? props.selectedEspacioId ?? '' : ''
const state = reactive<ReservaAreaComunFormData>({
  edificio_id: initialBuilding,
  espacio_comun_id: requestedSpace,
  ocupacion_id: '',
  inicio: '',
  fin: '',
  cantidad_asistentes: 1,
  observaciones: ''
})

const filteredSpaces = computed(() => props.espacios.filter(item => item.edificioId === state.edificio_id))
const filteredOccupations = computed(() => props.ocupaciones.filter(item => item.edificioId === state.edificio_id))
const selectedBuilding = computed(() => props.edificios.find(item => item.id === state.edificio_id) ?? null)
const selectedSpace = computed(() => props.espacios.find(item => item.id === state.espacio_comun_id) ?? null)
const occupationLabel = (item: OcupacionAreaComunOption) => `${item.departamentoCodigo} · ${item.nombre} · ${item.identificacion}`

watch(() => state.edificio_id, (building, previous) => {
  if (!previous || building === previous) return
  state.espacio_comun_id = ''
  state.ocupacion_id = ''
  state.inicio = ''
  state.fin = ''
})

const submit = () => emit('submit', { ...state })
</script>

<template>
  <form class="space-y-7" @submit.prevent="submit">
    <section class="space-y-4">
      <div>
        <p class="text-sm font-semibold text-highlighted">Espacio y reservante</p>
        <p class="mt-1 text-xs text-muted">Las opciones se limitan a edificios autorizados, espacios activos y ocupaciones vigentes.</p>
      </div>
      <div class="grid gap-4 md:grid-cols-2">
        <UFormField label="Edificio" name="edificio_id" required :error="errors.edificioId">
          <USelect v-model="state.edificio_id" class="w-full" :items="edificios.map(item => ({ label: item.nombre, value: item.id }))" placeholder="Seleccione edificio" required size="xl" />
        </UFormField>
        <UFormField label="Área común" name="espacio_comun_id" required :error="errors.espacioComunId">
          <USelect v-model="state.espacio_comun_id" class="w-full" :disabled="!state.edificio_id" :items="filteredSpaces.map(item => ({ label: `${item.codigo} · ${item.nombre}`, value: item.id }))" placeholder="Seleccione espacio" required size="xl" />
        </UFormField>
      </div>
      <UFormField label="Residente y departamento" name="ocupacion_id" required :error="errors.ocupacionId">
        <USelectMenu v-model="state.ocupacion_id" class="w-full" :disabled="!state.edificio_id" :items="filteredOccupations.map(item => ({ label: occupationLabel(item), value: item.id }))" value-key="value" label-key="label" placeholder="Buscar una ocupación vigente" required size="xl" />
      </UFormField>
      <UAlert v-if="selectedBuilding" color="info" icon="i-lucide-clock-3" variant="subtle" :description="`Ingrese las horas locales de ${selectedBuilding.timezone}. No se convierten desde la zona de su navegador.`" />
    </section>

    <section class="space-y-4 border-t border-default pt-6">
      <div>
        <p class="text-sm font-semibold text-highlighted">Fecha y asistencia</p>
        <p class="mt-1 text-xs text-muted">La reserva debe iniciar y terminar el mismo día, dentro de un horario habilitado por la regla vigente.</p>
      </div>
      <div class="grid gap-4 md:grid-cols-2">
        <UFormField label="Inicio" name="inicio" required :error="errors.inicio">
          <UInput v-model="state.inicio" class="w-full" type="datetime-local" required size="xl" />
        </UFormField>
        <UFormField label="Fin" name="fin" required :error="errors.fin">
          <UInput v-model="state.fin" class="w-full" type="datetime-local" :min="state.inicio || undefined" required size="xl" />
        </UFormField>
      </div>
      <UFormField label="Cantidad de asistentes" name="cantidad_asistentes" required :error="errors.cantidadAsistentes" :hint="selectedSpace ? `Capacidad física: ${selectedSpace.capacidad}` : undefined">
        <UInput v-model.number="state.cantidad_asistentes" class="w-full" type="number" min="1" :max="selectedSpace?.capacidad ?? 9999" required size="xl" />
      </UFormField>
      <UFormField label="Observaciones" name="observaciones" hint="Opcional" :error="errors.observaciones">
        <UTextarea v-model="state.observaciones" class="w-full" :rows="4" maxlength="5000" placeholder="Finalidad de la reserva o información para la administración" />
      </UFormField>
    </section>

    <UAlert color="neutral" icon="i-lucide-shield-check" variant="subtle" description="La regla vigente determinará duración, antelación, aforo, disponibilidad y si la solicitud requiere aprobación." />
    <div class="flex flex-col-reverse justify-end gap-3 border-t border-default pt-5 sm:flex-row">
      <UButton color="neutral" label="Cancelar" type="button" variant="outline" :disabled="loading" @click="emit('cancel')" />
      <UButton icon="i-lucide-calendar-plus" label="Registrar reserva" type="submit" :loading="loading" :disabled="!state.edificio_id || !state.espacio_comun_id || !state.ocupacion_id || !state.inicio || !state.fin" />
    </div>
  </form>
</template>
