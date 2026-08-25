<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import DepartamentoForm from '../../components/departamentos/DepartamentoForm.vue'
import type { Departamento, DepartamentoFormData, EdificioEstructuraOption } from '../../types'

const props = defineProps<{
  departamento: Departamento
  edificios: EdificioEstructuraOption[]
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
const initial = computed<DepartamentoFormData>(() => ({
  edificio_id: props.departamento.edificioId,
  piso_id: props.departamento.pisoId,
  codigo: props.departamento.codigo,
  nombre: props.departamento.nombre,
  alicuota: props.departamento.alicuota,
  observaciones: props.departamento.observaciones ?? '',
  parqueaderos: props.departamento.parqueaderos.map(item => item.id),
  bodegas: props.departamento.bodegas.map(item => item.id)
}))

const submit = (data: DepartamentoFormData) => {
  router.put(route('departamentos.update', [props.departamento.edificioId, props.departamento.id]), data, {
    onStart: () => { isLoading.value = true },
    onFinish: () => { isLoading.value = false }
  })
}
</script>

<template>
  <UDashboardPanel id="departamentos-edit">
    <template #header>
      <UDashboardNavbar title="Editar departamento">
        <template #leading><UDashboardSidebarCollapse /></template>
      </UDashboardNavbar>
    </template>
    <template #body>
      <div class="mx-auto w-full max-w-5xl p-4 sm:p-6">
        <div class="mb-6">
          <p class="text-sm font-medium text-primary">{{ departamento.edificio }} · {{ departamento.torre }}</p>
          <h1 class="mt-1 text-2xl font-semibold text-highlighted">{{ departamento.codigo }} · {{ departamento.nombre }}</h1>
          <p class="mt-2 text-sm text-muted">Los cambios de anexos conservan el historial de asignación.</p>
        </div>
        <UCard>
          <DepartamentoForm
            :edificios="edificios"
            :initial="initial"
            :initial-torre-id="departamento.torreId"
            :current-departamento-id="departamento.id"
            :current-estado="departamento.estado"
            :errors="errors"
            :loading="isLoading"
            lock-edificio
            submit-label="Actualizar departamento"
            @cancel="router.visit(route('departamentos.index'))"
            @submit="submit"
          />
        </UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
