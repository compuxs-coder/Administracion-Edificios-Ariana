<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import ResidenteForm from '../../components/residentes/ResidenteForm.vue'
import type { Residente, ResidenteFormData } from '../../types'

const props = defineProps<{ residente: Residente }>()
const page = usePage()
const loading = ref(false)
const errors = computed<Record<string, string>>(() => Object.fromEntries(
  Object.entries(page.props.errors ?? {}).map(([field, error]) => [field, Array.isArray(error) ? String(error[0]) : String(error)])
))
const initial = computed<ResidenteFormData>(() => ({
  edificio_id: '',
  nombres: props.residente.nombres,
  apellidos: props.residente.apellidos,
  tipo_identificacion: props.residente.tipoIdentificacion,
  identificacion: props.residente.identificacion,
  telefono: props.residente.telefono ?? '',
  celular: props.residente.celular ?? '',
  correo: props.residente.correo ?? '',
  direccion: props.residente.direccion ?? '',
  observaciones: props.residente.observaciones ?? ''
}))

const submit = (data: ResidenteFormData) => {
  router.put(route('residentes.update', props.residente.id), data, {
    onStart: () => { loading.value = true },
    onFinish: () => { loading.value = false }
  })
}
</script>

<template>
  <UDashboardPanel id="residentes-edit">
    <template #header>
      <UDashboardNavbar title="Editar residente"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar>
    </template>
    <template #body>
      <div class="mx-auto w-full max-w-4xl p-4 sm:p-6">
        <div class="mb-6">
          <p class="text-sm font-medium text-primary">{{ residente.identificacion }}</p>
          <h1 class="mt-1 text-2xl font-semibold text-highlighted">{{ residente.nombre }}</h1>
          <p class="mt-2 text-sm text-muted">La identidad compartida se actualiza; los snapshots de ocupaciones anteriores permanecen intactos.</p>
        </div>
        <UCard>
          <ResidenteForm
            :initial="initial"
            :errors="errors"
            :loading="loading"
            submit-label="Actualizar residente"
            @cancel="router.visit(route('residentes.show', residente.id))"
            @submit="submit"
          />
        </UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
