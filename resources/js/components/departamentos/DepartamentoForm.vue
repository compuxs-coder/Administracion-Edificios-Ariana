<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import type {
  DepartamentoFormData,
  EdificioEstructuraOption
} from '../../types'

const props = withDefaults(defineProps<{
  edificios: EdificioEstructuraOption[]
  initial?: Partial<DepartamentoFormData>
  initialTorreId?: string
  currentDepartamentoId?: string
  currentEstado?: 'activo' | 'inactivo'
  selectedEdificioId?: string | null
  errors?: Record<string, string>
  loading?: boolean
  submitLabel?: string
  lockEdificio?: boolean
}>(), {
  initial: () => ({}),
  initialTorreId: '',
  currentDepartamentoId: '',
  currentEstado: 'activo',
  selectedEdificioId: null,
  errors: () => ({}),
  loading: false,
  submitLabel: 'Guardar departamento',
  lockEdificio: false
})

const emit = defineEmits<{
  submit: [data: DepartamentoFormData]
  cancel: []
}>()

const initialBuildingId = props.initial.edificio_id
  ?? (props.selectedEdificioId
    ? (props.edificios.some(item => item.id === props.selectedEdificioId && item.estado === 'activo') ? props.selectedEdificioId : '')
    : null)
  ?? (props.edificios.filter(item => item.estado === 'activo').length === 1
    ? props.edificios.find(item => item.estado === 'activo')?.id
    : '')
  ?? ''

const state = reactive<DepartamentoFormData>({
  edificio_id: initialBuildingId,
  piso_id: props.initial.piso_id ?? '',
  codigo: props.initial.codigo ?? '',
  nombre: props.initial.nombre ?? '',
  alicuota: props.initial.alicuota ?? '0.000000',
  observaciones: props.initial.observaciones ?? '',
  parqueaderos: [...(props.initial.parqueaderos ?? [])],
  bodegas: [...(props.initial.bodegas ?? [])]
})
const torreId = ref(props.initialTorreId)

const edificio = computed(() => props.edificios.find(item => item.id === state.edificio_id) ?? null)
const unavailableSelection = computed(() => Boolean(
  props.selectedEdificioId
  && !state.edificio_id
  && !props.edificios.some(item => item.id === props.selectedEdificioId && item.estado === 'activo')
))
const torres = computed(() => edificio.value?.torres.filter(item => (
  item.estado === 'activo' || item.id === props.initialTorreId
)) ?? [])
const torre = computed(() => torres.value.find(item => item.id === torreId.value) ?? null)
const pisos = computed(() => torre.value?.pisos.filter(item => (
  item.estado === 'activo' || item.id === props.initial.piso_id
)) ?? [])
const edificioItems = computed(() => props.edificios
  .filter(item => item.estado === 'activo' || item.id === props.initial.edificio_id)
  .map(item => ({
    label: item.estado === 'activo' ? item.nombre : `${item.nombre} (inactivo)`,
    value: item.id
  })))
const torreItems = computed(() => torres.value.map(item => ({
  label: item.estado === 'activo' ? item.nombre : `${item.nombre} (inactiva)`,
  value: item.id
})))
const pisoItems = computed(() => pisos.value.map(item => ({
  label: `${item.nombre ? `${item.numero} · ${item.nombre}` : `Piso ${item.numero}`}${item.estado === 'inactivo' ? ' (inactivo)' : ''}`,
  value: item.id
})))

watch(() => state.edificio_id, () => {
  torreId.value = ''
  state.piso_id = ''
  state.parqueaderos = []
  state.bodegas = []
})

watch(torreId, (value, oldValue) => {
  if (oldValue && value !== oldValue) state.piso_id = ''
})

const isUnavailable = (departamentoId: string | null) => Boolean(
  departamentoId && departamentoId !== props.currentDepartamentoId
)

const toggleAnexo = (field: 'parqueaderos' | 'bodegas', id: string, checked: boolean) => {
  if (checked) {
    if (!state[field].includes(id)) state[field].push(id)
  } else {
    state[field] = state[field].filter(item => item !== id)
  }
}

const handleSubmit = () => emit('submit', {
  ...state,
  parqueaderos: [...state.parqueaderos],
  bodegas: [...state.bodegas]
})
</script>

