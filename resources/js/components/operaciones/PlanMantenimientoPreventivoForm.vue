<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import type { ContratoOperacionOption, EdificioOperacionOption, ElementoOperacionOption, PlanMantenimientoPreventivoFormData, ProveedorOperacionOption, TipoUbicacionOperativa } from '../../types'

const props = withDefaults(defineProps<{
  edificios?: EdificioOperacionOption[]
  torres?: ElementoOperacionOption[]
  pisos?: ElementoOperacionOption[]
  departamentos?: ElementoOperacionOption[]
  parqueaderos?: ElementoOperacionOption[]
  bodegas?: ElementoOperacionOption[]
  proveedores?: ProveedorOperacionOption[]
  contratos?: ContratoOperacionOption[]
  selectedEdificioId?: string | null
  initial?: Partial<PlanMantenimientoPreventivoFormData>
  currentLocation?: { tipo: TipoUbicacionOperativa | null, id: string | null, etiqueta: string | null } | null
  errors?: Record<string, string>
  loading?: boolean
  lockScope?: boolean
  submitLabel?: string
}>(), {
  edificios: () => [], torres: () => [], pisos: () => [], departamentos: () => [], parqueaderos: () => [], bodegas: () => [],
  proveedores: () => [], contratos: () => [], selectedEdificioId: null, initial: () => ({}), currentLocation: null,
  errors: () => ({}), loading: false, lockScope: false, submitLabel: 'Crear plan'
})
const emit = defineEmits<{ submit: [data: PlanMantenimientoPreventivoFormData], cancel: [] }>()
const selectedBuilding = props.edificios.some(item => item.id === props.selectedEdificioId) ? props.selectedEdificioId ?? '' : ''
const state = reactive<PlanMantenimientoPreventivoFormData>({
  edificio_id: props.initial.edificio_id || selectedBuilding || (props.edificios.length === 1 ? props.edificios[0]?.id ?? '' : ''),
  codigo: props.initial.codigo ?? '', titulo: props.initial.titulo ?? '', descripcion: props.initial.descripcion ?? '',
  prioridad: props.initial.prioridad ?? 'media', unidad_recurrencia: props.initial.unidad_recurrencia ?? 'mensual',
  intervalo_recurrencia: props.initial.intervalo_recurrencia ?? 1, dias_anticipacion: props.initial.dias_anticipacion ?? 0,
  torre_id: props.initial.torre_id ?? '', piso_id: props.initial.piso_id ?? '', departamento_id: props.initial.departamento_id ?? '',
  parqueadero_id: props.initial.parqueadero_id ?? '', bodega_id: props.initial.bodega_id ?? '', ubicacion_detalle: props.initial.ubicacion_detalle ?? '',
  proveedor_id: props.initial.proveedor_id ?? '', contrato_id: props.initial.contrato_id ?? ''
})
const initialLocation = () => {
  const entries: Array<[TipoUbicacionOperativa, string]> = [['torre', state.torre_id], ['piso', state.piso_id], ['departamento', state.departamento_id], ['parqueadero', state.parqueadero_id], ['bodega', state.bodega_id]]
  const selected = entries.find(([, id]) => Boolean(id))
  return selected ? `${selected[0]}:${selected[1]}` : ''
}
const locationValue = ref(initialLocation())
const labelFor = (type: TipoUbicacionOperativa, item: ElementoOperacionOption) => type === 'piso'
  ? `Piso ${item.numero}${item.nombre ? ` · ${item.nombre}` : ''}`
  : `${item.codigo ?? item.id.slice(0, 8)}${item.nombre ? ` · ${item.nombre}` : item.ubicacion ? ` · ${item.ubicacion}` : ''}`
const locationItems = computed(() => {
  const sources: Array<{ tipo: TipoUbicacionOperativa, label: string, items: ElementoOperacionOption[] }> = [
    { tipo: 'torre', label: 'Torre', items: props.torres }, { tipo: 'piso', label: 'Piso', items: props.pisos },
    { tipo: 'departamento', label: 'Departamento', items: props.departamentos }, { tipo: 'parqueadero', label: 'Parqueadero', items: props.parqueaderos },
    { tipo: 'bodega', label: 'Bodega', items: props.bodegas }
  ]
  const items = sources.flatMap(source => source.items.filter(item => item.edificio_id === state.edificio_id).map(item => ({ label: `${source.label}: ${labelFor(source.tipo, item)}`, value: `${source.tipo}:${item.id}` })))
  if (props.currentLocation?.tipo && props.currentLocation.id && props.currentLocation.etiqueta) {
    const value = `${props.currentLocation.tipo}:${props.currentLocation.id}`
    if (!items.some(item => item.value === value)) items.push({ label: `${props.currentLocation.etiqueta} · referencia histórica`, value })
  }
  return items
})
const providerItems = computed(() => props.proveedores.filter(item => item.edificioId === state.edificio_id).map(item => ({ label: `${item.nombre} · ${item.identificacion}`, value: item.proveedorId })))
const contractItems = computed(() => props.contratos.filter(item => item.edificioId === state.edificio_id && item.proveedorId === state.proveedor_id).map(item => ({ label: `${item.referencia} · ${item.objeto}`, value: item.id })))
const clearLocation = () => { state.torre_id = ''; state.piso_id = ''; state.departamento_id = ''; state.parqueadero_id = ''; state.bodega_id = '' }
watch(locationValue, value => {
  clearLocation()
  if (!value) return
  const [type, id] = value.split(':') as [TipoUbicacionOperativa, string]
  state[`${type}_id` as keyof Pick<PlanMantenimientoPreventivoFormData, 'torre_id' | 'piso_id' | 'departamento_id' | 'parqueadero_id' | 'bodega_id'>] = id
})
watch(() => state.edificio_id, (building, previous) => { if (previous && building !== previous) { locationValue.value = ''; state.proveedor_id = ''; state.contrato_id = '' } })
watch(() => state.proveedor_id, (provider, previous) => { if (provider !== previous) state.contrato_id = '' })
</script>

