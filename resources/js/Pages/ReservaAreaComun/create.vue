<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import ReservaAreaComunForm from '../../components/areas-comunes/ReservaAreaComunForm.vue'
import type { EdificioAreaComunOption, EspacioComun, OcupacionAreaComunOption, ReservaAreaComunFormData } from '../../types'

const props = defineProps<{
  edificios: EdificioAreaComunOption[]
  espacios: EspacioComun[]
  ocupaciones: OcupacionAreaComunOption[]
  edificioSeleccionado?: string | null
  espacioSeleccionado?: string | null
}>()
const page = usePage()
const loading = ref(false)
const errors = computed<Record<string, string>>(() => Object.fromEntries(Object.entries(page.props.errors ?? {}).map(([field, error]) => [field, Array.isArray(error) ? String(error[0]) : String(error)])))
const submit = (data: ReservaAreaComunFormData) => router.post(route('reservas-areas-comunes.store', [data.edificio_id, data.espacio_comun_id]), {
  ocupacion_id: data.ocupacion_id,
  inicio: data.inicio,
  fin: data.fin,
  cantidad_asistentes: data.cantidad_asistentes,
  observaciones: data.observaciones
}, {
  onStart: () => { loading.value = true },
  onFinish: () => { loading.value = false }
})
</script>

<template>
  <UDashboardPanel id="reservas-areas-comunes-create">
    <template #header><UDashboardNavbar title="Nueva reserva"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar></template>
    <template #body>
      <div class="mx-auto w-full max-w-4xl p-4 sm:p-6">
        <div class="mb-6"><p class="text-sm font-medium text-primary">Áreas comunes</p><h1 class="mt-1 text-2xl font-semibold text-highlighted">Registrar solicitud de reserva</h1><p class="mt-2 text-sm text-muted">La regla vigente definirá si la reserva queda confirmada o pendiente de aprobación.</p></div>
        <UCard><ReservaAreaComunForm :edificios="edificios" :espacios="espacios" :ocupaciones="ocupaciones" :selected-edificio-id="edificioSeleccionado" :selected-espacio-id="espacioSeleccionado" :errors="errors" :loading="loading" @cancel="router.visit(route('reservas-areas-comunes.index'))" @submit="submit" /></UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
