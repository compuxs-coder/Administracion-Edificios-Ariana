<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import EdificioForm from '../../components/edificios/EdificioForm.vue'
import type { Edificio, EdificioFormData } from '../../types'

const props = defineProps<{ edificio: Edificio }>()
const page = usePage()
const isLoading = ref(false)

const initial = computed<EdificioFormData>(() => ({
  nombre: props.edificio.nombre,
  ruc: props.edificio.ruc ?? '',
  direccion: props.edificio.direccion,
  ciudad: props.edificio.ciudad,
  telefono: props.edificio.telefono ?? '',
  correo: props.edificio.correo ?? '',
  responsable: props.edificio.responsable ?? ''
}))

const errors = computed<Record<string, string>>(() => {
  const result: Record<string, string> = {}

  Object.entries(page.props.errors ?? {}).forEach(([field, error]) => {
    result[field] = Array.isArray(error) ? String(error[0]) : String(error)
  })

  return result
})

const handleSubmit = (data: EdificioFormData) => {
  router.put(route('edificios.update', props.edificio.id), data, {
    onStart: () => { isLoading.value = true },
    onFinish: () => { isLoading.value = false }
  })
}
</script>

<template>
  <UDashboardPanel id="edificios-edit">
    <template #header>
      <UDashboardNavbar title="Editar edificio">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="mx-auto w-full max-w-4xl p-4 sm:p-6">
        <div class="mb-6">
          <p class="text-sm font-medium text-primary">{{ edificio.nombre }}</p>
          <h1 class="mt-1 text-2xl font-semibold text-highlighted">Información administrativa</h1>
          <p class="mt-2 text-sm text-muted">Actualice únicamente información verificada del edificio.</p>
        </div>

        <UCard>
          <EdificioForm
            :initial="initial"
            :errors="errors"
            :loading="isLoading"
            submit-label="Actualizar edificio"
            @cancel="router.visit(route('edificios.show', edificio.id))"
            @submit="handleSubmit"
          />
        </UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