<template>
  <form class="space-y-7" @submit.prevent="handleSubmit">
    <section class="space-y-4">
      <div>
        <p class="text-sm font-semibold text-highlighted">Ubicación física</p>
        <p class="mt-1 text-xs text-muted">Seleccione la ruta completa para evitar asignaciones ambiguas.</p>
      </div>
      <div class="grid gap-4 md:grid-cols-3">
        <UFormField label="Edificio" name="edificio_id" required :error="errors.edificioId">
          <USelect
            v-model="state.edificio_id"
            class="w-full"
            :disabled="lockEdificio"
            :items="edificioItems"
            placeholder="Seleccione edificio"
            required
            size="xl"
          />
        </UFormField>
        <UFormField label="Torre o bloque" name="torre_id" required :error="errors.torreId">
          <USelect
            v-model="torreId"
            class="w-full"
            :disabled="!edificio"
            :items="torreItems"
            placeholder="Seleccione torre"
            required
            size="xl"
          />
        </UFormField>
        <UFormField label="Piso" name="piso_id" required :error="errors.pisoId">
          <USelect
            v-model="state.piso_id"
            class="w-full"
            :disabled="!torre"
            :items="pisoItems"
            placeholder="Seleccione piso"
            required
            size="xl"
          />
        </UFormField>
      </div>
      <UAlert
        v-if="unavailableSelection"
        color="warning"
        icon="i-lucide-triangle-alert"
        title="El edificio solicitado no está disponible"
        description="Seleccione un edificio activo al que tenga acceso."
        variant="subtle"
      />
      <UAlert
        v-if="currentEstado === 'inactivo'"
        color="neutral"
        icon="i-lucide-circle-pause"
        title="Departamento inactivo"
        description="Puede editar sus datos, pero debe activarlo desde el listado antes de asignarle anexos."
        variant="subtle"
      />
      <UAlert
        v-if="edificio && torres.length === 0"
        color="warning"
        icon="i-lucide-triangle-alert"
        title="El edificio no tiene torres activas"
        description="Configura primero su estructura física antes de registrar departamentos."
        variant="subtle"
      />
    </section>

    <section class="space-y-4 border-t border-default pt-6">
      <div>
        <p class="text-sm font-semibold text-highlighted">Identificación y alícuota</p>
        <p class="mt-1 text-xs text-muted">El código es único dentro del edificio; la alícuota admite hasta seis decimales.</p>
      </div>
      <div class="grid gap-4 md:grid-cols-2">
        <UFormField label="Código" name="codigo" required :error="errors.codigo">
          <UInput v-model="state.codigo" class="w-full" icon="i-lucide-hash" placeholder="DEP-301" required size="xl" />
        </UFormField>
        <UFormField label="Nombre" name="nombre" required :error="errors.nombre">
          <UInput v-model="state.nombre" class="w-full" icon="i-lucide-door-open" placeholder="Departamento 301" required size="xl" />
        </UFormField>
        <UFormField label="Alícuota (%)" name="alicuota" required :error="errors.alicuota">
          <UInput
            v-model="state.alicuota"
            class="w-full"
            icon="i-lucide-percent"
            min="0"
            max="100"
            step="0.000001"
            type="number"
            required
            size="xl"
          />
        </UFormField>
      </div>
      <UFormField label="Observaciones" name="observaciones" hint="Opcional" :error="errors.observaciones">
        <UTextarea v-model="state.observaciones" class="w-full" :rows="3" placeholder="Notas administrativas de la unidad" />
      </UFormField>
    </section>

    <section class="space-y-4 border-t border-default pt-6">
      <div>
        <p class="text-sm font-semibold text-highlighted">Anexos asignados</p>
        <p class="mt-1 text-xs text-muted">Puede vincular varios anexos; los ya asignados a otra unidad no están disponibles.</p>
      </div>

      <div class="grid gap-5 lg:grid-cols-2">
        <div class="rounded-xl border border-default p-4">
          <div class="mb-3 flex items-center justify-between gap-3">
            <p class="text-sm font-medium text-highlighted">Parqueaderos</p>
            <UBadge color="neutral" :label="`${state.parqueaderos.length} seleccionado(s)`" variant="subtle" />
          </div>
          <div v-if="edificio?.parqueaderos.length" class="grid max-h-48 gap-2 overflow-y-auto sm:grid-cols-2">
            <label
              v-for="item in edificio.parqueaderos"
              :key="item.id"
              class="flex items-center gap-2 rounded-lg border border-default px-3 py-2 text-sm"
              :class="currentEstado === 'inactivo' || isUnavailable(item.departamentoId) ? 'cursor-not-allowed opacity-50' : 'cursor-pointer hover:bg-elevated/60'"
            >
              <input
                type="checkbox"
                class="size-4 accent-primary"
                :checked="state.parqueaderos.includes(item.id)"
                :disabled="currentEstado === 'inactivo' || isUnavailable(item.departamentoId)"
                @change="toggleAnexo('parqueaderos', item.id, ($event.target as HTMLInputElement).checked)"
              >
              <span class="font-mono">{{ item.codigo }}</span>
            </label>
          </div>
          <p v-else class="py-4 text-center text-sm text-muted">No hay parqueaderos activos.</p>
          <p v-if="errors.parqueaderos" class="mt-2 text-sm text-error">{{ errors.parqueaderos }}</p>
        </div>

        <div class="rounded-xl border border-default p-4">
          <div class="mb-3 flex items-center justify-between gap-3">
            <p class="text-sm font-medium text-highlighted">Bodegas</p>
            <UBadge color="neutral" :label="`${state.bodegas.length} seleccionada(s)`" variant="subtle" />
          </div>
          <div v-if="edificio?.bodegas.length" class="grid max-h-48 gap-2 overflow-y-auto sm:grid-cols-2">
            <label
              v-for="item in edificio.bodegas"
              :key="item.id"
              class="flex items-center gap-2 rounded-lg border border-default px-3 py-2 text-sm"
              :class="currentEstado === 'inactivo' || isUnavailable(item.departamentoId) ? 'cursor-not-allowed opacity-50' : 'cursor-pointer hover:bg-elevated/60'"
            >
              <input
                type="checkbox"
                class="size-4 accent-primary"
                :checked="state.bodegas.includes(item.id)"
                :disabled="currentEstado === 'inactivo' || isUnavailable(item.departamentoId)"
                @change="toggleAnexo('bodegas', item.id, ($event.target as HTMLInputElement).checked)"
              >
              <span class="font-mono">{{ item.codigo }}</span>
            </label>
          </div>
          <p v-else class="py-4 text-center text-sm text-muted">No hay bodegas activas.</p>
          <p v-if="errors.bodegas" class="mt-2 text-sm text-error">{{ errors.bodegas }}</p>
        </div>
      </div>
    </section>

    <div class="flex flex-col-reverse justify-end gap-3 border-t border-default pt-5 sm:flex-row">
      <UButton color="neutral" label="Cancelar" type="button" variant="outline" :disabled="loading" @click="emit('cancel')" />
      <UButton icon="i-lucide-save" :label="submitLabel" type="submit" :loading="loading" :disabled="!state.edificio_id || !state.piso_id" />
    </div>
  </form>
</template>
