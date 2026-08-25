<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import DepartamentoForm from '../../components/departamentos/DepartamentoForm.vue'
import type { DepartamentoFormData, EdificioEstructuraOption } from '../../types'

const props = defineProps<{
  edificios: EdificioEstructuraOption[]
  edificioSeleccionado?: string | null
}>()
const page = usePage()
const isLoading = ref(false)
const errors = computed<Record<string, string>>(() => {
  const result: Record<string, string> = {}
  Object.entries(page.props.errors ?? {}).forEach(([field, error]) => {
    result[field] = Array.isArray(error) ? String(error[0]) : String(error)
  })
  return result
})

const submit = (data: DepartamentoFormData) => {
  router.post(route('departamentos.store', data.edificio_id), data, {
    onStart: () => { isLoading.value = true },
    onFinish: () => { isLoading.value = false }
  })
}
</script>

<template>
  <UDashboardPanel id="departamentos-create">
    <template #header>
      <UDashboardNavbar title="Nuevo departamento">
        <template #leading><UDashboardSidebarCollapse /></template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <div class="mx-auto w-full max-w-5xl p-4 sm:p-6">
        <div class="mb-6">
          <p class="text-sm font-medium text-primary">Estructura física</p>
          <h1 class="mt-1 text-2xl font-semibold text-highlighted">Registrar departamento</h1>
          <p class="mt-2 text-sm text-muted">Defina su ubicación, alícuota y anexos administrativos.</p>
        </div>
        <UCard>
          <DepartamentoForm
            :edificios="edificios"
            :selected-edificio-id="edificioSeleccionado"
            :errors="errors"
            :loading="isLoading"
            @cancel="router.visit(route('departamentos.index'))"
            @submit="submit"
          />
        </UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
