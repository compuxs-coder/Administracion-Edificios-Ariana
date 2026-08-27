<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import ConceptoForm from '../../components/conceptos/ConceptoForm.vue'
import type { ConceptoCobroFormData } from '../../types'

const props = defineProps<{ edificios: Array<{ id: string, nombre: string }>, edificioSeleccionado: string | null }>()
const loading = ref(false)
const errors = ref<Record<string, string>>({})
const submit = (data: ConceptoCobroFormData) => router.post(route('conceptos.store', data.edificio_id), data, { onStart: () => { loading.value = true }, onError: responseErrors => { errors.value = responseErrors }, onFinish: () => { loading.value = false } })
</script>

<template>
  <UDashboardPanel id="conceptos-create"><template #header><UDashboardNavbar title="Nuevo concepto de cobro"><template #leading><UDashboardSidebarCollapse /></template></UDashboardNavbar></template><template #body><div class="mx-auto w-full max-w-3xl p-4 sm:p-6"><ConceptoForm require-edificio :edificios="props.edificios" :selected-edificio-id="props.edificioSeleccionado" :errors="errors" :loading="loading" submit-label="Crear concepto" @submit="submit" @cancel="router.visit(route('conceptos.index'))" /></div></template></UDashboardPanel>
</template>
