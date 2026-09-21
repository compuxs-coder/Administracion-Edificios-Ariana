<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import type { ContratoProveedorOption, GastoFormData, ProveedorOption, TipoPagoGasto } from '../../types'

const props = withDefaults(defineProps<{
  edificios?: Array<{ id: string, nombre: string }>
  proveedores?: ProveedorOption[]
  contratos?: ContratoProveedorOption[]
  selectedEdificioId?: string | null
  initial?: Partial<GastoFormData>
  errors?: Record<string, string>
  loading?: boolean
  lockScope?: boolean
  submitLabel?: string
}>(), {
  edificios: () => [],
  proveedores: () => [],
  contratos: () => [],
  selectedEdificioId: null,
  initial: () => ({}),
  errors: () => ({}),
  loading: false,
  lockScope: false,
  submitLabel: 'Guardar borrador'
})

const emit = defineEmits<{
  submit: [data: GastoFormData]
  cancel: []
}>()

const today = new Date().toISOString().slice(0, 10)
const selectedBuilding = props.edificios.some(item => item.id === props.selectedEdificioId)
  ? props.selectedEdificioId ?? ''
  : ''
const state = reactive<GastoFormData>({
  edificio_id: props.initial.edificio_id || selectedBuilding || (props.edificios.length === 1 ? props.edificios[0]?.id ?? '' : ''),
  proveedor_id: props.initial.proveedor_id ?? '',
  contrato_id: props.initial.contrato_id ?? '',
  fecha_gasto: props.initial.fecha_gasto ?? today,
  concepto: props.initial.concepto ?? '',
  fecha_vencimiento: props.initial.fecha_vencimiento ?? '',
  referencia: props.initial.referencia ?? '',
  monto: props.initial.monto ?? '',
  tipo_pago: props.initial.tipo_pago ?? 'contado',
  observaciones: props.initial.observaciones ?? ''
})
const amounts = reactive({ subtotal: props.initial.monto ?? '', impuestos: '0.0000' })

const providerItems = computed(() => props.proveedores
  .filter(item => item.edificioId === state.edificio_id && item.estado !== 'inactivo')
  .map(item => ({ label: `${item.nombre} · ${item.identificacion}`, value: item.id })))
const contractItems = computed(() => props.contratos
  .filter(item => item.edificioId === state.edificio_id && item.proveedorId === state.proveedor_id && item.estado === 'registrado')
  .map(item => ({ label: `${item.referencia} · ${item.objeto}`, value: item.id })))
const total = computed(() => {
  const value = Number(amounts.subtotal || 0) + Number(amounts.impuestos || 0)
  return Number.isFinite(value) ? value.toFixed(4) : '0.0000'
})
const conditionItems: Array<{ label: string, value: TipoPagoGasto }> = [
  { label: 'Contado', value: 'contado' },
  { label: 'Crédito', value: 'credito' }
]

watch(() => state.edificio_id, (building, previous) => {
  if (!previous || building === previous) return
  state.proveedor_id = ''
  state.contrato_id = ''
})
watch(() => state.proveedor_id, () => { state.contrato_id = '' })
watch(() => state.tipo_pago, type => {
  if (type === 'contado') state.fecha_vencimiento = ''
})
const submit = () => emit('submit', { ...state, monto: total.value })
</script>

