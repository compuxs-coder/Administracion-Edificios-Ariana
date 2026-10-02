<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import OrdenOperativaForm from '../../components/operaciones/OrdenOperativaForm.vue'
import type { ContratoOperacionOption, EdificioOperacionOption, ElementoOperacionOption, OrdenOperativaFormData, ProveedorOperacionOption, ReportanteOperacionOption } from '../../types'

const props = defineProps<{
  edificios: EdificioOperacionOption[]
  torres: ElementoOperacionOption[]
  pisos: ElementoOperacionOption[]
  departamentos: ElementoOperacionOption[]
  parqueaderos: ElementoOperacionOption[]
  bodegas: ElementoOperacionOption[]
  residentes: ReportanteOperacionOption[]
  proveedores: ProveedorOperacionOption[]
  contratos: ContratoOperacionOption[]
  edificioSeleccionado?: string | null
}>()
const page = usePage()
const loading = ref(false)
const errors = computed<Record<string, string>>(() => Object.fromEntries(
  Object.entries(page.props.errors ?? {}).map(([field, error]) => [field, Array.isArray(error) ? String(error[0]) : String(error)])
))
const formOptions = computed(() => ({
  edificios: props.edificios,
  torres: props.torres,
  pisos: props.pisos,
  departamentos: props.departamentos,
  parqueaderos: props.parqueaderos,
  bodegas: props.bodegas,
  residentes: props.residentes,
  proveedores: props.proveedores,
  contratos: props.contratos
}))
const submit = (data: OrdenOperativaFormData) => router.post(route('operaciones.store', data.edificio_id), data, {
  onStart: () => { loading.value = true },
  onFinish: () => { loading.value = false }
})
</script>

<template>
  <UDashboardPanel id="operaciones-create">
    <template #header><UDashboardNavbar title="Nueva orden operativa"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar></template>
    <template #body><div class="mx-auto w-full max-w-4xl p-4 sm:p-6"><div class="mb-6"><p class="text-sm font-medium text-primary">Operaciones</p><h1 class="mt-1 text-2xl font-semibold text-highlighted">Reportar incidencia o solicitud</h1><p class="mt-2 text-sm text-muted">La orden recibirá un consecutivo anual y conservará actor, ubicación y reportante.</p></div><UAlert v-if="errors.numero" class="mb-5" color="error" :description="errors.numero" icon="i-lucide-circle-alert" variant="subtle" /><UCard><OrdenOperativaForm v-bind="formOptions" :selected-edificio-id="edificioSeleccionado" :errors="errors" :loading="loading" @cancel="router.visit(route('operaciones.index'))" @submit="submit" /></UCard></div></template>
  </UDashboardPanel>
</template>
