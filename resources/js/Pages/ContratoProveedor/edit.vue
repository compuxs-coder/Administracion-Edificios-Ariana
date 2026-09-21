<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import ContratoProveedorForm from '../../components/contratos-proveedor/ContratoProveedorForm.vue'
import type { ContratoProveedor, ContratoProveedorFormData, ProveedorOption } from '../../types'

const props = defineProps<{
  contrato: ContratoProveedor
  edificios: Array<{ id: string, nombre: string }>
  proveedores: ProveedorOption[]
}>()
const page = usePage()
const loading = ref(false)
const errors = computed<Record<string, string>>(() => Object.fromEntries(
  Object.entries(page.props.errors ?? {}).map(([field, error]) => [field, Array.isArray(error) ? String(error[0]) : String(error)])
))
const initial = computed<ContratoProveedorFormData>(() => ({
  edificio_id: props.contrato.edificioId,
  proveedor_id: props.contrato.proveedorId,
  referencia: props.contrato.referencia,
  objeto: props.contrato.objeto,
  fecha_inicio: props.contrato.fechaInicio,
  fecha_fin: props.contrato.fechaFin ?? '',
  monto_total: props.contrato.montoTotal ?? '',
  observaciones: props.contrato.observaciones ?? ''
}))
const submit = (data: ContratoProveedorFormData) => {
  if (props.contrato.estado !== 'borrador') return
  router.put(route('contratos-proveedor.update', [props.contrato.edificioId, props.contrato.id]), data, {
    onStart: () => { loading.value = true },
    onFinish: () => { loading.value = false }
  })
}
</script>

<template>
  <UDashboardPanel id="contratos-proveedor-edit">
    <template #header><UDashboardNavbar :title="`Editar · ${contrato.referencia}`"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar></template>
    <template #body><div class="mx-auto w-full max-w-4xl p-4 sm:p-6"><UAlert v-if="contrato.estado !== 'borrador'" class="mb-5" color="warning" description="Solo los contratos en borrador pueden editarse." icon="i-lucide-lock" variant="subtle" /><UCard><ContratoProveedorForm :edificios="edificios" :proveedores="proveedores" :initial="initial" :errors="errors" :loading="loading" :lock-scope="true" submit-label="Actualizar borrador" @cancel="router.visit(route('contratos-proveedor.show', [contrato.edificioId, contrato.id]))" @submit="submit" /></UCard></div></template>
  </UDashboardPanel>
</template>