<template>
  <form class="space-y-7" @submit.prevent="emit('submit', { ...state })">
    <section class="space-y-4">
      <div><p class="text-sm font-semibold text-highlighted">Identificación</p><p class="mt-1 text-xs text-muted">El código se normaliza en mayúsculas y es único dentro del edificio.</p></div>
      <div class="grid gap-4 md:grid-cols-2">
        <UFormField label="Edificio" required :error="errors.edificioId"><USelect v-model="state.edificio_id" class="w-full" :disabled="lockScope" :items="edificios.map(item => ({ label: item.nombre, value: item.id }))" required size="xl" /></UFormField>
        <UFormField label="Código" required :error="errors.codigo"><UInput v-model="state.codigo" class="w-full font-mono uppercase" maxlength="40" placeholder="ASCENSOR-T1" required size="xl" /></UFormField>
      </div>
      <UFormField label="Título" required :error="errors.titulo"><UInput v-model="state.titulo" class="w-full" maxlength="180" required size="xl" /></UFormField>
      <UFormField label="Descripción" required :error="errors.descripcion"><UTextarea v-model="state.descripcion" class="w-full" :rows="5" maxlength="10000" required /></UFormField>
    </section>
    <section class="space-y-4 border-t border-default pt-6">
      <div><p class="text-sm font-semibold text-highlighted">Recurrencia</p><p class="mt-1 text-xs text-muted">La primera fecha se define al activar el plan. La programación mantiene su fecha ancla para evitar deriva.</p></div>
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <UFormField label="Prioridad" required :error="errors.prioridad"><USelect v-model="state.prioridad" class="w-full" :items="[{ label: 'Baja', value: 'baja' }, { label: 'Media', value: 'media' }, { label: 'Alta', value: 'alta' }, { label: 'Crítica', value: 'critica' }]" /></UFormField>
        <UFormField label="Frecuencia" required :error="errors.unidadRecurrencia"><USelect v-model="state.unidad_recurrencia" class="w-full" :items="[{ label: 'Diaria', value: 'diaria' }, { label: 'Semanal', value: 'semanal' }, { label: 'Mensual', value: 'mensual' }, { label: 'Anual', value: 'anual' }]" /></UFormField>
        <UFormField label="Cada" required :error="errors.intervaloRecurrencia"><UInput v-model.number="state.intervalo_recurrencia" class="w-full" type="number" min="1" max="99" /></UFormField>
        <UFormField label="Días de anticipación" required :error="errors.diasAnticipacion"><UInput v-model.number="state.dias_anticipacion" class="w-full" type="number" min="0" max="365" /></UFormField>
      </div>
    </section>
    <section class="space-y-4 border-t border-default pt-6">
      <div><p class="text-sm font-semibold text-highlighted">Ubicación</p><p class="mt-1 text-xs text-muted">No se crea un catálogo de activos; el plan se identifica por título, descripción y ubicación.</p></div>
      <div class="grid gap-4 md:grid-cols-2">
        <UFormField label="Elemento estructural" hint="Opcional" :error="errors.ubicacion || errors.torreId || errors.pisoId || errors.departamentoId || errors.parqueaderoId || errors.bodegaId"><USelect v-model="locationValue" class="w-full" :disabled="!state.edificio_id" :items="[{ label: 'Sin elemento asociado', value: '' }, ...locationItems]" /></UFormField>
        <UFormField label="Detalle de ubicación" hint="Obligatorio sin elemento" :error="errors.ubicacionDetalle"><UInput v-model="state.ubicacion_detalle" class="w-full" maxlength="500" placeholder="Ej. cuarto de bombas" /></UFormField>
      </div>
    </section>
    <section class="space-y-4 border-t border-default pt-6">
      <div><p class="text-sm font-semibold text-highlighted">Proveedor y contrato</p><p class="mt-1 text-xs text-muted">Son opcionales. Si dejan de ser válidos, la ocurrencia se bloquea sin generar costos.</p></div>
      <div class="grid gap-4 md:grid-cols-2">
        <UFormField label="Proveedor" hint="Opcional" :error="errors.proveedorId"><USelect v-model="state.proveedor_id" class="w-full" :disabled="!state.edificio_id" :items="[{ label: 'Sin proveedor', value: '' }, ...providerItems]" /></UFormField>
        <UFormField label="Contrato" hint="Opcional" :error="errors.contratoId"><USelect v-model="state.contrato_id" class="w-full" :disabled="!state.proveedor_id" :items="[{ label: 'Sin contrato', value: '' }, ...contractItems]" /></UFormField>
      </div>
    </section>
    <UAlert color="info" description="El plan se crea inactivo. Después podrá activarlo indicando la primera fecha programada." icon="i-lucide-info" variant="subtle" />
    <div class="flex flex-col-reverse justify-end gap-3 border-t border-default pt-5 sm:flex-row"><UButton color="neutral" label="Cancelar" type="button" variant="outline" :disabled="loading" @click="emit('cancel')" /><UButton icon="i-lucide-save" :label="submitLabel" type="submit" :loading="loading" :disabled="!state.edificio_id || !state.codigo.trim() || !state.titulo.trim() || !state.descripcion.trim() || (!locationValue && !state.ubicacion_detalle.trim())" /></div>
  </form>
</template>
