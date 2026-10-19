<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import ComunicadoForm from '../../components/comunicacion/ComunicadoForm.vue'
import type { ComunicadoDetalle, ComunicadoFormData, EdificioComunicacionOption } from '../../types'

const props = defineProps<{ comunicado: ComunicadoDetalle, edificios: EdificioComunicacionOption[] }>()
const page = usePage()
const loading = ref(false)
const errors = computed<Record<string, string>>(() => Object.fromEntries(Object.entries(page.props.errors ?? {}).map(([field, error]) => [field, Array.isArray(error) ? String(error[0]) : String(error)])))
const initial = computed<ComunicadoFormData>(() => ({
  edificio_id: props.comunicado.edificioId,
  titulo: props.comunicado.titulo,
  cuerpo: props.comunicado.cuerpo,
  audiencias: props.comunicado.audiencias,
  updated_at: props.comunicado.updatedAt ?? ''
}))
const submit = (data: ComunicadoFormData) => router.put(route('comunicados.update', [props.comunicado.edificioId, props.comunicado.id]), {
  updated_at: data.updated_at,
  titulo: data.titulo,
  cuerpo: data.cuerpo,
  audiencias: data.audiencias
}, {
  onStart: () => { loading.value = true },
  onFinish: () => { loading.value = false }
})
</script>

<template>
  <UDashboardPanel id="comunicados-edit">
    <template #header><UDashboardNavbar title="Editar borrador"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar></template>
    <template #body>
      <div class="mx-auto w-full max-w-4xl p-4 sm:p-6">
        <div class="mb-6"><p class="text-sm font-medium text-primary">{{ comunicado.edificio }}</p><h1 class="mt-1 text-2xl font-semibold text-highlighted">{{ comunicado.titulo }}</h1><p class="mt-2 text-sm text-muted">La versión evita sobrescribir cambios realizados desde otra sesión.</p></div>
        <UAlert v-if="errors.updatedAt" class="mb-5" color="error" :description="errors.updatedAt" icon="i-lucide-refresh-cw" variant="subtle" />
        <UCard><ComunicadoForm :edificios="edificios" :initial="initial" :errors="errors" :loading="loading" lock-building submit-label="Actualizar borrador" @cancel="router.visit(route('comunicados.show', [comunicado.edificioId, comunicado.id]))" @submit="submit" /></UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
