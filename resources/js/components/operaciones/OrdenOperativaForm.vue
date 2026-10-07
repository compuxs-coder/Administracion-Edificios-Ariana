<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import type {
  ContratoOperacionOption,
  EdificioOperacionOption,
  ElementoOperacionOption,
  OrdenOperativaFormData,
  ProveedorOperacionOption,
  ReportanteOperacionOption,
  TipoOrdenOperativa,
  TipoUbicacionOperativa
} from '../../types'

const props = withDefaults(defineProps<{
  edificios?: EdificioOperacionOption[]
  torres?: ElementoOperacionOption[]
  pisos?: ElementoOperacionOption[]
  departamentos?: ElementoOperacionOption[]
  parqueaderos?: ElementoOperacionOption[]
  bodegas?: ElementoOperacionOption[]
  residentes?: ReportanteOperacionOption[]
  proveedores?: ProveedorOperacionOption[]
  contratos?: ContratoOperacionOption[]
  selectedEdificioId?: string | null
  initial?: Partial<OrdenOperativaFormData>
  errors?: Record<string, string>
  loading?: boolean
  lockScope?: boolean
  lockType?: boolean
  submitLabel?: string
  currentLocation?: { tipo: TipoUbicacionOperativa | null, id: string | null, etiqueta: string | null } | null
}>(), {
  edificios: () => [],
  torres: () => [],
  pisos: () => [],
  departamentos: () => [],
  parqueaderos: () => [],
  bodegas: () => [],
  residentes: () => [],
  proveedores: () => [],
  contratos: () => [],
  selectedEdificioId: null,
  initial: () => ({}),
  errors: () => ({}),
  loading: false,
  lockScope: false,
  lockType: false,
  submitLabel: 'Crear orden',
  currentLocation: null
})

const emit = defineEmits<{
  submit: [data: OrdenOperativaFormData]
  cancel: []
}>()

const selectedBuilding = props.edificios.some(item => item.id === props.selectedEdificioId)
  ? props.selectedEdificioId ?? ''
  : ''
const state = reactive<OrdenOperativaFormData>({
  edificio_id: props.initial.edificio_id || selectedBuilding || (props.edificios.length === 1 ? props.edificios[0]?.id ?? '' : ''),
  tipo: props.initial.tipo ?? 'incidencia',
  titulo: props.initial.titulo ?? '',
  descripcion: props.initial.descripcion ?? '',
  prioridad: props.initial.prioridad ?? 'media',
  fecha_objetivo: props.initial.fecha_objetivo ?? '',
  torre_id: props.initial.torre_id ?? '',
  piso_id: props.initial.piso_id ?? '',
  departamento_id: props.initial.departamento_id ?? '',
  parqueadero_id: props.initial.parqueadero_id ?? '',
  bodega_id: props.initial.bodega_id ?? '',
  ubicacion_detalle: props.initial.ubicacion_detalle ?? '',
  reportante_residente_id: props.initial.reportante_residente_id ?? '',
  proveedor_id: props.initial.proveedor_id ?? '',
  contrato_id: props.initial.contrato_id ?? ''
})

