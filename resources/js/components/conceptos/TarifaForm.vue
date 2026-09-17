<script setup lang="ts">
import { computed, reactive } from 'vue'
import type { AlcanceTarifa, BaseCalculoInteres, FormaCalculoCobro, TarifaConceptoFormData, TipoConceptoCobro } from '../../types'

const props = withDefaults(defineProps<{
  formaCalculo: FormaCalculoCobro
  tipo: TipoConceptoCobro
  departamentos: Array<{ id: string, codigo: string, nombre: string }>
  errors?: Record<string, string>
  loading?: boolean
}>(), { errors: () => ({}), loading: false })

const emit = defineEmits<{ submit: [data: TarifaConceptoFormData], cancel: [] }>()
const today = (() => { const date = new Date(); return [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-') })()
const state = reactive<TarifaConceptoFormData>({
  valor: '', porcentaje: '', monto_total: '', numero_cuotas: '', unidad: '', base_calculo: '',
  fecha_inicio: today, fecha_fin: '', alcance: 'todo_el_edificio', departamentos: [], observacion: ''
})
const needsValor = computed(() => ['valor_fijo', 'por_alicuota', 'por_consumo'].includes(props.formaCalculo))
const needsPorcentaje = computed(() => props.formaCalculo === 'porcentaje')
const supportsInstallments = computed(() => props.tipo === 'extraordinario' && ['valor_fijo', 'por_alicuota'].includes(props.formaCalculo))
const isConsumo = computed(() => props.formaCalculo === 'por_consumo')
const alcanceItems: Array<{ label: string, value: AlcanceTarifa }> = [
  { label: 'Todo el edificio', value: 'todo_el_edificio' },
  { label: 'Departamentos específicos', value: 'departamentos_especificos' }
]
const baseItems: Array<{ label: string, value: BaseCalculoInteres }> = [
  { label: 'Saldo vencido', value: 'saldo_vencido' },
  { label: 'Capital vencido', value: 'capital_vencido' },
  { label: 'Saldo total', value: 'saldo_total' }
]
</script>

<template>
  <form class="space-y-5" @submit.prevent="emit('submit', { ...state, departamentos: [...state.departamentos] })">
    <UAlert v-if="errors.fechaInicio || errors.departamentos || errors.concepto" color="error" :description="errors.fechaInicio || errors.departamentos || errors.concepto" icon="i-lucide-circle-alert" role="alert" variant="subtle" />
    <div class="grid gap-4 sm:grid-cols-2">
      <UFormField label="Fecha inicio" name="fecha_inicio" required :error="errors.fechaInicio"><UInput v-model="state.fecha_inicio" type="date" class="w-full" required size="xl" /></UFormField>
      <UFormField label="Fecha fin" name="fecha_fin" hint="Opcional" :error="errors.fechaFin"><UInput v-model="state.fecha_fin" type="date" class="w-full" :min="state.fecha_inicio || undefined" size="xl" /></UFormField>
      <UFormField v-if="needsValor" :label="isConsumo ? 'Precio por unidad' : formaCalculo === 'por_alicuota' ? 'Presupuesto base' : 'Valor'" name="valor" required :error="errors.valor"><UInput v-model="state.valor" class="w-full" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,4})?" placeholder="0.0000" required size="xl" /></UFormField>
      <UFormField v-if="needsPorcentaje" label="Porcentaje" name="porcentaje" required :error="errors.porcentaje"><UInput v-model="state.porcentaje" class="w-full" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,6})?" placeholder="0.000000" required size="xl" /></UFormField>
      <UFormField v-if="isConsumo" label="Unidad" name="unidad" required :error="errors.unidad"><UInput v-model="state.unidad" class="w-full" placeholder="m3, kWh, unidad" required size="xl" /></UFormField>
      <UFormField v-if="needsPorcentaje" label="Base de cálculo" name="base_calculo" required :error="errors.baseCalculo"><USelect v-model="state.base_calculo" class="w-full" :items="baseItems" placeholder="Seleccione base" required size="xl" /></UFormField>
      <UFormField v-if="supportsInstallments" label="Monto total" name="monto_total" hint="Opcional, junto a cuotas" :error="errors.montoTotal"><UInput v-model="state.monto_total" class="w-full" inputmode="decimal" pattern="[0-9]+([.][0-9]{1,4})?" placeholder="0.0000" size="xl" /></UFormField>
      <UFormField v-if="supportsInstallments" label="Número de cuotas" name="numero_cuotas" hint="Opcional, junto a monto total" :error="errors.numeroCuotas"><UInput v-model="state.numero_cuotas" class="w-full" type="number" min="1" step="1" size="xl" /></UFormField>
    </div>

    <section class="space-y-3 border-t border-default pt-5"><div><p class="text-sm font-semibold text-highlighted">Alcance</p><p class="mt-1 text-xs text-muted">La tarifa se versiona con su alcance. Torre y piso podrán añadirse como extensiones futuras sin alterar este historial.</p></div><UFormField label="Aplicar a" name="alcance" required :error="errors.alcance"><USelect v-model="state.alcance" class="w-full" :items="alcanceItems" required size="xl" /></UFormField><div v-if="state.alcance === 'departamentos_especificos'" class="max-h-48 space-y-2 overflow-y-auto rounded-xl border border-default p-3"><UCheckbox v-for="departamento in departamentos" :key="departamento.id" v-model="state.departamentos" :value="departamento.id" :label="`${departamento.codigo} · ${departamento.nombre}`" /><p v-if="!departamentos.length" class="text-sm text-muted">No hay departamentos activos disponibles.</p></div></section>
    <UFormField label="Observación" name="observacion" hint="Opcional" :error="errors.observacion"><UTextarea v-model="state.observacion" class="w-full" :rows="3" placeholder="Motivo o referencia de la vigencia" /></UFormField>
    <div class="flex justify-end gap-3 border-t border-default pt-4"><UButton color="neutral" label="Cancelar" type="button" variant="outline" :disabled="loading" @click="emit('cancel')" /><UButton icon="i-lucide-plus" label="Registrar tarifa" type="submit" :loading="loading" /></div>
  </form>
</template>
