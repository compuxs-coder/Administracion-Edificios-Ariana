<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import GastoForm from '../../components/gastos/GastoForm.vue'
import type { ContratoProveedorOption, GastoFormData, ProveedorOption } from '../../types'

const props = defineProps<{
  edificios: Array<{ id: string, nombre: string }>
  proveedores: ProveedorOption[]
  contratos: ContratoProveedorOption[]
  edificioSeleccionado?: string | null
  proveedorSeleccionado?: string | null
  contratoSeleccionado?: string | null
}>()
const page = usePage()
const loading = ref(false)
const errors = computed<Record<string, string>>(() => Object.fromEntries(
  Object.entries(page.props.errors ?? {}).map(([field, error]) => [field, Array.isArray(error) ? String(error[0]) : String(error)])
))
const initial = computed<Partial<GastoFormData>>(() => ({
  proveedor_id: props.proveedorSeleccionado ?? '',
  contrato_id: props.contratoSeleccionado ?? ''
}))
const submit = (data: GastoFormData) => router.post(route('gastos.store', data.edificio_id), data, {
  onStart: () => { loading.value = true },
  onFinish: () => { loading.value = false }
})
</script>

<template>
  <UDashboardPanel id="gastos-create">
    <template #header><UDashboardNavbar title="Nuevo gasto"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar></template>
    <template #body><div class="mx-auto w-full max-w-4xl p-4 sm:p-6"><div class="mb-6"><p class="text-sm font-medium text-primary">Control de gastos</p><h1 class="mt-1 text-2xl font-semibold text-highlighted">Crear borrador</h1><p class="mt-2 text-sm text-muted">El registro contable y la cuenta por pagar se generan únicamente al confirmar el borrador.</p></div><UCard><GastoForm :edificios="edificios" :proveedores="proveedores" :contratos="contratos" :selected-edificio-id="edificioSeleccionado" :initial="initial" :errors="errors" :loading="loading" @cancel="router.visit(route('gastos.index'))" @submit="submit" /></UCard></div></template>
  </UDashboardPanel>
</template>
