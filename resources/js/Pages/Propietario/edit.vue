<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PropietarioForm from '../../components/propietarios/PropietarioForm.vue'
import type { Propietario, PropietarioFormData } from '../../types'

const props = defineProps<{ propietario: Propietario }>()
const page = usePage()
const loading = ref(false)
const errors = computed<Record<string, string>>(() => Object.fromEntries(
  Object.entries(page.props.errors ?? {}).map(([field, error]) => [field, Array.isArray(error) ? String(error[0]) : String(error)])
))
const initial = computed<PropietarioFormData>(() => ({
  edificio_id: '',
  tipo_persona: props.propietario.tipoPersona,
  nombres: props.propietario.nombres ?? '',
  apellidos: props.propietario.apellidos ?? '',
  razon_social: props.propietario.razonSocial ?? '',
  tipo_identificacion: props.propietario.tipoIdentificacion,
  identificacion: props.propietario.identificacion,
  telefono: props.propietario.telefono ?? '',
  celular: props.propietario.celular ?? '',
  correo: props.propietario.correo ?? '',
  direccion: props.propietario.direccion ?? '',
  observaciones: props.propietario.observaciones ?? ''
}))

const submit = (data: PropietarioFormData) => {
  router.put(route('propietarios.update', props.propietario.id), data, {
    onStart: () => { loading.value = true },
    onFinish: () => { loading.value = false }
  })
}
</script>

<template>
  <UDashboardPanel id="propietarios-edit">
    <template #header>
      <UDashboardNavbar title="Editar propietario"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar>
    </template>
    <template #body>
      <div class="mx-auto w-full max-w-4xl p-4 sm:p-6">
        <div class="mb-6">
          <p class="text-sm font-medium text-primary">{{ propietario.identificacion }}</p>
          <h1 class="mt-1 text-2xl font-semibold text-highlighted">{{ propietario.nombre }}</h1>
          <p class="mt-2 text-sm text-muted">Los cambios se reflejan en todas las propiedades vinculadas a esta identidad.</p>
        </div>
        <UCard>
          <PropietarioForm
            :initial="initial"
            :errors="errors"
            :loading="loading"
            submit-label="Actualizar propietario"
            @cancel="router.visit(route('propietarios.show', propietario.id))"
            @submit="submit"
          />
        </UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
