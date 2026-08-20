<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import EdificioForm from '../../components/edificios/EdificioForm.vue'
import type { EdificioFormData } from '../../types'

const page = usePage()
const isLoading = ref(false)

const errors = computed<Record<string, string>>(() => {
  const result: Record<string, string> = {}

  Object.entries(page.props.errors ?? {}).forEach(([field, error]) => {
    result[field] = Array.isArray(error) ? String(error[0]) : String(error)
  })

  return result
})

const handleSubmit = (data: EdificioFormData) => {
  router.post(route('edificios.store'), data, {
    onStart: () => { isLoading.value = true },
    onFinish: () => { isLoading.value = false }
  })
}
</script>

<template>
  <UDashboardPanel id="edificios-create">
    <template #header>
      <UDashboardNavbar title="Nuevo edificio">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="mx-auto w-full max-w-4xl p-4 sm:p-6">
        <div class="mb-6">
          <p class="text-sm font-medium text-primary">Configuración inicial</p>
          <h1 class="mt-1 text-2xl font-semibold text-highlighted">Registrar edificio</h1>
          <p class="mt-2 text-sm text-muted">
            El usuario que registra el edificio recibirá acceso inicial automáticamente.
          </p>
        </div>

        <UCard>
          <EdificioForm
            :errors="errors"
            :loading="isLoading"
            @cancel="router.visit(route('edificios.index'))"
            @submit="handleSubmit"
          />
        </UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
