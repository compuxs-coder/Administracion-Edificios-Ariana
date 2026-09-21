<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import ContratoProveedorForm from '../../components/contratos-proveedor/ContratoProveedorForm.vue'
import type { ContratoProveedorFormData, ProveedorOption } from '../../types'

const props = defineProps<{
  edificios: Array<{ id: string, nombre: string }>
  proveedores: ProveedorOption[]
  edificioSeleccionado?: string | null
  proveedorSeleccionado?: string | null
}>()
const page = usePage()
const loading = ref(false)
const errors = computed<Record<string, string>>(() => Object.fromEntries(
  Object.entries(page.props.errors ?? {}).map(([field, error]) => [field, Array.isArray(error) ? String(error[0]) : String(error)])
))
const initial = computed<Partial<ContratoProveedorFormData>>(() => ({ proveedor_id: props.proveedorSeleccionado ?? '' }))
const submit = (data: ContratoProveedorFormData) => router.post(route('contratos-proveedor.store', data.edificio_id), data, {
  onStart: () => { loading.value = true },
  onFinish: () => { loading.value = false }
})
</script>

<template>
  <UDashboardPanel id="contratos-proveedor-create">
    <template #header><UDashboardNavbar title="Nuevo contrato"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar></template>
    <template #body><div class="mx-auto w-full max-w-4xl p-4 sm:p-6"><div class="mb-6"><p class="text-sm font-medium text-primary">Contratos de proveedores</p><h1 class="mt-1 text-2xl font-semibold text-highlighted">Crear borrador</h1><p class="mt-2 text-sm text-muted">Revise las condiciones antes de registrar el contrato.</p></div><UCard><ContratoProveedorForm :edificios="edificios" :proveedores="proveedores" :selected-edificio-id="edificioSeleccionado" :initial="initial" :errors="errors" :loading="loading" @cancel="router.visit(route('contratos-proveedor.index'))" @submit="submit" /></UCard></div></template>
  </UDashboardPanel>
</template>