const initialLocation = (): string => {
  const entries: Array<[TipoUbicacionOperativa, string]> = [
    ['torre', state.torre_id],
    ['piso', state.piso_id],
    ['departamento', state.departamento_id],
    ['parqueadero', state.parqueadero_id],
    ['bodega', state.bodega_id]
  ]
  const selected = entries.find(([, id]) => Boolean(id))
  return selected ? `${selected[0]}:${selected[1]}` : ''
}
const locationValue = ref(initialLocation())
const labelFor = (tipo: TipoUbicacionOperativa, item: ElementoOperacionOption): string => {
  if (tipo === 'piso') return `Piso ${item.numero}${item.nombre ? ` · ${item.nombre}` : ''}`
  const code = item.codigo ?? item.id.slice(0, 8)
  return `${code}${item.nombre ? ` · ${item.nombre}` : item.ubicacion ? ` · ${item.ubicacion}` : ''}`
}
const locationItems = computed(() => {
  const locationSources: Array<{ tipo: TipoUbicacionOperativa, label: string, items: ElementoOperacionOption[] }> = [
    { tipo: 'torre', label: 'Torre', items: props.torres },
    { tipo: 'piso', label: 'Piso', items: props.pisos },
    { tipo: 'departamento', label: 'Departamento', items: props.departamentos },
    { tipo: 'parqueadero', label: 'Parqueadero', items: props.parqueaderos },
    { tipo: 'bodega', label: 'Bodega', items: props.bodegas }
  ]
  const items = locationSources.flatMap(source => source.items
    .filter(item => item.edificio_id === state.edificio_id)
    .map(item => ({ label: `${source.label}: ${labelFor(source.tipo, item)}`, value: `${source.tipo}:${item.id}` })))
  const current = props.currentLocation
  if (current?.tipo && current.id && current.etiqueta) {
    const value = `${current.tipo}:${current.id}`
    if (!items.some(item => item.value === value)) {
      items.push({ label: `${current.etiqueta} · referencia histórica`, value })
    }
  }

  return items
})
const reporterItems = computed(() => props.residentes
  .filter(item => item.edificioId === state.edificio_id)
  .map(item => ({ label: `${item.nombre} · ${item.identificacion}`, value: item.residenteId })))
const providerItems = computed(() => props.proveedores
  .filter(item => item.edificioId === state.edificio_id)
  .map(item => ({ label: `${item.nombre} · ${item.identificacion}`, value: item.proveedorId })))
const contractItems = computed(() => props.contratos
  .filter(item => item.edificioId === state.edificio_id && item.proveedorId === state.proveedor_id)
  .map(item => ({ label: `${item.referencia} · ${item.objeto}`, value: item.id })))
const typeItems: Array<{ label: string, value: TipoOrdenOperativa }> = [
  { label: 'Incidencia', value: 'incidencia' },
  { label: 'Solicitud', value: 'solicitud' },
  ...(props.initial.tipo === 'mantenimiento_preventivo' ? [{ label: 'Mantenimiento preventivo', value: 'mantenimiento_preventivo' as const }] : [])
]
const priorityItems = [
  { label: 'Baja', value: 'baja' },
  { label: 'Media', value: 'media' },
  { label: 'Alta', value: 'alta' },
  { label: 'Crítica', value: 'critica' }
]

const clearLocation = () => {
  state.torre_id = ''
  state.piso_id = ''
  state.departamento_id = ''
  state.parqueadero_id = ''
  state.bodega_id = ''
}
watch(locationValue, value => {
  clearLocation()
  if (!value) return
  const [type, id] = value.split(':') as [TipoUbicacionOperativa, string]
  state[`${type}_id` as keyof Pick<OrdenOperativaFormData, 'torre_id' | 'piso_id' | 'departamento_id' | 'parqueadero_id' | 'bodega_id'>] = id
})
watch(() => state.edificio_id, (building, previous) => {
  if (!previous || building === previous) return
  locationValue.value = ''
  state.reportante_residente_id = ''
  state.proveedor_id = ''
  state.contrato_id = ''
})
watch(() => state.proveedor_id, (provider, previous) => {
  if (provider !== previous) state.contrato_id = ''
})

const submit = () => emit('submit', { ...state })
</script>

