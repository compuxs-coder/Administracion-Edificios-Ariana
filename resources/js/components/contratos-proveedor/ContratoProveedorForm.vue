<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import type { ContratoProveedorFormData, ProveedorOption } from '../../types'

const props = withDefaults(defineProps<{
  edificios?: Array<{ id: string, nombre: string }>
  proveedores?: ProveedorOption[]
  selectedEdificioId?: string | null
  initial?: Partial<ContratoProveedorFormData>
  errors?: Record<string, string>
  loading?: boolean
  lockScope?: boolean
  submitLabel?: string
}>(), {
  edificios: () => [],
  proveedores: () => [],
  selectedEdificioId: null,
  initial: () => ({}),
  errors: () => ({}),
  loading: false,
  lockScope: false,
  submitLabel: 'Guardar borrador'
})

const emit = defineEmits<{
  submit: [data: ContratoProveedorFormData]
  cancel: []
}>()

const today = new Date().toISOString().slice(0, 10)
const selectedBuilding = props.edificios.some(item => item.id === props.selectedEdificioId)
  ? props.selectedEdificioId ?? ''
  : ''
const state = reactive<ContratoProveedorFormData>({
  edificio_id: props.initial.edificio_id || selectedBuilding || (props.edificios.length === 1 ? props.edificios[0]?.id ?? '' : ''),
  proveedor_id: props.initial.proveedor_id ?? '',
  referencia: props.initial.referencia ?? '',
  objeto: props.initial.objeto ?? '',
  fecha_inicio: props.initial.fecha_inicio ?? today,
  fecha_fin: props.initial.fecha_fin ?? '',
  monto_total: props.initial.monto_total ?? '',
  observaciones: props.initial.observaciones ?? ''
})
const providerItems = computed(() => props.proveedores
  .filter(item => item.edificioId === state.edificio_id && item.estado !== 'inactivo')
  .map(item => ({ label: `${item.nombre} · ${item.identificacion}`, value: item.id })))

watch(() => state.edificio_id, (building, previous) => {
  if (previous && building !== previous) state.proveedor_id = ''
})
</script>

<template>
  <form class="space-y-7" @submit.prevent="emit('submit', { ...state })">
    <section class="space-y-4">
      <div><p class="text-sm font-semibold text-highlighted">Partes del contrato</p><p class="mt-1 text-xs text-muted">Solo se muestran proveedores activos del edificio seleccionado.</p></div>
      <div class="grid gap-4 md:grid-cols-2">
        <UFormField label="Edificio" name="edificio_id" required :error="errors.edificioId"><USelect v-model="state.edificio_id" class="w-full" :disabled="lockScope" :items="edificios.map(item => ({ label: item.nombre, value: item.id }))" placeholder="Seleccione edificio" required size="xl" /></UFormField>
        <UFormField label="Proveedor" name="proveedor_id" required :error="errors.proveedorId"><USelect v-model="state.proveedor_id" class="w-full" :disabled="lockScope || !state.edificio_id" :items="providerItems" placeholder="Seleccione proveedor" required size="xl" /></UFormField>
      </div>
    </section>

    <section class="space-y-4 border-t border-default pt-6">
      <div><p class="text-sm font-semibold text-highlighted">Condiciones</p><p class="mt-1 text-xs text-muted">El contrato permanecerá en borrador hasta que se registre desde su detalle.</p></div>
      <div class="grid gap-4 md:grid-cols-2">
        <UFormField label="Referencia" name="referencia" required :error="errors.referencia"><UInput v-model="state.referencia" class="w-full" icon="i-lucide-hash" required size="xl" /></UFormField>
        <UFormField label="Monto total" name="monto_total" hint="Opcional" :error="errors.montoTotal"><UInput v-model="state.monto_total" class="w-full" icon="i-lucide-circle-dollar-sign" inputmode="decimal" placeholder="0.0000" size="xl" /></UFormField>
        <UFormField label="Fecha de inicio" name="fecha_inicio" required :error="errors.fechaInicio"><UInput v-model="state.fecha_inicio" class="w-full" type="date" required size="xl" /></UFormField>
        <UFormField label="Fecha de finalización" name="fecha_fin" hint="Opcional" :error="errors.fechaFin"><UInput v-model="state.fecha_fin" class="w-full" type="date" :min="state.fecha_inicio" size="xl" /></UFormField>
      </div>
      <UFormField label="Objeto" name="objeto" required :error="errors.objeto"><UTextarea v-model="state.objeto" class="w-full" :rows="3" required /></UFormField>
      <UFormField label="Observaciones" name="observaciones" hint="Opcional" :error="errors.observaciones"><UTextarea v-model="state.observaciones" class="w-full" :rows="3" /></UFormField>
    </section>

    <UAlert color="info" description="Guardar no registra el contrato. Podrá revisarlo y registrarlo desde la vista de detalle." icon="i-lucide-info" variant="subtle" />
    <div class="flex flex-col-reverse justify-end gap-3 border-t border-default pt-5 sm:flex-row">
      <UButton color="neutral" label="Cancelar" type="button" variant="outline" :disabled="loading" @click="emit('cancel')" />
      <UButton icon="i-lucide-save" :label="submitLabel" type="submit" :loading="loading" :disabled="!state.edificio_id || !state.proveedor_id" />
    </div>
  </form>
</template>
