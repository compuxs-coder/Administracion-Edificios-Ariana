<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import GastoForm from '../../components/gastos/GastoForm.vue'
import type { ContratoProveedorOption, Gasto, GastoFormData, ProveedorOption } from '../../types'

const props = defineProps<{
  gasto: Gasto
  edificios: Array<{ id: string, nombre: string }>
  proveedores: ProveedorOption[]
  contratos: ContratoProveedorOption[]
}>()
const page = usePage()
const loading = ref(false)
const errors = computed<Record<string, string>>(() => Object.fromEntries(
  Object.entries(page.props.errors ?? {}).map(([field, error]) => [field, Array.isArray(error) ? String(error[0]) : String(error)])
))
const initial = computed<GastoFormData>(() => ({
  edificio_id: props.gasto.edificioId,
  proveedor_id: props.gasto.proveedorId,
  contrato_id: props.gasto.contratoId ?? '',
  fecha_gasto: props.gasto.fechaGasto,
  concepto: props.gasto.concepto,
  fecha_vencimiento: props.gasto.fechaVencimiento ?? '',
  referencia: props.gasto.referencia ?? '',
  monto: props.gasto.monto,
  tipo_pago: props.gasto.tipoPago,
  observaciones: props.gasto.observaciones ?? ''
}))
const submit = (data: GastoFormData) => {
  if (props.gasto.estado !== 'borrador') return
  router.put(route('gastos.update', [props.gasto.edificioId, props.gasto.id]), data, {
    onStart: () => { loading.value = true },
    onFinish: () => { loading.value = false }
  })
}
</script>

<template>
  <UDashboardPanel id="gastos-edit">
    <template #header><UDashboardNavbar :title="`Editar · ${gasto.numero || 'borrador'}`"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar></template>
    <template #body><div class="mx-auto w-full max-w-4xl p-4 sm:p-6"><UAlert v-if="gasto.estado !== 'borrador'" class="mb-5" color="warning" description="Solo los gastos en borrador pueden editarse." icon="i-lucide-lock" variant="subtle" /><UCard><GastoForm :edificios="edificios" :proveedores="proveedores" :contratos="contratos" :initial="initial" :errors="errors" :loading="loading" :lock-scope="true" submit-label="Actualizar borrador" @cancel="router.visit(route('gastos.show', [gasto.edificioId, gasto.id]))" @submit="submit" /></UCard></div></template>
  </UDashboardPanel>
</template>
