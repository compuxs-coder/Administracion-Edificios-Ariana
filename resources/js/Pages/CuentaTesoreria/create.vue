<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import CuentaTesoreriaForm from '../../components/cuentas-tesoreria/CuentaTesoreriaForm.vue'
import type { CuentaTesoreria, CuentaTesoreriaFormData } from '../../types'

const props = defineProps<{
  cuenta?: CuentaTesoreria | null
  edificios: Array<{ id: string, nombre: string }>
  edificioSeleccionado?: string | null
}>()
const page = usePage()
const loading = ref(false)
const errors = computed<Record<string, string>>(() => Object.fromEntries(
  Object.entries(page.props.errors ?? {}).map(([field, error]) => [field, Array.isArray(error) ? String(error[0]) : String(error)])
))
const initial = computed<Partial<CuentaTesoreriaFormData>>(() => ({
  edificio_id: props.cuenta?.edificioId ?? props.edificioSeleccionado ?? ''
}))

const submit = (data: CuentaTesoreriaFormData) => router.post(route('cuentas-tesoreria.store', data.edificio_id), data, {
  onStart: () => { loading.value = true },
  onFinish: () => { loading.value = false }
})
</script>

<template>
  <UDashboardPanel id="cuentas-tesoreria-create">
    <template #header><UDashboardNavbar title="Nueva cuenta de tesoreria"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar></template>
    <template #body>
      <div class="mx-auto w-full max-w-4xl p-4 sm:p-6">
        <div class="mb-6"><p class="text-sm font-medium text-primary">Tesoreria</p><h1 class="mt-1 text-2xl font-semibold text-highlighted">Registrar cuenta</h1><p class="mt-2 text-sm text-muted">Configure una cuenta bancaria o caja para registrar movimientos del edificio.</p></div>
        <UCard><CuentaTesoreriaForm :edificios="edificios" :initial="initial" :errors="errors" :loading="loading" @cancel="router.visit(route('cuentas-tesoreria.index'))" @submit="submit" /></UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
