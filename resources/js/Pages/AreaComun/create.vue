<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import EspacioComunForm from '../../components/areas-comunes/EspacioComunForm.vue'
import type { EdificioAreaComunOption, EspacioComunFormData, PisoAreaComunOption, TorreAreaComunOption } from '../../types'

const props = defineProps<{
  edificios: EdificioAreaComunOption[]
  torres: TorreAreaComunOption[]
  pisos: PisoAreaComunOption[]
  edificioSeleccionado?: string | null
}>()
const page = usePage()
const loading = ref(false)
const errors = computed<Record<string, string>>(() => Object.fromEntries(Object.entries(page.props.errors ?? {}).map(([field, error]) => [field, Array.isArray(error) ? String(error[0]) : String(error)])))
const submit = (data: EspacioComunFormData) => router.post(route('areas-comunes.store', data.edificio_id), {
  codigo: data.codigo,
  nombre: data.nombre,
  descripcion: data.descripcion,
  capacidad: data.capacidad,
  torre_id: data.torre_id,
  piso_id: data.piso_id,
  ubicacion_detalle: data.ubicacion_detalle
}, {
  onStart: () => { loading.value = true },
  onFinish: () => { loading.value = false }
})
</script>

<template>
  <UDashboardPanel id="areas-comunes-create">
    <template #header><UDashboardNavbar title="Nueva área común"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar></template>
    <template #body>
      <div class="mx-auto w-full max-w-4xl p-4 sm:p-6">
        <div class="mb-6"><p class="text-sm font-medium text-primary">Áreas comunes</p><h1 class="mt-1 text-2xl font-semibold text-highlighted">Registrar espacio compartido</h1><p class="mt-2 text-sm text-muted">Después de crear el espacio podrá versionar sus reglas y horarios de uso.</p></div>
        <UCard><EspacioComunForm :edificios="edificios" :torres="torres" :pisos="pisos" :selected-edificio-id="edificioSeleccionado" :errors="errors" :loading="loading" @cancel="router.visit(route('areas-comunes.index'))" @submit="submit" /></UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
