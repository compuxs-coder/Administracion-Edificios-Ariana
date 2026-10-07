<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PlanMantenimientoPreventivoForm from '../../components/operaciones/PlanMantenimientoPreventivoForm.vue'
import type { ContratoOperacionOption, EdificioOperacionOption, ElementoOperacionOption, PlanMantenimientoPreventivoFormData, ProveedorOperacionOption } from '../../types'

const props = defineProps<{ edificios: EdificioOperacionOption[], torres: ElementoOperacionOption[], pisos: ElementoOperacionOption[], departamentos: ElementoOperacionOption[], parqueaderos: ElementoOperacionOption[], bodegas: ElementoOperacionOption[], proveedores: ProveedorOperacionOption[], contratos: ContratoOperacionOption[], edificioSeleccionado?: string | null }>()
const page = usePage()
const loading = ref(false)
const errors = computed<Record<string, string>>(() => Object.fromEntries(Object.entries(page.props.errors ?? {}).map(([field, error]) => [field, Array.isArray(error) ? String(error[0]) : String(error)])))
const submit = (data: PlanMantenimientoPreventivoFormData) => router.post(route('mantenimiento-preventivo.store', data.edificio_id), data, { onStart: () => { loading.value = true }, onFinish: () => { loading.value = false } })
</script>

<template><UDashboardPanel id="mantenimiento-create"><template #header><UDashboardNavbar title="Nuevo plan preventivo"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar></template><template #body><div class="mx-auto w-full max-w-4xl p-4 sm:p-6"><div class="mb-6"><p class="text-sm font-medium text-primary">Operaciones</p><h1 class="mt-1 text-2xl font-semibold text-highlighted">Programar mantenimiento preventivo</h1><p class="mt-2 text-sm text-muted">Defina una recurrencia controlada que generará órdenes operativas mediante el scheduler.</p></div><UCard><PlanMantenimientoPreventivoForm v-bind="props" :selected-edificio-id="edificioSeleccionado" :errors="errors" :loading="loading" @cancel="router.visit(route('mantenimiento-preventivo.index'))" @submit="submit" /></UCard></div></template></UDashboardPanel></template>