<template>
  <form class="space-y-7" @submit.prevent="submit">
    <section class="space-y-4">
      <div><p class="text-sm font-semibold text-highlighted">Origen del gasto</p><p class="mt-1 text-xs text-muted">El contrato es opcional y solo incluye contratos registrados del proveedor y edificio.</p></div>
      <div class="grid gap-4 md:grid-cols-2">
        <UFormField label="Edificio" name="edificio_id" required :error="errors.edificioId"><USelect v-model="state.edificio_id" class="w-full" :disabled="lockScope" :items="edificios.map(item => ({ label: item.nombre, value: item.id }))" placeholder="Seleccione edificio" required size="xl" /></UFormField>
        <UFormField label="Proveedor" name="proveedor_id" required :error="errors.proveedorId"><USelect v-model="state.proveedor_id" class="w-full" :disabled="lockScope || !state.edificio_id" :items="providerItems" placeholder="Seleccione proveedor" required size="xl" /></UFormField>
        <UFormField class="md:col-span-2" label="Contrato" name="contrato_id" hint="Opcional" :error="errors.contratoId"><USelect v-model="state.contrato_id" class="w-full" :disabled="!state.proveedor_id" :items="[{ label: 'Sin contrato asociado', value: '' }, ...contractItems]" /></UFormField>
      </div>
    </section>

    <section class="space-y-4 border-t border-default pt-6">
      <div><p class="text-sm font-semibold text-highlighted">Documento</p><p class="mt-1 text-xs text-muted">Registre los valores tal como constan en el comprobante del proveedor.</p></div>
      <div class="grid gap-4 md:grid-cols-2">
        <UFormField label="Fecha del gasto" name="fecha_gasto" required :error="errors.fechaGasto"><UInput v-model="state.fecha_gasto" class="w-full" type="date" required size="xl" /></UFormField>
        <UFormField label="Referencia" name="referencia" hint="Opcional" :error="errors.referencia"><UInput v-model="state.referencia" class="w-full" icon="i-lucide-hash" size="xl" /></UFormField>
      </div>
      <UFormField label="Concepto" name="concepto" required :error="errors.concepto"><UTextarea v-model="state.concepto" class="w-full" :rows="3" required /></UFormField>
      <div class="grid gap-4 md:grid-cols-2">
        <UFormField label="Subtotal" required :error="errors.monto"><UInput v-model="amounts.subtotal" class="w-full" inputmode="decimal" placeholder="0.0000" required size="xl" /></UFormField>
        <UFormField label="Impuestos" required><UInput v-model="amounts.impuestos" class="w-full" inputmode="decimal" placeholder="0.0000" required size="xl" /></UFormField>
      </div>
      <div class="flex items-center justify-between rounded-xl bg-elevated/60 px-4 py-3"><span class="text-sm font-medium text-muted">Total</span><span class="font-mono text-xl font-semibold text-highlighted">${{ total }}</span></div>
    </section>

    <section class="space-y-4 border-t border-default pt-6">
      <div><p class="text-sm font-semibold text-highlighted">Condición de pago</p><p class="mt-1 text-xs text-muted">Los gastos a crédito generan una cuenta por pagar al registrarse.</p></div>
      <div class="grid gap-4 md:grid-cols-2">
        <UFormField label="Tipo de pago" name="tipo_pago" required :error="errors.tipoPago"><USelect v-model="state.tipo_pago" class="w-full" :items="conditionItems" required size="xl" /></UFormField>
        <UFormField v-if="state.tipo_pago === 'credito'" label="Fecha de vencimiento" name="fecha_vencimiento" required :error="errors.fechaVencimiento"><UInput v-model="state.fecha_vencimiento" class="w-full" type="date" :min="state.fecha_gasto" required size="xl" /></UFormField>
        <UAlert v-else class="md:col-span-2" color="neutral" description="Al registrarse, el gasto de contado quedará marcado como pagado." icon="i-lucide-circle-check" variant="subtle" />
      </div>
      <UFormField label="Observaciones" name="observaciones" hint="Opcional" :error="errors.observaciones"><UTextarea v-model="state.observaciones" class="w-full" :rows="3" /></UFormField>
    </section>

    <UAlert color="info" description="El gasto se guarda como borrador. Los efectos financieros se crean únicamente al registrarlo." icon="i-lucide-info" variant="subtle" />
    <div class="flex flex-col-reverse justify-end gap-3 border-t border-default pt-5 sm:flex-row">
      <UButton color="neutral" label="Cancelar" type="button" variant="outline" :disabled="loading" @click="emit('cancel')" />
      <UButton icon="i-lucide-save" :label="submitLabel" type="submit" :loading="loading" :disabled="!state.edificio_id || !state.proveedor_id" />
    </div>
  </form>
</template>
