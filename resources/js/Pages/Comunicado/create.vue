<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import ComunicadoForm from '../../components/comunicacion/ComunicadoForm.vue'
import type { ComunicadoFormData, EdificioComunicacionOption } from '../../types'

defineProps<{
  edificios: EdificioComunicacionOption[]
  edificioSeleccionado?: string | null
}>()
const page = usePage()
const loading = ref(false)
const errors = computed<Record<string, string>>(() => Object.fromEntries(Object.entries(page.props.errors ?? {}).map(([field, error]) => [field, Array.isArray(error) ? String(error[0]) : String(error)])))
const submit = (data: ComunicadoFormData) => router.post(route('comunicados.store', data.edificio_id), {
  titulo: data.titulo,
  cuerpo: data.cuerpo,
  audiencias: data.audiencias
}, {
  onStart: () => { loading.value = true },
  onFinish: () => { loading.value = false }
})
</script>

<template>
  <UDashboardPanel id="comunicados-create">
    <template #header><UDashboardNavbar title="Nuevo comunicado"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar></template>
    <template #body>
      <div class="mx-auto w-full max-w-4xl p-4 sm:p-6">
        <div class="mb-6"><p class="text-sm font-medium text-primary">Comunicación</p><h1 class="mt-1 text-2xl font-semibold text-highlighted">Preparar un comunicado</h1><p class="mt-2 text-sm text-muted">Primero guarde el borrador; después podrá adjuntar documentos, revisar la audiencia y publicar.</p></div>
        <UCard><ComunicadoForm :edificios="edificios" :selected-edificio-id="edificioSeleccionado" :errors="errors" :loading="loading" @cancel="router.visit(route('comunicados.index'))" @submit="submit" /></UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