<template>
  <form class="space-y-7" @submit.prevent="submit">
    <section class="space-y-4">
      <div><p class="text-sm font-semibold text-highlighted">Identificación</p><p class="mt-1 text-xs text-muted">La clasificación queda fija después de crear la orden.</p></div>
      <div class="grid gap-4 md:grid-cols-2">
        <UFormField label="Edificio" name="edificio_id" required :error="errors.edificioId"><USelect v-model="state.edificio_id" class="w-full" :disabled="lockScope" :items="edificios.map(item => ({ label: item.nombre, value: item.id }))" placeholder="Seleccione edificio" required size="xl" /></UFormField>
        <UFormField label="Tipo" name="tipo" required :error="errors.tipo"><USelect v-model="state.tipo" class="w-full" :disabled="lockType" :items="typeItems" required size="xl" /></UFormField>
      </div>
      <UFormField label="Título" name="titulo" required :error="errors.titulo"><UInput v-model="state.titulo" class="w-full" maxlength="180" placeholder="Resumen breve de la necesidad" required size="xl" /></UFormField>
      <UFormField label="Descripción" name="descripcion" required :error="errors.descripcion"><UTextarea v-model="state.descripcion" class="w-full" :rows="5" maxlength="10000" required /></UFormField>
    </section>

    <section class="space-y-4 border-t border-default pt-6">
      <div><p class="text-sm font-semibold text-highlighted">Planificación</p><p class="mt-1 text-xs text-muted">La fecha objetivo es manual y no activa SLA ni escalamiento.</p></div>
      <div class="grid gap-4 md:grid-cols-2">
        <UFormField label="Prioridad" name="prioridad" required :error="errors.prioridad"><USelect v-model="state.prioridad" class="w-full" :items="priorityItems" required size="xl" /></UFormField>
        <UFormField label="Fecha objetivo" name="fecha_objetivo" hint="Opcional" :error="errors.fechaObjetivo"><UInput v-model="state.fecha_objetivo" class="w-full" type="date" size="xl" /></UFormField>
      </div>
    </section>

    <section class="space-y-4 border-t border-default pt-6">
      <div><p class="text-sm font-semibold text-highlighted">Origen y ubicación</p><p class="mt-1 text-xs text-muted">Seleccione como máximo un elemento estructural y use el detalle para precisar la zona.</p></div>
      <div class="grid gap-4 md:grid-cols-2">
        <UFormField label="Elemento estructural" name="ubicacion" hint="Opcional" :error="errors.ubicacion || errors.torreId || errors.pisoId || errors.departamentoId || errors.parqueaderoId || errors.bodegaId"><USelect v-model="locationValue" class="w-full" :disabled="!state.edificio_id" :items="[{ label: 'Sin elemento asociado', value: '' }, ...locationItems]" /></UFormField>
        <UFormField label="Residente reportante" name="reportante_residente_id" hint="Opcional" :error="errors.reportanteResidenteId"><USelect v-model="state.reportante_residente_id" class="w-full" :disabled="!state.edificio_id" :items="[{ label: 'Reporte administrativo', value: '' }, ...reporterItems]" /></UFormField>
      </div>
      <UFormField label="Detalle de ubicación" name="ubicacion_detalle" hint="Obligatorio si no selecciona un elemento" :error="errors.ubicacionDetalle"><UInput v-model="state.ubicacion_detalle" class="w-full" maxlength="500" placeholder="Ej. pasillo frente al ascensor" size="xl" /></UFormField>
    </section>

    <section class="space-y-4 border-t border-default pt-6">
      <div><p class="text-sm font-semibold text-highlighted">Proveedor y contrato</p><p class="mt-1 text-xs text-muted">La asociación es opcional y no genera gastos ni documentos financieros.</p></div>
      <div class="grid gap-4 md:grid-cols-2">
        <UFormField label="Proveedor" name="proveedor_id" hint="Opcional" :error="errors.proveedorId"><USelect v-model="state.proveedor_id" class="w-full" :disabled="!state.edificio_id" :items="[{ label: 'Sin proveedor asociado', value: '' }, ...providerItems]" /></UFormField>
        <UFormField label="Contrato registrado" name="contrato_id" hint="Opcional" :error="errors.contratoId"><USelect v-model="state.contrato_id" class="w-full" :disabled="!state.proveedor_id" :items="[{ label: 'Sin contrato asociado', value: '' }, ...contractItems]" /></UFormField>
      </div>
    </section>

    <UAlert color="info" description="La orden inicia en estado reportada. Responsable, estados, evidencias y actuaciones se administran desde su detalle." icon="i-lucide-info" variant="subtle" />
    <div class="flex flex-col-reverse justify-end gap-3 border-t border-default pt-5 sm:flex-row">
      <UButton color="neutral" label="Cancelar" type="button" variant="outline" :disabled="loading" @click="emit('cancel')" />
      <UButton icon="i-lucide-save" :label="submitLabel" type="submit" :loading="loading" :disabled="!state.edificio_id || !state.titulo.trim() || !state.descripcion.trim() || (!locationValue && !state.ubicacion_detalle.trim())" />
    </div>
  </form>
</template>
