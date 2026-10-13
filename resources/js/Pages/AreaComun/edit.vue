<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import EspacioComunForm from '../../components/areas-comunes/EspacioComunForm.vue'
import type { EdificioAreaComunOption, EspacioComunDetalle, EspacioComunFormData, PisoAreaComunOption, TorreAreaComunOption } from '../../types'

const props = defineProps<{
  espacio: EspacioComunDetalle
  edificios: EdificioAreaComunOption[]
  torres: TorreAreaComunOption[]
  pisos: PisoAreaComunOption[]
}>()
const page = usePage()
const loading = ref(false)
const errors = computed<Record<string, string>>(() => Object.fromEntries(Object.entries(page.props.errors ?? {}).map(([field, error]) => [field, Array.isArray(error) ? String(error[0]) : String(error)])))
const initial = computed<EspacioComunFormData>(() => ({
  edificio_id: props.espacio.edificioId,
  codigo: props.espacio.codigo,
  nombre: props.espacio.nombre,
  descripcion: props.espacio.descripcion ?? '',
  capacidad: props.espacio.capacidad,
  torre_id: props.espacio.torreId ?? '',
  piso_id: props.espacio.pisoId ?? '',
  ubicacion_detalle: props.espacio.ubicacionDetalle ?? '',
  updated_at: props.espacio.updatedAt ?? ''
}))
const submit = (data: EspacioComunFormData) => router.put(route('areas-comunes.update', [props.espacio.edificioId, props.espacio.id]), {
  updated_at: data.updated_at,
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
  <UDashboardPanel id="areas-comunes-edit">
    <template #header><UDashboardNavbar :title="`Editar ${espacio.codigo}`"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar></template>
    <template #body>
      <div class="mx-auto w-full max-w-4xl p-4 sm:p-6">
        <div class="mb-6"><p class="text-sm font-medium text-primary">{{ espacio.edificio }}</p><h1 class="mt-1 text-2xl font-semibold text-highlighted">{{ espacio.nombre }}</h1><p class="mt-2 text-sm text-muted">Las reglas y reservas conservan sus versiones aunque cambie la información del espacio.</p></div>
        <UAlert v-if="errors.updatedAt" class="mb-5" color="error" :description="errors.updatedAt" icon="i-lucide-refresh-cw" variant="subtle" />
        <UCard><EspacioComunForm :edificios="edificios" :torres="torres" :pisos="pisos" :initial="initial" :current-location="espacio.ubicacion" :errors="errors" :loading="loading" lock-building submit-label="Actualizar espacio" @cancel="router.visit(route('areas-comunes.show', [espacio.edificioId, espacio.id]))" @submit="submit" /></UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
