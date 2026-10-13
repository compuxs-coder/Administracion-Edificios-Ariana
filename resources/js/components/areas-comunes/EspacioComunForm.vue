<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import type { EdificioAreaComunOption, EspacioComunFormData, PisoAreaComunOption, TorreAreaComunOption } from '../../types'

const props = withDefaults(defineProps<{
  edificios: EdificioAreaComunOption[]
  torres: TorreAreaComunOption[]
  pisos: PisoAreaComunOption[]
  selectedEdificioId?: string | null
  initial?: Partial<EspacioComunFormData>
  currentLocation?: { tipo: 'torre' | 'piso' | null, id: string | null, etiqueta: string | null } | null
  errors?: Record<string, string>
  loading?: boolean
  lockBuilding?: boolean
  submitLabel?: string
}>(), {
  selectedEdificioId: null,
  initial: () => ({}),
  currentLocation: null,
  errors: () => ({}),
  loading: false,
  lockBuilding: false,
  submitLabel: 'Guardar espacio'
})

const emit = defineEmits<{
  submit: [data: EspacioComunFormData]
  cancel: []
}>()

const selectedBuilding = props.edificios.some(item => item.id === props.selectedEdificioId)
  ? props.selectedEdificioId ?? ''
  : ''
const state = reactive<EspacioComunFormData>({
  edificio_id: props.initial.edificio_id || selectedBuilding || (props.edificios.length === 1 ? props.edificios[0]?.id ?? '' : ''),
  codigo: props.initial.codigo ?? '',
  nombre: props.initial.nombre ?? '',
  descripcion: props.initial.descripcion ?? '',
  capacidad: props.initial.capacidad ?? 1,
  torre_id: props.initial.torre_id ?? '',
  piso_id: props.initial.piso_id ?? '',
  ubicacion_detalle: props.initial.ubicacion_detalle ?? '',
  ...(props.initial.updated_at ? { updated_at: props.initial.updated_at } : {})
})
const initialLocation = state.torre_id ? `torre:${state.torre_id}` : state.piso_id ? `piso:${state.piso_id}` : ''
const locationValue = ref(initialLocation)

const locationItems = computed(() => {
  const items = [
    ...props.torres
      .filter(item => item.edificio_id === state.edificio_id)
      .map(item => ({ label: `Torre: ${item.codigo} · ${item.nombre}`, value: `torre:${item.id}` })),
    ...props.pisos
      .filter(item => item.edificio_id === state.edificio_id)
      .map(item => ({ label: `Piso: ${item.numero}${item.nombre ? ` · ${item.nombre}` : ''}`, value: `piso:${item.id}` }))
  ]
  const current = props.currentLocation
  if (current?.tipo && current.id && current.etiqueta) {
    const value = `${current.tipo}:${current.id}`
    if (!items.some(item => item.value === value)) items.push({ label: `${current.etiqueta} · referencia actual`, value })
  }
  return items
})
const selectedTimezone = computed(() => props.edificios.find(item => item.id === state.edificio_id)?.timezone ?? null)

watch(locationValue, value => {
  state.torre_id = ''
  state.piso_id = ''
  if (!value) return
  const [type, id] = value.split(':')
  if (type === 'torre') state.torre_id = id ?? ''
  if (type === 'piso') state.piso_id = id ?? ''
})
watch(() => state.edificio_id, (building, previous) => {
  if (previous && building !== previous) locationValue.value = ''
})

const submit = () => emit('submit', { ...state })
</script>

<template>
  <form class="space-y-7" @submit.prevent="submit">
    <section class="space-y-4">
      <div>
        <p class="text-sm font-semibold text-highlighted">Identificación</p>
        <p class="mt-1 text-xs text-muted">El código debe ser único dentro del edificio y admite letras, números, puntos, guiones y guion bajo.</p>
      </div>
      <div class="grid gap-4 md:grid-cols-2">
        <UFormField label="Edificio" name="edificio_id" required :error="errors.edificioId">
          <USelect v-model="state.edificio_id" class="w-full" :disabled="lockBuilding" :items="edificios.map(item => ({ label: item.nombre, value: item.id }))" placeholder="Seleccione edificio" required size="xl" />
        </UFormField>
        <UFormField label="Código" name="codigo" required :error="errors.codigo">
          <UInput v-model="state.codigo" class="w-full font-mono uppercase" maxlength="40" pattern="[A-Za-z0-9][A-Za-z0-9._-]*" placeholder="SALON-SOCIAL" required size="xl" />
        </UFormField>
      </div>
      <UFormField label="Nombre" name="nombre" required :error="errors.nombre">
        <UInput v-model="state.nombre" class="w-full" maxlength="180" placeholder="Salón social" required size="xl" />
      </UFormField>
      <UFormField label="Descripción" name="descripcion" hint="Opcional" :error="errors.descripcion">
        <UTextarea v-model="state.descripcion" class="w-full" :rows="4" maxlength="10000" placeholder="Características, mobiliario y condiciones generales del espacio" />
      </UFormField>
    </section>

    <section class="space-y-4 border-t border-default pt-6">
      <div>
        <p class="text-sm font-semibold text-highlighted">Capacidad y ubicación</p>
        <p class="mt-1 text-xs text-muted">Seleccione como máximo una torre o un piso. Si no aplica, describa la ubicación.</p>
      </div>
      <div class="grid gap-4 md:grid-cols-2">
        <UFormField label="Capacidad máxima" name="capacidad" required :error="errors.capacidad">
          <UInput v-model.number="state.capacidad" class="w-full" type="number" min="1" max="9999" step="1" required size="xl" />
        </UFormField>
        <UFormField label="Elemento estructural" name="ubicacion" hint="Opcional" :error="errors.ubicacion || errors.torreId || errors.pisoId">
          <USelect v-model="locationValue" class="w-full" :disabled="!state.edificio_id" :items="[{ label: 'Sin elemento asociado', value: '' }, ...locationItems]" />
        </UFormField>
      </div>
      <UFormField label="Detalle de ubicación" name="ubicacion_detalle" hint="Obligatorio si no selecciona torre o piso" :error="errors.ubicacionDetalle">
        <UInput v-model="state.ubicacion_detalle" class="w-full" maxlength="500" placeholder="Ej. planta baja, junto a recepción" size="xl" />
      </UFormField>
      <UAlert v-if="selectedTimezone" color="info" icon="i-lucide-clock-3" variant="subtle" :description="`Las reglas y reservas se interpretarán en ${selectedTimezone}.`" />
    </section>

    <div class="flex flex-col-reverse justify-end gap-3 border-t border-default pt-5 sm:flex-row">
      <UButton color="neutral" label="Cancelar" type="button" variant="outline" :disabled="loading" @click="emit('cancel')" />
      <UButton icon="i-lucide-save" :label="submitLabel" type="submit" :loading="loading" :disabled="!state.edificio_id || !state.codigo.trim() || !state.nombre.trim() || (!locationValue && !state.ubicacion_detalle.trim())" />
    </div>
  </form>
</template>
