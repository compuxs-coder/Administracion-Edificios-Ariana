<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import type { ConceptoCobroFormData, FormaCalculoCobro, PeriodicidadCobro, TipoConceptoCobro } from '../../types'

const props = withDefaults(defineProps<{
  edificios?: Array<{ id: string, nombre: string }>
  selectedEdificioId?: string | null
  initial?: Partial<ConceptoCobroFormData>
  errors?: Record<string, string>
  loading?: boolean
  requireEdificio?: boolean
  submitLabel?: string
}>(), {
  edificios: () => [],
  selectedEdificioId: null,
  initial: () => ({}),
  errors: () => ({}),
  loading: false,
  requireEdificio: false,
  submitLabel: 'Guardar concepto'
})

const emit = defineEmits<{
  submit: [data: ConceptoCobroFormData]
  cancel: []
}>()

const selectedBuilding = props.edificios.some(item => item.id === props.selectedEdificioId)
  ? props.selectedEdificioId ?? ''
  : ''
const defaultBuilding = selectedBuilding || (props.edificios.length === 1 ? props.edificios[0]?.id ?? '' : '')
const state = reactive<ConceptoCobroFormData>({
  edificio_id: props.initial.edificio_id ?? defaultBuilding,
  codigo: props.initial.codigo ?? '',
  nombre: props.initial.nombre ?? '',
  descripcion: props.initial.descripcion ?? '',
  tipo: props.initial.tipo ?? 'ordinario',
  periodicidad: props.initial.periodicidad ?? 'mensual',
  forma_calculo: props.initial.forma_calculo ?? 'valor_fijo',
  estado: props.initial.estado ?? 'activo'
})

const tipoItems: Array<{ label: string, value: TipoConceptoCobro }> = [
  { label: 'Ordinario', value: 'ordinario' },
  { label: 'Extraordinario', value: 'extraordinario' },
  { label: 'Consumo', value: 'consumo' },
  { label: 'Multa', value: 'multa' },
  { label: 'Interés', value: 'interes' },
  { label: 'Otro', value: 'otro' }
]
const periodicidadItems: Array<{ label: string, value: PeriodicidadCobro }> = [
  { label: 'Mensual', value: 'mensual' },
  { label: 'Trimestral', value: 'trimestral' },
  { label: 'Semestral', value: 'semestral' },
  { label: 'Anual', value: 'anual' },
  { label: 'Único', value: 'unico' },
  { label: 'Manual', value: 'manual' }
]
const allCalculoItems: Array<{ label: string, value: FormaCalculoCobro }> = [
  { label: 'Valor fijo', value: 'valor_fijo' },
  { label: 'Por alícuota', value: 'por_alicuota' },
  { label: 'Porcentaje', value: 'porcentaje' },
  { label: 'Por consumo', value: 'por_consumo' },
  { label: 'Manual', value: 'manual' }
]
const calculoItems = computed(() => {
  if (state.tipo === 'consumo') return allCalculoItems.filter(item => item.value === 'por_consumo')
  if (state.tipo === 'interes') return allCalculoItems.filter(item => item.value === 'porcentaje')
  return allCalculoItems.filter(item => item.value !== 'por_consumo')
})

watch(() => state.tipo, tipo => {
  if (tipo === 'consumo') state.forma_calculo = 'por_consumo'
  else if (tipo === 'interes') state.forma_calculo = 'porcentaje'
  else if (state.forma_calculo === 'por_consumo') state.forma_calculo = 'valor_fijo'
})
</script>

<template>
  <form class="space-y-7" @submit.prevent="emit('submit', { ...state })">
    <section v-if="requireEdificio" class="space-y-4">
      <div><p class="text-sm font-semibold text-highlighted">Alcance administrativo</p><p class="mt-1 text-xs text-muted">El concepto y todas sus tarifas pertenecen a un único edificio.</p></div>
      <UFormField label="Edificio" name="edificio_id" required :error="errors.edificioId"><USelect v-model="state.edificio_id" class="w-full" :items="edificios.map(item => ({ label: item.nombre, value: item.id }))" placeholder="Seleccione edificio" required size="xl" /></UFormField>
    </section>

    <section class="space-y-4" :class="requireEdificio ? 'border-t border-default pt-6' : ''">
      <div><p class="text-sm font-semibold text-highlighted">Concepto de cobro</p><p class="mt-1 text-xs text-muted">Defina el tipo, periodicidad y forma de cálculo que ETAPA 6 utilizará para generar cargos.</p></div>
      <div class="grid gap-4 md:grid-cols-2">
        <UFormField label="Código" name="codigo" required :error="errors.codigo"><UInput v-model="state.codigo" class="w-full" placeholder="ALICUOTA" required size="xl" /></UFormField>
        <UFormField label="Nombre" name="nombre" required :error="errors.nombre"><UInput v-model="state.nombre" class="w-full" placeholder="Alícuota ordinaria mensual" required size="xl" /></UFormField>
        <UFormField label="Tipo" name="tipo" required :error="errors.tipo"><USelect v-model="state.tipo" class="w-full" :items="tipoItems" required size="xl" /></UFormField>
        <UFormField label="Periodicidad" name="periodicidad" required :error="errors.periodicidad"><USelect v-model="state.periodicidad" class="w-full" :items="periodicidadItems" required size="xl" /></UFormField>
        <UFormField label="Forma de cálculo" name="forma_calculo" required :error="errors.formaCalculo"><USelect v-model="state.forma_calculo" class="w-full" :items="calculoItems" required size="xl" /></UFormField>
        <UFormField label="Estado" name="estado" required :error="errors.estado"><USelect v-model="state.estado" class="w-full" :items="[{ label: 'Activo', value: 'activo' }, { label: 'Inactivo', value: 'inactivo' }]" required size="xl" /></UFormField>
      </div>
      <UFormField label="Descripción" name="descripcion" hint="Opcional" :error="errors.descripcion"><UTextarea v-model="state.descripcion" class="w-full" :rows="3" placeholder="Detalle administrativo del concepto" /></UFormField>
    </section>

    <UAlert v-if="state.tipo === 'consumo'" color="info" description="La tarifa solicitará unidad y precio por unidad. Los consumos se obtienen de lecturas acumulativas registradas por período." icon="i-lucide-gauge" variant="subtle" />
    <UAlert v-if="state.tipo === 'interes'" color="info" description="La tarifa solicitará porcentaje y base de cálculo para preparar intereses por mora." icon="i-lucide-percent" variant="subtle" />

    <div class="flex flex-col-reverse justify-end gap-3 border-t border-default pt-5 sm:flex-row"><UButton color="neutral" label="Cancelar" type="button" variant="outline" :disabled="loading" @click="emit('cancel')" /><UButton icon="i-lucide-save" :label="submitLabel" type="submit" :loading="loading" :disabled="requireEdificio && !state.edificio_id" /></div>
  </form>
</template>
