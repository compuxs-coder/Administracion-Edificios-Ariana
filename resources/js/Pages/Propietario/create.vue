<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PropietarioForm from '../../components/propietarios/PropietarioForm.vue'
import type { PropietarioFormData } from '../../types'

defineProps<{
  edificios: Array<{ id: string, nombre: string }>
  edificioSeleccionado?: string | null
}>()
const page = usePage()
const loading = ref(false)
const errors = computed<Record<string, string>>(() => Object.fromEntries(
  Object.entries(page.props.errors ?? {}).map(([field, error]) => [field, Array.isArray(error) ? String(error[0]) : String(error)])
))

const submit = (data: PropietarioFormData) => {
  router.post(route('propietarios.store', data.edificio_id), data, {
    onStart: () => { loading.value = true },
    onFinish: () => { loading.value = false }
  })
}
</script>

<template>
  <UDashboardPanel id="propietarios-create">
    <template #header>
      <UDashboardNavbar title="Nuevo propietario"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar>
    </template>
    <template #body>
      <div class="mx-auto w-full max-w-4xl p-4 sm:p-6">
        <div class="mb-6">
          <p class="text-sm font-medium text-primary">Directorio de propiedad</p>
          <h1 class="mt-1 text-2xl font-semibold text-highlighted">Registrar propietario</h1>
          <p class="mt-2 text-sm text-muted">La titularidad de departamentos se asigna después desde el detalle de cada unidad.</p>
        </div>
        <UCard>
          <PropietarioForm
            :edificios="edificios"
            :selected-edificio-id="edificioSeleccionado"
            :errors="errors"
            :loading="loading"
            require-edificio
            @cancel="router.visit(route('propietarios.index'))"
            @submit="submit"
          />
        </UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
